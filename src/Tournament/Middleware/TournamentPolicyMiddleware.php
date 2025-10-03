<?php

namespace Tournament\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Views\Twig;
use Slim\Routing\RouteContext;

use Tournament\Policy\CurrentTournamentPolicy;
use Tournament\Policy\TournamentPolicy;

/**
 * middleware to inject a policy handler for the current tournament into the request.
 * This allows route handlers and templates to check permissions for the currently selected tournament
 */
class TournamentPolicyMiddleware implements MiddlewareInterface
{
   public function __construct(private TournamentPolicy $tournamentPolicy, private Twig $twig)
   {
   }

   public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
   {
      // acquire the current route
      $route = RouteContext::fromRequest($request)->getRoute();
      if (!$route)
      {
         throw new \DomainException("Route could not be determined from request. Is the RoutingMiddleware registered?");
      }

      // extract the tournament from the route and spawn the policy handler for it
      $policy = new CurrentTournamentPolicy($request->getAttribute('tournament', null), $this->tournamentPolicy);

      // inject the policy handler
      $this->twig->getEnvironment()->addGlobal('policy', $policy);
      $request = $request->withAttribute('policy', $policy);

      // done
      return $handler->handle($request);
   }

   /**
    * factory method to create the middleware with dependencies from the container
    */
   static public function create(
      \Slim\App $app,
      ?\Tournament\Policy\TournamentPolicy $policy = null,
      ?\Slim\Views\Twig $twig = null
   ): self
   {
      $container = $app->getContainer();
      if( !isset($policy) ) $policy = $container->get(\Tournament\Policy\TournamentPolicy::class);
      if( !isset($twig) )   $twig = $container->get(\Slim\Views\Twig::class);

      return new self($policy, $twig);
   }
}
