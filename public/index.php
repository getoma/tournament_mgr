<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config.php';

use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Views\Twig;
use Twig\Loader\FilesystemLoader;

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
$container->set(PDO::class, function ()
{
   $dsn = sprintf('mysql:dbname=%s;host=%s;charset=utf8', config::$DB_CONNECTION['db'], config::$DB_CONNECTION['server']);
   $options = [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
   ];
   return new PDO($dsn, config::$DB_CONNECTION['user'], config::$DB_CONNECTION['pw'], $options);
});

// configure Twig
$container->set(Twig::class, function () use ($app)
{
   $loader = new FilesystemLoader(__DIR__ . '/../templates');
   $twig = new Twig($loader, ['debug' => config::$debug]);
   $twig->getEnvironment()->addGlobal('base_path', $app->getBasePath());
   if (config::$debug)
   {
      $twig->addExtension(new \Twig\Extension\DebugExtension());
   }
   return $twig;
});

// Add Error Handling Middleware
$app->addErrorMiddleware(true, false, false);

// load routes
foreach ((require '../routes.php') as $name => $route)
{
   $app->map([$route[0]], $route[1], $route[2])->setName($name);
}

// start the application
$app->run();
