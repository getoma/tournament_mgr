<?php

namespace App\Middleware;

use App\Service\AuthService;
use App\Service\SessionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Routing\RouteContext;
use Slim\Exception\HttpNotFoundException;

/**
 * Perform user authentication and check if user may access the requested page
 * If no authentication available, forward to login prompt.
 */
class AuthMiddleware implements MiddlewareInterface
{
   public function __construct(private AuthService $authService, private SessionService $session,
                               private string $loginRoute, private array $freeRoutes = [])
   {
   }

   public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
   {
      $routeContext = RouteContext::fromRequest($request);
      $route = $routeContext->getRoute();

      // return NotFound for non-existent route
      if (empty($route))
      {
         throw new HttpNotFoundException($request);
      }

      // if user is not authenticated and the route is not the login route or a free route, redirect to login
      if (!$this->authService->isAuthenticated() && ($route->getName() != $this->loginRoute) && !in_array($route->getName(), $this->freeRoutes))
      {
         // Save the requested path to redirect after login
         $this->session->set('redirect_after_login', $request->getUri()->getPath());

         // Redirect to login
         $routeParser = $routeContext->getRouteParser();
         $response = new \Slim\Psr7\Response();
         return $response
            ->withHeader('Location', $routeParser->urlFor($this->loginRoute))
            ->withStatus(302);
      }
      else
      {
         return $handler->handle($request);
      }
   }
}
