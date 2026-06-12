<?php declare(strict_types=1);

namespace Tournament\Controller\App;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use Slim\Views\Twig;

use Tournament\Model\TournamentStructure\MatchNode\MatchNodeCollection;
use Tournament\Model\TournamentStructure\MatchNode\MatchRoundCollection;
use Tournament\Model\TournamentStructure\MatchNode\TeamSoloMatch;
use Tournament\Model\TournamentStructure\MatchNode\TeamMatch;

use Tournament\Service\RouteArgsContext;
use Tournament\Service\MatchHandlingService;
use Tournament\Service\TournamentStructureService;
use Tournament\Service\ChangeLogEvaluationService;

use Base\Service\PrgService;
use Base\Service\DataValidationService;

use Respect\Validation\Validator as v;

class TournamentTreeController
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

      // Load the tournament structure for this category
      $structure = $ctx->category->getTournamentStructure();
      $chgLog = $structure->pools->empty()? $this->chgLogService->getChangesForKoTree($structure->ko) : null;

      return $this->view->render($response, 'tournament/navigation/category_KO.twig', [
         'no_pools'   => $structure->pools->empty(),
         'ko_rounds'  => $structure->getFinaleRounds(),
         'unmapped_participants' => $structure->unmapped_participants,
         'change_log' => $chgLog,
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
         'change_log' => $this->chgLogService->getChangesForPool($ctx->pool),
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
    * add a tie break match to a pool if needed, and redirect to the new match
    */
   public function addTeamMatchTieBreak(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      if($ctx->match instanceof TeamMatch ) $error = $this->matchService->addTeamMatchTieBreak($ctx->match);
      else throw new \LogicException('not a team match');

      if ($error)
      {
         return $this->showMatch($request, $response, $args, $error);
      }
      else
      {
         return $this->prgService->redirectBack($request, $response, 'tie_break_added');
      }
   }

   /**
    * remove a pool tie break match again
    */
   public function deleteTeamMatchTieBreak(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      if ($ctx->match instanceof TeamMatch) $error = $this->matchService->deleteTeamMatchTieBreak($ctx->match);
      else throw new \LogicException('not a team match');

      /* forward to output page */
      if ($error)
      {
         return $this->showMatch($request, $response, $args, $error);
      }
      else
      {
         return $this->prgService->redirectBack($request, $response, 'tie_break_deleted');
      }
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

      /* load the structure and find the current node/match */
      $structure = $ctx->category->getTournamentStructure();
      $node = $ctx->match;

      /* this method might be called with the specific solo match node for team matches
       * change to the parent node for the resulting template, and set this sub note as selected
       */
      if ($node instanceof TeamSoloMatch)
      {
         $selected = $node->getName();
         $node = $node->parent;
      }
      /* otherwise, if a team node was requested, still select a sub node for the template view */
      else if ($node instanceof TeamMatch)
      {
         /* select an active match from this node */
         $matches = $node->getMatchList();
         $selected = $request->getQueryParams()['selected'] ?? null;
         if ($selected === null || !$matches->findNode($selected))
         {
            /* default to the first uncompleted match, or the very last match if all completed */
            $selected = $matches->filter(fn($n) => !$n->isCompleted())->first()?->getName() ?? $matches->last()?->getName();
         }
      }
      else
      {
         $selected = $node->getName();
      }

      /* get pointers to the previous and next "real" matches */
      $matchList = $ctx->pool? $ctx->pool->getMatchList()
                 : $structure->getFinaleRounds()->filterRounds(fn($n) => $n->isReal() && $n->getArea() === $ctx->match->getArea());
      /** @var MatchNodeCollection|MatchRoundCollection $matchList */
      $current_it = $matchList->getNodeIteratorAt($node->getName());

      /* for team matches (getMembers() !== null), we need to allow modifying the participant order */
      $redSideSelection   = $node->getRedParticipant()?->getMembers()?->map(fn($p) => $p->getDisplayName());
      $whiteSideSelection = $node->getWhiteParticipant()?->getMembers()?->map(fn($p) => $p->getDisplayName());

      return $this->view->render($response, 'tournament/match/match.twig', [
         'type'     => $ctx->pool? 'pool' : 'ko',
         'pool'     => $ctx->pool,
         'node'     => $node,
         'selected' => $selected,
         'node_it'  => $current_it,
         'area'     => $ctx->match->getArea(),  // explicitly mark that we provide the match list for this area, only
         'area_selection' => $structure->areas->column('name', 'id'),
         'red_side_selection' => $redSideSelection,
         'white_side_selection' => $whiteSideSelection,
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

   public function updateTeamOrder(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      /* evaluate the match update data via our match service */
      $data = (array)$request->getParsedBody();
      if( is_array($data['redSide']) && is_array($data['whiteSide']) )
      {
         $error = $this->matchService->updateTeamMatchParticipantOrder($ctx->match, $data['redSide'], $data['whiteSide']);
      }
      else
      {
         $error = 'invalid input';
      }

      if ($error)
      {
         return $this->showMatch($request, $response, $args, ['team_order' => $error]);
      }
      else
      {
         return $this->prgService->redirectBack($request, $response, 'order_updated');
      }
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