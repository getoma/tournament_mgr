<?php

namespace Tournament\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use Slim\Exception\HttpForbiddenException;

use Tournament\Policy\TournamentAction;
use Tournament\Policy\TournamentPolicy;

/**
 * Middleware to guard a route by the current policy.
 * To be added route-specific via the for() method that defines the action this route needs
 * to be guarded against.
 */
class TournamentPolicyGuardMiddleware implements MiddlewareInterface
{
   public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
   {
      /** @var TournamentAction $requiredAction */
      $requiredAction = $request->getAttribute('requiredAction');
      if (!($requiredAction instanceof TournamentAction) )
      {
         throw new \DomainException("Route is missing requiredAction argument or it is not of type TournamentAction");
      }

      /** @var TournamentPolicy $policy */
      $policy = $request->getAttribute('policy');
      if (!$policy->isActionAllowed($requiredAction))
      {
         throw new HttpForbiddenException($request, "Aktion {$requiredAction->name} ist nicht erlaubt.");
      }

      return $handler->handle($request);
   }

   /**
    * Wrapper to inject the specific action for each route.
    */
   public function for(TournamentAction $action): \Closure
   {
      $mw = $this;
      return function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($action, $mw)
      {
         return $mw->process($request->withAttribute('requiredAction', $action), $handler);
      };
   }

   /**
    * factory method to create the middleware with dependencies from the container
    */
   static public function create(
      \Slim\App $app,
   ): self
   {
      return new self();
   }
}
