<?php

namespace Tournament\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use Slim\Exception\HttpForbiddenException;
use Tournament\Policy\AuthType;
use Tournament\Policy\TournamentAction;
use Tournament\Policy\TournamentPolicy;

/**
 * Middleware to guard a route by the current policy.
 * To be added route-specific via the for() method that defines the action this route needs
 * to be guarded against.
 */
class TournamentPolicyGuardMiddleware
{
   /**
    * Wrapper to inject a guard for a specific action on a route.
    */
   public static function for(TournamentAction $action): \Closure
   {
      return function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($action): ResponseInterface
      {
         /** @var TournamentPolicy $policy */
         $policy = $request->getAttribute('policy');
         if (!$policy->isActionAllowed($action))
         {
            throw new HttpForbiddenException($request, "Aktion {$action->name} ist nicht erlaubt.");
         }
         return $handler->handle($request);
      };
   }

   /**
    * Wrapper to inject guard for a specific authorization type on a route
    */
   public static function as(AuthType $auth)
   {
      return function (ServerRequestInterface $request, RequestHandlerInterface $handler) use ($auth): ResponseInterface
      {
         /** @var TournamentPolicy $policy */
         $policy = $request->getAttribute('policy');
         if (!$policy->hasAccessAs($auth))
         {
            throw new HttpForbiddenException($request, "Zugriff mit aktueller Authorisierung nicht erlaubt");
         }
         return $handler->handle($request);
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
