<?php

namespace Tournament\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use Slim\Views\Twig;
use Slim\Routing\RouteContext;
use Tournament\Service\RouteArgsResolverService;

/**
 * Middleware to create the RouteArgsContext on the current route.
 * Needs to be added after the processing of the routing middleware itself.
 */
class RouteArgsResolverMiddleware implements MiddlewareInterface
{
   public function __construct(
      private RouteArgsResolverService $resolver,
      private ?Twig $twig = null
   )
   {
   }

   public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
   {
      $routeContext = $this->resolver->resolve($request);
      $request = $request->withAttribute('route_context', $routeContext);
      $this->twig?->getEnvironment()->addGlobal('route_context', $routeContext);
      return $handler->handle($request);
   }

   /**
    * factory method to create the middleware with dependencies from the container
    */
   static public function create(
      \Slim\App $app,
      ?RouteArgsResolverService $resolver = null,
      ?Twig $twig = null,
   ): self
   {
      $container  = $app->getContainer();
      $resolver ??= $container->get(RouteArgsResolverService::class);
      if (!isset($twig) && $container->has(Twig::class)) $twig = $container->get(Twig::class);
      return new self($resolver, $twig);
   }
}