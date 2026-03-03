<?php

namespace Tournament\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

use Slim\Routing\RouteContext;

use Tournament\Service\AuthContext;
use Base\Service\SessionService;
use Slim\Exception\HttpForbiddenException;

/**
 * Middleware to guard a route according authentication context
 * If authentication context is not sufficient:
 * - throw an error if authenticated, or
 * - forward to the login prompt if not authenticated at all.
 * AuthContext needs to be injected into the $request object already, with key 'auth_context'
 */
class AuthContextGuard
{
   public function __construct(
      private string $loginRoute,
      private ?SessionService $session = null,
   )
   {
   }

   public function process(ServerRequestInterface $request, RequestHandlerInterface $handler, callable $check, string $forbidden_msg): ResponseInterface
   {
      /** @var AuthContext */
      $auth_context = $request->getAttribute('auth_context');

      if( !$auth_context)
      {
         throw new \DomainException('AuthorizationContext could not be retrieved - is the AuthContextMiddleware installed?');
      }

      // if user is not authenticated and the route is not the login route or a free route, redirect to login
      if ($check($auth_context))
      {
         return $handler->handle($request);
      }
      else if ($auth_context->isAuthenticated())
      {
         // user is already logged in, but lacks the proper context for this route, throw
         throw new HttpForbiddenException($request, $forbidden_msg ?: 'insufficient access permissions for this page');
      }
      else
      {
         // Save the requested path to redirect after login
         $this->session?->set('redirect_after_login', $request->getUri()->getPath());

         // Redirect to login
         $response = new \Slim\Psr7\Response();
         return $response
            ->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()
            ->urlFor($this->loginRoute))
            ->withStatus(302);
      }
   }

   /**
    * Wrapper to inject the guard on a route.
    */
   public function check(callable $check, string $forbidden_msg = ''): \Closure
   {
      $self = $this;
      return fn (ServerRequestInterface $request, RequestHandlerInterface $handler)
               => $self->process($request, $handler, $check, $forbidden_msg);
   }

   /**
    * factory method to create the middleware with dependencies from the container
    */
   static public function create(
      \Slim\App $app,
      string $loginRoute,
      ?SessionService $session = null,

   ): self
   {
      $container = $app->getContainer();
      if (!isset($session) && $container->has(SessionService::class)) $session = $container->get(SessionService::class);
      return new self($loginRoute, $session);
   }
}
