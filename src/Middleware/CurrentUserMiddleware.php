<?php

namespace App\Middleware;

use App\Service\AuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Views\Twig;

/**
 * middleware to inject access to the current user into the request and twig.
 * This is a separate middleware next to AuthMiddleware because there may be pages
 * which can be accessed without authentication, but still benefit from having access
 * to the current user in case there is an authentication available.
 */
class CurrentUserMiddleware implements MiddlewareInterface
{
   public function __construct(private AuthService $authService, private Twig $twig)
   {
   }

   public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
   {
      if ($this->authService->isAuthenticated())
      {
         $currentUser = $this->authService->getCurrentUser();
         $this->twig->getEnvironment()->addGlobal('current_user', $currentUser);
         $request = $request->withAttribute('current_user', $currentUser);
      }
      return $handler->handle($request);
   }
}
