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
 * middleware to inject access to the current user into the request and twig.
 * This is a separate middleware next to AuthMiddleware because there may be pages
 * which can be accessed without authentication, but still benefit from having access
 * to the current user in case there is an authentication available.
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
      $routeArgs    = RouteContext::fromRequest($request)->getRoute()->getArguments();
      $routeContext = $this->resolver->resolve($routeArgs);
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