<?php

namespace Tournament\Controller\App;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use Slim\Views\Twig;

use Tournament\Model\TournamentStructure\TournamentStructure;
use Tournament\Model\TournamentStructure\MatchNode\MatchNodeCollection;
use Tournament\Model\TournamentStructure\MatchNode\MatchRoundCollection;

use Tournament\Service\RouteArgsContext;
use Tournament\Service\MatchHandlingService;
use Tournament\Service\TournamentStructureService;

use Tournament\Exception\EntityNotFoundException;

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
   public function showCategoryPools(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      // Load the tournament structure for this category
      $structure = $this->structureLoadService->load($ctx->category);

      return $this->view->render($response, 'tournament/navigation/category_Pool.twig', [
         'pools' => $structure->pools,
         'unmapped_participants' => $structure->unmapped_participants,
      ]);
   }

   /**
    * Show a specific category KO
    */
   public function showCategorytree(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      // Load the tournament structure for this category
      $structure = $this->structureLoadService->load($ctx->category);

      return $this->view->render($response, 'tournament/navigation/category_KO.twig', [
         'no_pools'   => $structure->pools->empty(),
         'ko'         => $structure->getFinaleRounds(),
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

      // Load the tournament structure for this category
      $structure = $this->structureLoadService->load($ctx->category);

      return $this->view->render($response, 'tournament/navigation/category_home.twig', [
         'pools'      => $structure->pools,
         'ko'         => $structure->getFinaleRounds(),
         'unmapped_participants' => $structure->unmapped_participants,
      ]);
   }

   /**
    * Show the overview of a single pool
    */
   public function showPool(Request $request, Response $response, array $args, ?TournamentStructure $structure = null, $error = null): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $structure ??= $this->structureLoadService->load($ctx->category);
      $pool = $structure->pools[$args['pool']] ?? throw new EntityNotFoundException($request, 'Pool not found');

      return $this->view->render($response, 'tournament/navigation/pool_home.twig', [
         'pool' => $pool,
         'error' => $error,
         'area_selection' => $structure->areas->column('name', 'id'),
      ]);
   }

   /**
    * add a tie break match to a pool if needed, and redirect to the new match
    */
   public function addPoolTieBreak(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $structure = $this->structureLoadService->load($ctx->category);
      $pool = $structure->pools[$args['pool']] ?? throw new EntityNotFoundException($request, 'Pool not found');
      $error = $this->matchService->addPoolTieBreak($pool);

      if( $error )
      {
         return $this->showPool($request, $response, $args, $structure, $error);
      }
      else
      {
         return $this->prgService->redirectBack($request, $response, 'tie_break_added');
      }
   }

   /**
    * remove a pool tie break match again
    */
   public function deletePoolDecisionRound(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $structure = $this->structureLoadService->load($ctx->category);
      $pool = $structure->pools[$args['pool']] ?? throw new EntityNotFoundException($request, 'Pool not found');
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

   /**
    * RESET all match records for a specific category - TEMPORARY, FOR TESTING PURPOSES ONLY
    */
   public function resetMatchRecords(Request $request, Response $response, array $args): Response
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

   public function showMatch(Request $request, Response $response, array $args, ?TournamentStructure $structure = null, $error=null): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      /* load the structure and find the current node/match */
      $structure ??= $this->structureLoadService->load($ctx->category);
      $node = $structure->findNode($ctx->match_name, $ctx->pool_name ?? false) ?? new EntityNotFoundException($request, "unknown Match '{$ctx->match_name}'");

      /* get pointers to the previous and next "real" matches */
      $matchList = match($ctx->pool_name)
      {
         /* if pool given, get all of the current pool */
         default => $structure->pools[$ctx->pool_name]->getMatchList(),
         /* if outside pool, get all ko matches of this area that are "real" */
         null    => $structure->getFinaleRounds()->filterRounds(fn($n) => $n->isReal() && $n->area === $node->area),
      };
      /** @var MatchNodeCollection|MatchRoundCollection $matchList */
      $current_it = $matchList->getNodeIteratorAt($ctx->match_name);

      return $this->view->render($response, 'tournament/match/match.twig', [
         'type'     => isset($args['pool'])?'pool':'ko',
         'pool'     => $args['pool']??null,
         'node'     => $node,
         'node_it'  => $current_it,
         'area'     => $node->area,  // explicitly mark that we provide the match list for this area, only
         'area_selection' => $structure->areas->column('name', 'id'),
         'error'    => $error,
      ]);
   }

   public function updateMatch(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      /* load the structure and find the current node/match */
      $structure = $this->structureLoadService->load($ctx->category);
      $node = $structure->findNode($ctx->match_name, $ctx->pool_name ?? false) ?? new EntityNotFoundException($request, "unknown Match '{$ctx->match_name}'");

      /* evaluate the match update data via our match service */
      $error = $this->matchService->updateMatchPoint($node, (array)$request->getParsedBody());

      if( $error )
      {
         return $this->showMatch($request, $response, $args, $structure, $error);
      }
      else
      {
         return $this->prgService->redirectBack($request, $response, 'match_updated');
      }
   }

   public function setNodeArea(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      $structure = $this->structureLoadService->initialize($ctx->category);
      $node = $structure->findNode($ctx->match_name) ?? new EntityNotFoundException($request, "unknown Match '{$ctx->match_name}'");

      $rules = [ 'area_id' => v::intVal()->in($structure->areas->column('id')) ];
      $data = (array)$request->getParsedBody();
      $errors = DataValidationService::validate($data, $rules);

      if( !$errors )
      {
         $this->structureLoadService->updateAreaAssignment($node, $data['area_id']);
      }

      return $this->prgService->redirectBack($request, $response, 'area_updated');
   }

   public function setPoolArea(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      $structure = $this->structureLoadService->initialize($ctx->category);
      $pool = $structure->pools[$ctx->pool_name] ?? throw new EntityNotFoundException($request, 'Pool not found');

      $rules = ['area_id' => v::intVal()->in($structure->areas->column('id'))];
      $data = (array)$request->getParsedBody();
      $errors = DataValidationService::validate($data, $rules);

      if (!$errors)
      {
         $this->structureLoadService->updateAreaAssignment($pool, $data['area_id']);
      }

      return $this->prgService->redirectBack($request, $response, 'area_updated');
   }
}