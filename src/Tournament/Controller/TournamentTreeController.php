<?php

namespace Tournament\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use Slim\Views\Twig;
use Slim\Routing\RouteContext;

use Tournament\Model\TournamentStructure\MatchNode\MatchNode;
use Tournament\Model\TournamentStructure\Pool\Pool;
use Tournament\Model\TournamentStructure\TournamentStructure;
use Tournament\Model\MatchRecord\MatchPoint;

use Tournament\Repository\MatchDataRepository;
use Tournament\Repository\ParticipantRepository;

use Tournament\Service\RouteArgsContext;
use Tournament\Service\TournamentStructureService;

use Tournament\Exception\EntityNotFoundException;

use Respect\Validation\Validator as v;
use Base\Service\DataValidationService;
use Base\Service\PrgService;

class TournamentTreeController
{
   public function __construct(
      private Twig $view,
      private ParticipantRepository $p_repo,
      private MatchDataRepository $m_repo,
      private TournamentStructureService $structureLoadService,
      private PrgService $prgService,
   )
   {
   }

   /**
    * Show a specific category Pool
    */
   public function showCategoryPool(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      // Load the tournament structure for this category
      $structure = $this->structureLoadService->load($ctx->category);

      return $this->view->render($response, 'tournament/navigation/category_Pool.twig', [
         'pools'      => $structure->pools,
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

      /* filter pool/ko display if we have a very large structure */
      if ($structure->ko)
      {
         $ko = $structure->ko->getRounds(- ($structure->finale_rounds_cnt ?? 0));
      }

      return $this->view->render($response, 'tournament/navigation/category_KO.twig', [
         'no_pools'   => $structure->pools->empty(),
         'ko'         => $ko,
         'chunks'     => $structure->chunks,
         'unmapped_participants' => $structure->unmapped_participants,
      ]);
   }

   /**
    * Show a specific category home
    */
   public function showCategoryHome(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      // Load the tournament structure for this category
      $structure = $this->structureLoadService->load($ctx->category);

      /* filter pool/ko display if we have a very large structure */
      if ($structure->ko)
      {
         $ko = $structure->ko->getRounds(- ($structure->finale_rounds_cnt ?? 0));
      }

      return $this->view->render($response, 'tournament/navigation/category_home.twig', [
         'pools'      => $structure->pools,
         'ko'         => $ko,
         'chunks'     => $structure->chunks,
         'unmapped_participants' => $structure->unmapped_participants,
      ]);
   }

   /**
    * Show the KO-tree assigned to a specific area and chunk for a specific category
    */
   public function showKoArea(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      // Load the tournament structure for this category and fetch the specific chunk
      $structure = $this->structureLoadService->load($ctx->category);
      $chunk = $structure->chunks[$args['chunk']] ?? throw new EntityNotFoundException('Chunk not found');

      return $this->view->render($response, 'tournament/navigation/area_ko.twig', [
         'ko'         => $chunk->root->getRounds(),
         'chunk'      => $chunk,
      ]);
   }

   /**
    * Show the overview of a single pool
    */
   public function showPool(Request $request, Response $response, array $args, ?TournamentStructure $structure = null, $error = null): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      // Load the tournament structure for this category and fetch the specific chunk
      $structure ??= $this->structureLoadService->load($ctx->category);
      /** @var Pool $pool */
      $pool = $structure->pools[$args['pool']] ?? throw new EntityNotFoundException('Pool not found');

      return $this->view->render($response, 'tournament/navigation/pool_home.twig', [
         'pool' => $pool,
         'error' => $error,
      ]);
   }

   /**
    * add a tie break match to a pool if needed, and redirect to the new match
    */
   public function addPoolTieBreak(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      /* load the structure and find the current node/match */
      $structure = $this->structureLoadService->load($ctx->category);

      /** @var Pool $pool */
      $pool = $structure->pools[$args['pool']] ?? throw new EntityNotFoundException('Pool not found: ' . $args['pool']);

      if( !$pool->needsDecisionRound() )
      {
         $error = 'no tie break needed';
      }
      else
      {
         /* generate the new tie break matches and register them in the database */
         $nodes = $pool->createDecisionRound();
         foreach( $nodes as $node )
         {
            $record = $node->provideMatchRecord($ctx->category);
            $record->tie_break = $nodes->count() === 1; // if only a single decision match - make it a tie break match.
            if (!$this->m_repo->saveMatchRecord($record))
            {
               $error = 'Match-Generierung ist fehlgeschlagen :-(';
            }
         }
      }

      if( $error )
      {
         return $this->view->render($response, 'tournament/navigation/pool_home.twig', [
            'pool'  => $pool,
            'error' => $error
         ]);
      }
      else
      {
         return $this->prgService->redirect($request, $response, 'show_pool', $args, 'tie_break_added');
      }
   }

   /**
    * remove a pool tie break match again
    */
   public function deletePoolDecisionRound(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      /* load the structure and find the current node/match */
      $structure = $this->structureLoadService->load($ctx->category);

      /** @var Pool $pool */
      $pool = $structure->pools[$args['pool']] ?? throw new EntityNotFoundException('Pool not found: ' . $args['pool']);
      /* get the list of current decision matches */
      $matches = $pool->getDecisionMatches($args['decision_round']);

      /* check if we can delete them - only if no points are currently assigned and not frozen */
      $is_frozen  = $matches->any(fn($n) => $n->isFrozen());
      $has_points = $matches->any(fn($n) => !$n->getMatchRecord()->points->empty());

      /* delete match record data, only if this is a tie break match, pool is not frozen, and there are no points registered, yet */
      if( !$matches->empty() && !$is_frozen && !$has_points )
      {
         foreach( $matches as $node )
         {
            if( !$this->m_repo->deleteMatchRecordById($node->getMatchRecord()->id) )
            {
               $error = "match data deletion failed!";
            }
         }
      }
      else
      {
         $error = "Entscheidungsrunde kann nicht gelöscht werden - " . match (true)
         {
            $matches->empty() => "Keine Entscheidungsrunde gefunden",
            $is_frozen        => "Pool-Ergebnisse bereits eingefroren",
            $has_points       => "Es wurden bereits Punkte erfasst",
            default => "UNBEKANNTER GRUND"
         };
      }

      /* forward to output page */
      if ($error)
      {
         return $this->showPool($request, $response, $args, $structure, $error);
      }
      else
      {
         return $this->prgService->redirect($request, $response, 'show_pool', $args, 'tie_break_deleted');
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
      return $this->prgService->redirect($request, $response, 'show_category_home', $args, 'records_deleted');
   }

   /**
    * reroll all participants
    */
   public function repopulate(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $this->structureLoadService->populate($ctx->category);
      return $this->prgService->redirect($request, $response, 'show_category_home', $args, 'repopulated');
   }

   public function showMatch(Request $request, Response $response, array $args, ?TournamentStructure $structure = null, $error=null): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      /* load the structure and find the current node/match */
      $structure ??= $this->structureLoadService->load($ctx->category);

      if( isset($args['pool']) )
      {
         /** @var Pool $pool */
         $pool = $structure->pools[$args['pool']] ?? throw new EntityNotFoundException('Pool not found: ' . $args['pool']);
         /* get the ordered list of matches in the current pool */
         $nav_match_list = $pool->getMatchList();
         /* get the current match node */
         $node = $nav_match_list->findNode($args['matchName']) ?? throw new EntityNotFoundException('Match not found: ' . $args['matchName']);
      }
      else
      {
         /* get the ordered list of matches in the current area.
          * include non-real matches as the current match might be non-real */
         $root = $structure->ko;
         $node = $root->findByName($args['matchName']) ?? throw new EntityNotFoundException('Match not found: ' . $args['matchName']);
         $nav_match_list = $root->getMatchList()->filter(fn(MatchNode $e) => $e->area == $node->area);
      }

      /* get an iterator to the current node for further navigation build-up */
      $current_it = $nav_match_list->getIteratorAt($node->getName());

      /* get the next real matches after the current one */
      $next_matches = $nav_match_list->slice($current_it->skip()->key())->filter(fn(MatchNode $n) => $n->isReal());

      /* get the previous real match before the current one */
      $previous_it = $current_it->back();
      while( $previous_it->valid() && !$previous_it->current()->isReal() )
      {
         $previous_it->prev();
      }

      /* load match point handler to get the list of possible points */
      $mphdl = $ctx->category->getMatchPointHandler();

      /* load the list of points per participant */
      if( $record = $node->getMatchRecord() )
      {
         $pts = [];
         foreach( ['red'   => $node->getRedParticipant(),
                  'white' => $node->getWhiteParticipant()]
               as $color => $participant)
         {
            $pts[$color] = [
               'points'  => $mphdl->getPoints($record)->for($participant),
               'penalty' => $mphdl->getActivePenalties($record)->for($participant),
               'undo'    => $record->points->for($participant)->filter(fn($p) => $p->isSolitary())->back()
            ];
         }
      }
      else
      {
         $pts = ['red' => null, 'white' => null ];
      }

      return $this->view->render($response, 'tournament/match/match.twig',[
         'type'     => isset($args['pool'])?'pool':'ko',
         'error'    => $error,
         'node'     => $node,
         'previous' => $previous_it->current(),
         'next'     => $next_matches,
         'possible_pts' => $mphdl->getPointList(),
         'points'   => $pts,
         'pool'     => $args['pool']??null,
      ]);
   }

   public function updateMatch(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      /* load the structure and find the current node/match */
      $structure = $this->structureLoadService->load($ctx->category);

      if (isset($args['pool']))
      {
         /** @var Pool $pool */
         $pool = $structure->pools[$args['pool']] ?? throw new EntityNotFoundException('Pool not found: ' . $args['pool']);
         /* get the ordered list of matches in the current pool */
         $node = $pool->getMatchList()->findNode($args['matchName']) ?? throw new EntityNotFoundException('Match not found: ' . $args['matchName']);
      }
      else
      {
         /* get the ordered list of matches in the current area.
          * include non-real matches as the current match might be non-real */
         $root = $structure->ko;
         $node = $root->findByName($args['matchName']) ?? throw new EntityNotFoundException('Match not found: ' . $args['matchName']);
      }

      /* prepare error message */
      $error = '';

      if( !$node->isDetermined() )
      {
         $error = "Match ist noch nicht valide";
      }
      else
      {
         /* load match point handler to evaluate the input */
         $mphdl = $ctx->category->getMatchPointHandler();

         /* load and validate the input data */
         $data = $request->getParsedBody();
         $rules = [
            'participant' => v::optional(v::in([$node->getRedParticipant()->id, $node->getWhiteParticipant()->id])),
            'action' => v::in(array_merge($mphdl->getPointList(), ['winner', 'undo', 'tie'])),
            'undo' => v::optional(v::intVal()->positive())
         ];
         $err_list = DataValidationService::validate($data, $rules);

         if( !empty($err_list))
         {
            $error = 'Eingabe abgelehnt';
         }
         else
         {
            $record = $node->provideMatchRecord($ctx->category);
            $saveRecord = false;

            if ($data['action'] === 'tie')
            {
               if (!$node->isModifiable())
               {
                  $error = 'Gewinner kann nicht mehr neu gesetzt werden';
               }
               else if (!$node->tiesAllowed())
               {
                  $error = 'Dieser Kampf darf nicht unentschieden enden';
               }
               else
               {
                  $record->winner = null;
                  $record->finalized_at = new \DateTime();
                  $saveRecord = true;
               }
            }
            else
            {
               $participant = $this->p_repo->getParticipantById($data['participant']) ?? throw new EntityNotFoundException('Unknown Participant');

               if( $data['action'] === 'winner' )
               {
                  if( $node->isModifiable() )
                  {
                     $record->winner = $participant;
                     $record->finalized_at = new \DateTime();
                     $saveRecord = true;
                  }
                  else
                  {
                     $error = 'Gewinner kann nicht mehr neu gesetzt werden';
                  }
               }
               else if( !$node->isModifiable() )
               {
                  $error = "Punkt-Änderungen nicht (mehr) erlaubt.";
               }
               else
               {
                  if ($data['action'] === 'undo')
                  {
                     if( $mphdl->removePoint($record, $data['undo']) )
                     {
                        $saveRecord = true;
                     }
                     else
                     {
                        $error = 'Punkt kann nicht zurückgenommen werden, abgelehnt';
                     }
                  }
                  else
                  {
                     $pt = new MatchPoint(null, $participant, $data['action'], new \DateTime());
                     if( $mphdl->addPoint($record, $pt) )
                     {
                        $saveRecord = true;
                     }
                     else
                     {
                        $error = 'Invalider Punkt, abgelehnt';
                     }
                  }
               }
            }

            if( $saveRecord )
            {
               if( $this->m_repo->saveMatchRecord($record) )
               {
                  $node->setMatchRecord($record);
               }
               else
               {
                  $error = 'Aktualisierung ist fehlgeschlagen :-(';
               }
            }
         }
      }

      if( $error )
      {
         return $this->showMatch($request, $response, $args, $structure, $error);
      }
      else
      {
         /* there are different routes: ko match, pool match.
          * but for all, the location is the same for the GET and POST route,
          * therefore just use the current route's name to generate the redirect
          */
         $route = RouteContext::fromRequest($request)->getRoute()->getName();
         return $this->prgService->redirect($request, $response, $route, $args, 'match_updated');
      }
   }
}