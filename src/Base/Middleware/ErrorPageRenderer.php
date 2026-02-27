<?php

namespace Base\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Slim\Interfaces\ErrorRendererInterface;
use Slim\Views\Twig;

class ErrorPageRenderer implements ErrorRendererInterface
{
   public function __construct(
      private string $error_page_template_name,
      private Twig $twig,
      private ResponseFactoryInterface $responseFactory
   )
   {
   }

   public function __invoke(\Throwable $exception, bool $displayErrorDetails): string
   {
      $code = $exception->getCode() ?: 500;

      $response = $this->responseFactory->createResponse($code);

      return $this->twig->render($response, $this->error_page_template_name, [
         'message'   => $exception->getMessage(),
         'code'      => $code,
         'exception' => $exception
      ])->getBody();
   }

   public static function create(
      \Slim\App $app,
      string $error_page_template_name,
      ?Twig $twig = null,
      ?ResponseFactoryInterface $responseFactory = null,
   ): self
   {
      $container = $app->getContainer();
      $twig ??= $container->get(Twig::class);
      $responseFactory ??= $app->getResponseFactory();
      return new self($error_page_template_name, $twig, $responseFactory);
   }
}