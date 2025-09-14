<?php

namespace Tournament\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use Slim\Routing\RouteContext;
use Slim\Exception\HttpForbiddenException;

use Tournament\Repository\TournamentRepository;
use Tournament\Policy\TournamentPolicy;
use Tournament\Policy\TournamentAction;

/**
 * middleware to inject access to the current user into the request and twig.
 * This is a separate middleware next to AuthMiddleware because there may be pages
 * which can be accessed without authentication, but still benefit from having access
 * to the current user in case there is an authentication available.
 */
class TournamentStatusGuardMiddleware implements MiddlewareInterface
{
   public function __construct(private TournamentRepository $repo, private TournamentPolicy $policy)
   {
   }

   public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
   {
      $route = RouteContext::fromRequest($request)->getRoute();
      if (!$route)
      {
         throw new \InvalidArgumentException("Route could not be determined from request. Is the RoutingMiddleware registered?");
      }

      $tournamentId = (int)($route->getArgument('tournamentId') ?? 0);
      if ($tournamentId <= 0)
      {
         throw new \InvalidArgumentException("Route is missing tournament ID argument or it is not valid.");
      }

      /** @var TournamentAction|null $requiredAction */
      $requiredAction = $request->getAttribute('requiredAction');
      if (!$requiredAction instanceof TournamentAction)
      {
         throw new \InvalidArgumentException("Route is missing requiredAction argument or it is not of type TournamentAction");
      }

      $tournament = $this->repo->getTournamentById($tournamentId);
      if (!$tournament)
      {
         return $handler->handle($request); // let later middleware handle the not found case
      }

      if (!$this->policy->isActionAllowed($tournamentId, $requiredAction))
      {
         throw new HttpForbiddenException($request, "Aktion {$requiredAction->name} ist im Status {$tournament->status->value} nicht erlaubt.");
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
}
