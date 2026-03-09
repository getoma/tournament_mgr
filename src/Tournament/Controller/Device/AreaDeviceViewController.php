<?php

namespace Tournament\Controller\Device;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use Slim\Views\Twig;

use Tournament\Model\TournamentStructure\TournamentStructure;
use Tournament\Model\TournamentStructure\MatchNode\MatchRoundCollection;
use Tournament\Model\TournamentStructure\MatchNode\MatchNode;
use Tournament\Model\TournamentStructure\Pool\Pool;

use Tournament\Service\MatchHandlingService;
use Tournament\Service\TournamentStructureService;

use Tournament\Service\RouteArgsContext;
use Tournament\Policy\AuthContext;

use Base\Service\PrgService;

use Tournament\Exception\EntityNotFoundException;
use Slim\Exception\HttpForbiddenException;

class AreaDeviceViewController
{
   public function __construct(
      private TournamentStructureService $structureLoadService,
      private MatchHandlingService $matchService,
      private PrgService $prgService,
      private Twig $view,
   )
   {
   }

   /**
    * device access is limited to a specific area.
    * But for knowing whether a specific entity is mapped to a specific area,
    * we need to load the whole structure and match record list, which we do not
    * want to do on policy level. Therefore the area access is checked here on Controller level
    */
   private function guardAccess(Request $request, Pool|MatchNode $entity, AuthContext $auth): void
   {
      if ($entity->getArea() !== $auth->area)
      {
         throw new HttpForbiddenException($request, 'Zugriff nicht erlaubt');
      }
   }

   /**
    * Show all categories
    */
   public function showCategories(Request $request, Response $response): Response
   {
      /** @var AuthContext $auth */
      $auth = $request->getAttribute('auth_context');

      return $this->view->render($response, 'device/categories_index.twig', [
         'categories' => $auth->tournament->categories
      ]);
   }

   /**
    * Show a specific category home
    */
   public function showCategoryHome(Request $request, Response $response): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      /** @var AuthContext $auth */
      $auth = $request->getAttribute('auth_context');

      // Load the tournament structure for this category
      $structure = $this->structureLoadService->load($ctx->category);

      return $this->view->render($response, 'device/categories_show.twig', [
         'pools' => $structure->pools->filter(fn($p) => $p->getArea() === $auth->area),
         'ko'    => $structure->getFinaleRounds()->filterRounds(fn($n) => $n->getArea() === $auth->area),
      ]);
   }

   /**
    * Show the overview of a single pool
    */
   public function showPool(Request $request, Response $response, array $args, ?TournamentStructure $structure = null, $error = null): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      /** @var AuthContext $auth */
      $auth = $request->getAttribute('auth_context');

      $structure ??= $this->structureLoadService->load($ctx->category);
      $pool = $structure->pools[$args['pool']] ?? throw new EntityNotFoundException($request, 'Pool not found');

      /** @var Pool $pool */
      $this->guardAccess($request, $pool, $auth);

      /* select an active match from this pool */
      $matches = $pool->getMatchList();
      $selected = $request->getQueryParams()['selected'] ?? null;
      if( $selected === null || !$matches->findNode($selected) )
      {
         /* default to the first uncompleted match, or the very last match if all completed */
         $selected = $matches->filter(fn($n) => !$n->isCompleted())->first()?->getName() ?? $matches->last()?->getName();
      }

      /* provide the next pool for navigation */
      $area_pool_list = $structure->pools->filter(fn($p) => $p->getArea() === $auth->area)->values();
      $idx = array_search($pool, $area_pool_list);
      $next_pool = $area_pool_list[$idx+1] ?? null;

      return $this->view->render($response, 'device/pool_show.twig', [
         'pool'      => $pool,
         'next_pool' => $next_pool,
         'selected'  => $selected,
         'error'     => $error,
      ]);
   }

   /**
    * add a tie break match to a pool if needed, and redirect to the new match
    */
   public function addPoolTieBreak(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      /** @var AuthContext $auth */
      $auth = $request->getAttribute('auth_context');

      $structure = $this->structureLoadService->load($ctx->category);
      $pool = $structure->pools[$args['pool']] ?? throw new EntityNotFoundException($request, 'Pool not found');

      $this->guardAccess($request, $pool, $auth);

      $error = $this->matchService->addPoolTieBreak($pool);

      if ($error)
      {
         return $this->showPool($request, $response, $args, $structure, $error);
      }
      else
      {
         return $this->prgService->redirect($request, $response, 'device.categories.pools.show', $ctx->args, 'tie_break_added');
      }
   }

   /**
    * remove a pool tie break match again
    */
   public function deletePoolDecisionRound(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      /** @var AuthContext $auth */
      $auth = $request->getAttribute('auth_context');

      $structure = $this->structureLoadService->load($ctx->category);
      $pool = $structure->pools[$args['pool']] ?? throw new EntityNotFoundException($request, 'Pool not found');

      $this->guardAccess($request, $pool, $auth);

      $error = $this->matchService->deletePoolTieBreak($pool, $args['decision_round']);
      /* forward to output page */
      if ($error)
      {
         return $this->showPool($request, $response, $args, $structure, $error);
      }
      else
      {
         return $this->prgService->redirectBack($request, $response, 'tie_break_deleted');
      }
   }

   public function showMatch(Request $request, Response $response, array $args, ?TournamentStructure $structure = null, $error = null): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      /** @var AuthContext $auth */
      $auth = $request->getAttribute('auth_context');

      /* load the structure and find the current node/match */
      $structure ??= $this->structureLoadService->load($ctx->category);
      $node = $structure->findNode($ctx->match_name, $ctx->pool_name ?? false) ?? new EntityNotFoundException($request, "unknown Match '{$ctx->match_name}'");

      $this->guardAccess($request, $node, $auth);

      /* get pointers to the previous and next "real" matches for our area */
      $matchList = $structure->getFinaleRounds()->filterRounds(fn($n) => $n->isReal() && $n->area === $auth->area);
      /** @var MatchRoundCollection $matchList */
      $current_it = $matchList->getNodeIteratorAt($ctx->match_name);

      return $this->view->render($response, 'device/match.twig', [
         'type'    => isset($args['pool']) ? 'pool' : 'ko',
         'pool'    => $args['pool'] ?? null,
         'node'    => $node,
         'node_it' => $current_it,
         'error'   => $error,
      ]);
   }

   public function updateMatch(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      /** @var AuthContext $auth */
      $auth = $request->getAttribute('auth_context');

      /* load the structure and find the current node/match */
      $structure = $this->structureLoadService->load($ctx->category);
      $node = $structure->findNode($ctx->match_name, $ctx->pool_name ?? false) ?? new EntityNotFoundException($request, "unknown Match '{$ctx->match_name}'");

      $this->guardAccess($request, $node, $auth);

      /* evaluate the match update data via our match service */
      $error = $this->matchService->updateMatchPoint($node, (array)$request->getParsedBody());

      if ($error)
      {
         return $this->showMatch($request, $response, $args, $structure, $error);
      }
      else
      {
         return $this->prgService->redirectBack($request, $response, 'match_updated');
      }
   }
}
