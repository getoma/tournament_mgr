<?php

require_once '../vendor/autoload.php';
require_once '../config.php';

// create the app
$container = new DI\Container();
Slim\Factory\AppFactory::setContainer($container);
$app = Slim\Factory\AppFactory::create();

// set the base path for the application
if (!empty(config::$BASE_PATH))
{
   $app->setBasePath(config::$BASE_PATH);
}

// populate DI container
(require '../src/dependencies.php')($container);

// load middleware and routes
(require '../src/routes.php')($app);

// start the application
$app->run();
