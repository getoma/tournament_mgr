<?php declare(strict_types=1);

namespace Tournament\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use Slim\Exception\HttpForbiddenException;
use Tournament\Exception\EntityNotFoundException;

use Tournament\Policy\AuthContext;
use Tournament\Service\RouteArgsContext;
use Tournament\Service\TournamentStructureService;

/**
 * middleware to guard access for area device accounts - only grant access
 * if the entity (pool, matchnode) access via the current route is assigned
 * to the right area
 */
class AreaDeviceAccessGuard implements MiddlewareInterface
{
   public function __construct(
      private TournamentStructureService $structureLoadService
   )
   {
   }

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

      if( $ctx->match_name || $ctx->pool_name )
      {
         $structure = $this->structureLoadService->load($ctx->category);
         $entity = $ctx->match_name? $structure->findNode($ctx->match_name, $ctx->pool_name ?? false)
                 :                   $structure->pools[$ctx->pool_name];

         if( !$entity )
         {
            throw new EntityNotFoundException($request, 'target not found');
         }

         if ($entity->getArea() !== $auth->area)
         {
            throw new HttpForbiddenException($request, 'Zugriff nicht erlaubt');
         }

         $request = $request->withAttribute('tournament_structure', $structure);
      }

      return $handler->handle($request);
   }

   /**
    * factory method to create the middleware with dependencies from the container
    */
   public static function create(
      \Slim\App $app,
      ?TournamentStructureService $structureLoadService = null,
   ): self
   {
      $container = $app->getContainer();
      $structureLoadService ??= $container->get(TournamentStructureService::class);
      return new self($structureLoadService);
   }
}