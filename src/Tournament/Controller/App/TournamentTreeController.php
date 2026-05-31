<?php declare(strict_types=1);

namespace Tournament\Controller\App;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use Slim\Views\Twig;

use Tournament\Service\RouteArgsContext;
use Tournament\Service\MatchHandlingService;
use Tournament\Service\TournamentStructureService;

use Base\Service\PrgService;
use Base\Service\DataValidationService;

use Respect\Validation\Validator as v;

class TournamentTreeController
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
    * Show a the pools of a specific category
    */
   public function showCategoryPools(Request $request, Response $response): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $structure = $ctx->category->getTournamentStructure();

      return $this->view->render($response, 'tournament/navigation/category_Pool.twig', [
         'pools' => $structure->pools,
         'unmapped_participants' => $structure->unmapped_participants,
      ]);
   }

   /**
    * Show a specific category KO
    */
   public function showCategorytree(Request $request, Response $response): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $structure = $ctx->category->getTournamentStructure();

      return $this->view->render($response, 'tournament/navigation/category_KO.twig', [
         'no_pools'   => $structure->pools->empty(),
         'ko_rounds'  => $structure->getFinaleRounds(),
         'unmapped_participants' => $structure->unmapped_participants,
      ]);
   }

   /**
    * Show a specific category home
    */
   public function showCategoryHome(Request $request, Response $response): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $structure = $ctx->category->getTournamentStructure();

      return $this->view->render($response, 'tournament/navigation/category_home.twig', [
         'pools'      => $structure->pools,
         'ko_rounds'  => $structure->getFinaleRounds(),
         'unmapped_participants' => $structure->unmapped_participants,
      ]);
   }

   /**
    * Show the overview of a single pool
    */
   public function showPool(Request $request, Response $response, array $args, $error = null): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      return $this->view->render($response, 'tournament/navigation/pool_home.twig', [
         'pool' => $ctx->pool,
         'error' => $error,
         'area_selection' => $ctx->category->getTournamentStructure()->areas->column('name', 'id'),
      ]);
   }

   /**
    * add a tie break match to a pool if needed, and redirect to the new match
    */
   public function addPoolTieBreak(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      if( $error = $this->matchService->addPoolTieBreak($ctx->pool) )
      {
         return $this->showPool($request, $response, $args, $error);
      }

      return $this->prgService->redirectBack($request, $response, 'tie_break_added');
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

   /**
    * RESET all match records for a specific category - TEMPORARY, FOR TESTING PURPOSES ONLY
    */
   public function resetMatchRecords(Request $request, Response $response): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $this->structureLoadService->resetMatchRecords($ctx->category);
      return $this->prgService->redirectBack($request, $response, 'records_deleted');
   }

   /**
    * reroll all participants
    */
   public function repopulate(Request $request, Response $response): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $this->structureLoadService->repopulate($ctx->category);
      return $this->prgService->redirectBack($request, $response, 'repopulated');
   }

   /**
    * assign unslotted participants into the structure
    */
   public function addUnslottedParticipants(Request $request, Response $response): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $this->structureLoadService->addParticipants($ctx->category);
      return $this->prgService->redirectBack($request, $response, 'add_unslotted');
   }

   public function showMatch(Request $request, Response $response, array $args, $error=null): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      /* get pointers to the previous and next "real" matches */
      $structure = $ctx->category->getTournamentStructure();
      $matchList = $ctx->pool? $ctx->pool->getMatchList()
                 : $structure->getFinaleRounds()->filterRounds(fn($n) => $n->isReal() && $n->getArea() === $ctx->match->getArea());
      $current_it = $matchList->getNodeIteratorAt($ctx->match->getName());

      return $this->view->render($response, 'tournament/match/match.twig', [
         'type'     => $ctx->pool? 'pool' : 'ko',
         'pool'     => $ctx->pool,
         'node'     => $ctx->match,
         'node_it'  => $current_it,
         'area'     => $ctx->match->getArea(),  // explicitly mark that we provide the match list for this area, only
         'area_selection' => $structure->areas->column('name', 'id'),
         'error'    => $error,
      ]);
   }

   public function updateMatch(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      /* evaluate the match update data via our match service */
      if($error = $this->matchService->updateMatchPoint($ctx->match, (array)$request->getParsedBody()) )
      {
         return $this->showMatch($request, $response, $args, $error);
      }

      return $this->prgService->redirectBack($request, $response, 'match_updated');
   }

   public function setNodeArea(Request $request, Response $response): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      $structure = $ctx->category->getTournamentStructure();
      $rules = [ 'area_id' => v::intVal()->in($structure->areas->column('id')) ];
      $data = (array)$request->getParsedBody();
      $errors = DataValidationService::validate($data, $rules);

      if( !$errors )
      {
         $this->structureLoadService->updateAreaAssignment($ctx->match, $data['area_id']);
      }

      return $this->prgService->redirectBack($request, $response, 'area_updated');
   }

   public function setPoolArea(Request $request, Response $response): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      $structure = $ctx->category->getTournamentStructure();
      $rules = ['area_id' => v::intVal()->in($structure->areas->column('id'))];
      $data = (array)$request->getParsedBody();
      $errors = DataValidationService::validate($data, $rules);

      if (!$errors)
      {
         $this->structureLoadService->updateAreaAssignment($ctx->pool, $data['area_id']);
      }

      return $this->prgService->redirectBack($request, $response, 'area_updated');
   }
}