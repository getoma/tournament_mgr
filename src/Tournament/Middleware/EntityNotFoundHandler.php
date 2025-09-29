<?php

namespace Tournament\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Log\LoggerInterface;
use Slim\Interfaces\ErrorHandlerInterface;
use Slim\Views\Twig;

class EntityNotFoundHandler implements ErrorHandlerInterface
{
   public function __construct(
      private Twig $view,
      private ResponseFactoryInterface $responseFactory,
      private ?LoggerInterface $logger = null
   )
   {
   }

   public function __invoke(
      Request $request,
      \Throwable $exception,
      bool $displayErrorDetails,
      bool $logErrors,
      bool $logErrorDetails
   ): Response
   {
      if($this->logger && $logErrors)
      {
         $this->logger->warning('Entity not found: ' . $exception->getMessage());
      }

      $response = $this->responseFactory->createResponse(404);
      return $this->view->render($response, 'info_pages/entity_not_found.twig', [
         'message' => $exception->getMessage(),
      ]);
   }

   public static function create(
      \Slim\App $app,
      ?Twig $view = null,
      ?ResponseFactoryInterface $responseFactory = null,
      ?LoggerInterface $logger = null
   ): self
   {
      $container = $app->getContainer();
      if (!isset($view)) $view = $container->get(Twig::class);
      if (!isset($responseFactory)) $responseFactory = $app->getResponseFactory();
      if (!isset($logger) && $container->has(LoggerInterface::class)) $logger = $container->get(LoggerInterface::class);
      return new self($view, $responseFactory, $logger);
   }
}
