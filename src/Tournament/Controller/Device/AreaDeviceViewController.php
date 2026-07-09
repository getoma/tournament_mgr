<?php declare(strict_types=1);

namespace Tournament\Controller\Device;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use Slim\Views\Twig;

use Tournament\Service\MatchHandlingService;
use Tournament\Service\TournamentStructureService;
use Tournament\Service\ChangeLogEvaluationService;

use Tournament\Service\RouteArgsContext;
use Tournament\Policy\AuthContext;

use Base\Service\PrgService;

class AreaDeviceViewController
{
   public function __construct(
      private TournamentStructureService $structureLoadService,
      private MatchHandlingService $matchService,
      private PrgService $prgService,
      private ChangeLogEvaluationService $chgLogService,
      private Twig $view,
   )
   {
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
      $structure = $request->getAttribute('tournament_structure') ?? $this->structureLoadService->load($ctx->category);

      // get change log for this area for pure KO categories
      $chgLog = $structure->pools->empty() ? $this->chgLogService->getChangesForKoTree($structure->ko, $auth->area) : null;

      return $this->view->render($response, 'device/categories_show.twig', [
         'pools'     => $structure->pools->filter(fn($p) => $p->getArea() === $auth->area),
         'ko_rounds' => $structure->getFinaleRounds()->filterRounds(fn($n) => $n->getArea() === $auth->area && $n->isReal()),
         'change_log' => $chgLog,
      ]);
   }

   /**
    * Show the overview of a single pool
    */
   public function showPool(Request $request, Response $response, array $args, $error = null): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      /** @var AuthContext $auth */
      $auth = $request->getAttribute('auth_context');

      /* select an active match from this pool */
      $matches = $ctx->pool->getMatchList();
      $selected = $request->getQueryParams()['selected'] ?? null;
      if( $selected === null || !$matches->findNode($selected) )
      {
         /* default to the first uncompleted match, or the very last match if all completed */
         $selected = $matches->filter(fn($n) => !$n->isCompleted())->first()?->getName() ?? $matches->last()?->getName();
      }

      /* provide the next pool for navigation */
      $structure = $ctx->category->getTournamentStructure();
      $area_pool_list = $structure->pools->filter(fn($p) => $p->getArea() === $auth->area)->values();
      $idx = array_search($ctx->pool, $area_pool_list);
      $next_pool = $area_pool_list[$idx+1] ?? null;

      /* get any changes since planning phase */
      $poolChgLog = $this->chgLogService->getChangesForPool($ctx->pool);

      return $this->view->render($response, 'device/pool_show.twig', [
         'pool'      => $ctx->pool,
         'next_pool' => $next_pool,
         'selected'  => $selected,
         'error'     => $error,
         'change_log' => $poolChgLog,
      ]);
   }

   /**
    * add a tie break match to a pool if needed, and redirect to the new match
    */
   public function addPoolTieBreak(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      if ($error = $this->matchService->addPoolTieBreak($ctx->pool))
      {
         return $this->showPool($request, $response, $args, $error);
      }

      return $this->prgService->redirect($request, $response, 'device.categories.pools.show', $ctx->args, 'tie_break_added');
   }

   /**
    * remove a pool tie break match again
    */
   public function deletePoolDecisionRound(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      if ($error = $this->matchService->deletePoolTieBreak($ctx->pool, (int)$args['decision_round']))
      {
         return $this->showPool($request, $response, $args, $error);
      }

      return $this->prgService->redirectBack($request, $response, 'tie_break_deleted');
   }

   public function showMatch(Request $request, Response $response, array $args, ?string $error = null): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      /** @var AuthContext $auth */
      $auth = $request->getAttribute('auth_context');

      /* get pointers to the previous and next "real" matches for our area */
      $structure = $ctx->category->getTournamentStructure();
      $matchList = $structure->getFinaleRounds()->filterRounds(fn($n) => $n->isReal() && $n->getArea() === $auth->area);
      $current_it = $matchList->getNodeIteratorAt($ctx->match->getName());

      return $this->view->render($response, 'device/match.twig', [
         'type'    => $ctx->pool? 'pool' : 'ko',
         'pool'    => $ctx->pool,
         'node'    => $ctx->match,
         'node_it' => $current_it,
         'error'   => $error,
      ]);
   }

   public function updateMatch(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      /* evaluate the match update data via our match service */
      if ($error = $this->matchService->updateMatchPoint($ctx->match, (array)$request->getParsedBody()))
      {
         return $this->showMatch($request, $response, $args, $error);
      }

      return $this->prgService->redirectBack($request, $response, 'match_updated');
   }
}
