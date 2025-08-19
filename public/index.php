<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

// initialize PDO, SessionHandler, and start the session
$pdo = new PDO(
   sprintf('mysql:dbname=%s;host=%s;charset=utf8', config::$DB_CONNECTION['db'], config::$DB_CONNECTION['server']),
   config::$DB_CONNECTION['user'],
   config::$DB_CONNECTION['pw'],
   [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
   ]
);
session_set_save_handler(new App\Service\SessionHandler($pdo));
session_start();

// create the app
$container = new Container();
AppFactory::setContainer($container);
$app = AppFactory::create();

// set the base path for the application
if( !empty(config::$BASE_PATH) )
{
   $app->setBasePath(config::$BASE_PATH);
}

// configure database connection
$container->set(PDO::class, function () use ($pdo) {
   return $pdo;
});

// configure Twig
$container->set(Twig::class, function () use ($app)
{
   $twig = Twig::create(__DIR__ . '/../templates', [
      'cache' => false,
      'debug' => config::$debug,
   ]);
   if (config::$debug)
   {
      $twig->addExtension(new \Twig\Extension\DebugExtension());
   }

   $twig->getEnvironment()->addGlobal('base_path', $app->getBasePath());

   return $twig;
});

// configure MailService
$container->set(App\Service\MailService::class, function () {
   return new App\Service\MailService(
      fromAddress: config::$MAIL_FROM_ADDRESS,
      fromName: config::$MAIL_FROM_NAME,
      smtpSettings: config::$SMTP_SETTINGS
   );
});

// twig middleware to support various extensions in templates
$app->add(TwigMiddleware::create($app, $container->get(Twig::class)));

// initialize AuthService
$authService = $container->get(App\Service\AuthService::class);

// Add CurrentUserMiddleware
$app->add(new App\Middleware\CurrentUserMiddleware($authService, $container->get(Twig::class)));

// Add AuthMiddleware
$app->add(new App\Middleware\AuthMiddleware($authService, 'login', ['home', 'login', 'login_post', 'pw_forgot', 'pw_forgot_post', 'pw_reset', 'pw_reset_post']));

$app->addRoutingMiddleware();

// Add Error Handling Middleware
$app->addErrorMiddleware(true, false, false);

// load routes
foreach ((require '../routes.php') as $name => $route)
{
   $app->map([$route[0]], $route[1], $route[2])->setName($name);
}

// start the application
$app->run();
