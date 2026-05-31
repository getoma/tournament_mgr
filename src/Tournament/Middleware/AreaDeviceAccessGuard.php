<?php declare(strict_types=1);

namespace Tournament\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use Slim\Exception\HttpForbiddenException;

use Tournament\Policy\AuthContext;
use Tournament\Service\RouteArgsContext;

/**
 * middleware to guard access for area device accounts - only grant access
 * if the target entity of the current route (pool, matchnode) is assigned
 * to the right area
 */
class AreaDeviceAccessGuard implements MiddlewareInterface
{
   /**
    * guard processing
    */
   public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      /** @var AuthContext $auth */
      $auth = $request->getAttribute('auth_context');

      if( !$auth->area )
      {
         throw new \LogicException("Area Device guard entered for user without area restriction");
      }

      if( $entity = $ctx->match ?? $ctx->pool )
      {
         if ($entity->getArea() !== $auth->area)
         {
            throw new HttpForbiddenException($request, 'Zugriff nicht erlaubt');
         }
      }

      return $handler->handle($request);
   }

   /**
    * factory method to create the middleware with dependencies from the container
    */
   public static function create(
      \Slim\App $app,
   ): self
   {
      return new self();
   }
}