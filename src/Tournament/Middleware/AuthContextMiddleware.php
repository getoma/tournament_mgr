<?php

namespace Tournament\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use Slim\Views\Twig;

use Tournament\Service\AuthContextService;

/**
 * middleware to inject the current authorization context into the request and twig.
 * The authorization context grants access to the currently logged in user, and allows
 * some easy queries on user roles and type.
 */
class AuthContextMiddleware implements MiddlewareInterface
{
   public function __construct(private AuthContextService $ctxService, private Twig $twig)
   {
   }

   public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
   {
      $ctx = $this->ctxService->createContext($request);
      $this->twig->getEnvironment()->addGlobal('auth_context', $ctx);
      $request = $request->withAttribute('auth_context', $ctx);
      return $handler->handle($request);
   }

   public static function create(
      \Slim\App $app,
      ?AuthContextService $ctxService = null,
      ?Twig $twig = null
   ): self
   {
      $container = $app->getContainer();
      if (!isset($ctxService)) $ctxService = $container->get(AuthContextService::class);
      if (!isset($twig)) $twig = $container->get(Twig::class);
      return new self($ctxService, $twig);
   }
}
