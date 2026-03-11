<?php

namespace Tournament\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use Tournament\Service\ActivityTrackingService;

/**
 * Middleware to create the RouteArgsContext on the current route.
 * Needs to be added after the processing of the routing middleware itself.
 */
class ActivityTrackingMiddleware implements MiddlewareInterface
{
   public function __construct(
      private ActivityTrackingService $service,
   )
   {
   }

   public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
   {
      // acquire the authorization context
      $auth_context = $request->getAttribute('auth_context');
      if (!$auth_context)
      {
         throw new \BadMethodCallException('authorization context could not be acquired - is the AuthContextMiddleware registered?');
      }

      $this->service->updateActivity($auth_context);

      return $handler->handle($request);
   }

   /**
    * factory method to create the middleware with dependencies from the container
    */
   static public function create(
      \Slim\App $app,
      ?ActivityTrackingService $service = null,
   ): self
   {
      $container  = $app->getContainer();
      $service ??= $container->get(ActivityTrackingService::class);
      return new self($service);
   }
}
