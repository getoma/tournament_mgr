<?php

use Slim\Views\Twig;

return function (DI\Container $container)
{
   // configure database connection
   $container->set(PDO::class, function ()
   {
      return new PDO(
         sprintf('mysql:dbname=%s;host=%s;charset=utf8', config::$DB_CONNECTION['db'], config::$DB_CONNECTION['server']),
         config::$DB_CONNECTION['user'],
         config::$DB_CONNECTION['pw'],
         [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
         ]
      );
   });

   // configure Twig
   $container->set(Twig::class, function () use ($container)
   {
      $twig = Twig::create(__DIR__ . '/../templates', [
         'cache' => false,
         'debug' => config::$debug ?? false,
      ]);
      if (config::$debug ?? false)
      {
         $twig->addExtension(new \Twig\Extension\DebugExtension());
         $twig->addExtension(new \Tournament\Twig\DebugExtension());
      }

      $twig->addExtension(new \Tournament\Twig\TwigExtensions());
      $twig->addExtension(new \Tournament\Twig\NavigationExtension(
         $container->get(\Tournament\Service\NavigationStructureService::class))
      );

      $twig->getEnvironment()->addGlobal('debug', config::$debug ?? false);
      $twig->getEnvironment()->addGlobal('test_interfaces', config::$test_interfaces ?? false);

      return $twig;
   });

   // configure MailService
   $container->set(Base\Service\MailService::class, function ()
   {
      return new Base\Service\MailService(
         fromAddress: config::$MAIL_FROM_ADDRESS ?? '',
         fromName: config::$MAIL_FROM_NAME ?? 'Tournament Software',
         smtpSettings: config::$SMTP_SETTINGS ?? []
      );
   });

   // configure SessionHandler
   $container->set(SessionHandlerInterface::class, function () use ($container)
   {
      return new Base\Service\PdoSessionHandler($container->get(PDO::class));
   });

   // configure SessionService
   $container->set(Base\Service\SessionService::class, function () use ($container)
   {
      return new Base\Service\SessionService($container->get(SessionHandlerInterface::class));
   });

   // override UserRepository with Tournament specific one
   $container->set(Base\Repository\UserRepository::class, function () use ($container)
   {
      return $container->get(\Tournament\Repository\UserRepository::class);
   });

   // configure DbUpdateService
   $container->set(\Base\Service\DbUpdateService::class, function ()
   {
      return new \Base\Service\DbUpdateService(config::$DB_CONNECTION, __DIR__ . '/../db');
   });
};
