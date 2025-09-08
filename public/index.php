<?php

require_once '../vendor/autoload.php';
require_once '../config.php';

// create the app
$container = new DI\Container();
Slim\Factory\AppFactory::setContainer($container);
$app = Slim\Factory\AppFactory::create();

// set the base path for the application
if( !empty(config::$BASE_PATH) )
{
   $app->setBasePath(config::$BASE_PATH);
}

// load DI container definitions
require_once '../src/dependencies.php';

// Add CurrentUserMiddleware
$app->add(new Base\Middleware\CurrentUserMiddleware($authService, $container->get(Slim\Views\Twig::class)));

// Add AuthMiddleware
$app->add(new Base\Middleware\AuthMiddleware($authService, $container->get(Base\Service\SessionService::class), 'login',
                                            ['home', 'login', 'login_post', 'pw_forgot', 'pw_forgot_post', 'pw_reset', 'pw_reset_post']));

$app->addRoutingMiddleware();

// Add Error Handling Middleware
$app->addErrorMiddleware(true, false, false);

// load routes
foreach ((require '../src/routes.php') as $name => $route)
{
   $app->map([$route[0]], $route[1], $route[2])->setName($name);
}

// start the application
$app->run();
