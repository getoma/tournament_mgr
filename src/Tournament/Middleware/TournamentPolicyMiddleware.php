<?php

namespace Tournament\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use Slim\Views\Twig;

use Tournament\Policy\TournamentPolicy;

/**
 * middleware to inject a policy handler for the current tournament into the request.
 * This allows route handlers and templates to check permissions for the currently selected tournament
 */
class TournamentPolicyMiddleware implements MiddlewareInterface
{
   public function __construct(private Twig $twig)
   {
   }

   public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
   {
      // acquire the authorization context
      $auth_context = $request->getAttribute('auth_context');
      if (!$auth_context)
      {
         throw new \DomainException('authorization context could not be acquired - is the AuthContextMiddleware registered?');
      }

      // acquire RouteArgsContext
      $route_context = $request->getAttribute('route_context');
      if (!$route_context)
      {
         throw new \DomainException('route context could not be acquired - is the RouteArgsResolverMiddleware registered?');
      }

      // spawn the policy handler
      $policy = new TournamentPolicy($auth_context, $route_context);

      // inject the policy handler
      $this->twig?->getEnvironment()->addGlobal('policy', $policy);
      $request = $request->withAttribute('policy', $policy);

      // done
      return $handler->handle($request);
   }

   /**
    * factory method to create the middleware with dependencies from the container
    */
   static public function create(
      \Slim\App $app,
      ?\Slim\Views\Twig $twig = null
   ): self
   {
      $container = $app->getContainer();
      if (!isset($twig) && $container->has(Twig::class)) $twig = $container->get(Twig::class);
      return new self($twig);
   }
}
