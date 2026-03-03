<?php

namespace Base\Service;

class SessionValidationIssue extends \RuntimeException
{
   public function __construct(string $message = "", public bool $critical = true)
   {
      parent::__construct($message, 403);
   }

   /**
    * get a Slim error handler that will display a customized error page
    * via twig
    */
   static public function getSlimErrorHandler(\Slim\App $app, string $template_name)
   {
      return function () use ($app, $template_name)
      {
         $twig = $app->getContainer()->get(\Slim\Views\Twig::class);
         $response = $app->getResponseFactory()->createResponse(403);
         return $twig->render($response, $template_name);
      };
   }
}
