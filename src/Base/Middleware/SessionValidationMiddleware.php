<?php

namespace Base\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use Base\Service\SessionService;

/**
 * perform session validation via dedicated service
 */
class SessionValidationMiddleware implements MiddlewareInterface
{
   public function __construct(
      private SessionService $service,
   )
   {
   }

   public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
   {
      $this->service->validateSession();
      return $handler->handle($request);
   }

   /**
    * factory method to create the middleware with dependencies from the container
    */
   static public function create(
      \Slim\App $app,
      ?SessionService $service = null,
   ): self
   {
      $container = $app->getContainer();
      $service ??= $container->get(\Base\Service\SessionService::class);
      return new self($service);
   }
}