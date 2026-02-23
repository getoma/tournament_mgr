<?php

namespace Tournament\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use Slim\Views\Twig;

/**
 * middleware to inject a dedicated navigation key to identify the position
 * in the navigation structure for a route.
 */
class NavigationKeyMiddleware implements MiddlewareInterface
{
   public function __construct(
      private ?Twig $twig = null
   )
   {
   }

   public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
   {
      $this->twig?->getEnvironment()->addGlobal('navigation_key', $request->getAttribute('navigation_key'));
      return $handler->handle($request);
   }

   /**
    * Wrapper to inject the specific action for each route.
    */
   public function navkey(string $key): \Closure
   {
      $mw = $this;
      return function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($key, $mw)
      {
         return $mw->process($request->withAttribute('navigation_key', $key), $handler);
      };
   }

   /**
    * factory method to create the middleware with dependencies from the container
    */
   static public function create(
      \Slim\App $app,
      ?Twig $twig = null,
   ): self
   {
      $container  = $app->getContainer();
      if (!isset($twig) && $container->has(Twig::class)) $twig = $container->get(Twig::class);
      return new self($twig);
   }
}