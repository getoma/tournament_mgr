<?php

namespace Tournament\Controller;

use DateTime;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use Slim\Views\Twig;
use Slim\Routing\RouteContext;

use Tournament\Model\MatchRecord\MatchRecord;
use Tournament\Model\Category\Category;
use Tournament\Model\TournamentStructure\MatchNode\KoNode;
use Tournament\Model\TournamentStructure\TournamentStructure;

use Tournament\Repository\MatchDataRepository;
use Tournament\Repository\ParticipantRepository;

use Tournament\Service\TournamentStructureService;
use Tournament\Exception\EntityNotFoundException;

use Respect\Validation\Validator as v;
use Base\Service\DataValidationService;
use Tournament\Model\MatchRecord\MatchPoint;
use Tournament\Model\MatchRecord\MatchPointCollection;

class TournamentTreeController
{
   public function __construct(
      private Twig $view,
      private ParticipantRepository $p_repo,
      private MatchDataRepository $m_repo,
      private TournamentStructureService $structureLoadService,
   )
   {
   }

   /**
    * Show a specific category
    */
   public function showCategoryTree(Request $request, Response $response, array $args): Response
   {
      // Load the tournament structure for this category
      $structure = $this->structureLoadService->load($request->getAttribute('category'));

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
      // Load the tournament structure for this category and fetch the specific chunk
      $structure = $this->structureLoadService->load($request->getAttribute('category'));
      $chunk = $structure->chunks[$args['chunk']] ?? throw new EntityNotFoundException('Chunk not found');

      return $this->view->render($response, 'tournament/navigation/area_ko.twig', [
         'ko'         => $chunk->root->getRounds(),
         'chunk'      => $chunk,
      ]);
   }

   /**
    * RESET all match records for a specific category - TEMPORARY, FOR TESTING PURPOSES ONLY
    */
   public function resetMatchRecords(Request $request, Response $response, array $args): Response
   {
      $category = $request->getAttribute('category');
      $this->structureLoadService->resetMatchRecords($category);
      return $response->withHeader(
         'Location',
         RouteContext::fromRequest($request)->getRouteParser()->urlFor('show_category', $args)
      )->withStatus(302);
   }

   /**
    * reroll all participants
    */
   public function repopulate(Request $request, Response $response, array $args): Response
   {
      $category = $request->getAttribute('category');
      $this->structureLoadService->populate($category);
      return $response->withHeader(
         'Location',
         RouteContext::fromRequest($request)->getRouteParser()->urlFor('show_category', $args)
      )->withStatus(302);
   }

   public function showKoMatch(Request $request, Response $response, array $args, ?TournamentStructure $structure = null, $error=null): Response
   {
      /** @var Category $category */
      $category = $request->getAttribute('category');

      /* load the structure and find the current node/match */
      $structure ??= $this->structureLoadService->load($category);
      $root = $structure->ko;
      $node = $root->findByName($args['matchName']) ?? throw new EntityNotFoundException('Match not found: ' . $args['matchName']);

      /* get the ordered list of matches in the current area and a pointer to the current node.
       * include non-real matches as the current match might be non-real */
      $nav_match_list = $root->getMatchList()->filter(fn(KoNode $e) => $e->area == $node->area);
      $current_it = $nav_match_list->getIteratorAt($node->name);

      /* get the next real matches after the current one */
      $next_matches = $nav_match_list->slice($current_it->skip()->key())->filter(fn(KoNode $n) => $n->isReal());

      /* get the previous real match before the current one */
      $previous_it = $current_it->back();
      while( $previous_it->valid() && !$previous_it->current()->isReal() )
      {
         $previous_it->prev();
      }

      /* load match point handler to get the list of possible points */
      $mphdl = $category->getMatchPointHandler();

      /* load the list of points per participant */
      $record = $node->getMatchRecord()?->points ?? new MatchPointCollection();

      $pts = [];
      $redP = $node->getRedParticipant();
      $whiteP = $node->getWhiteParticipant();
      foreach( ['red'   => isset($redP)?   $record->for($redP) : $record->new(),
                'white' => isset($whiteP)? $record->for($whiteP) : $record->new()]
             as $color => $pt_list)
      {
         $pts[$color] = [
            'points'  => $mphdl->getPoints($pt_list),
            'penalty' => $mphdl->getActivePenalties($pt_list),
            'undo'    => $pt_list->filter(fn($p) => $p->isSolitary())->back()
         ];
      }

      return $this->view->render($response, 'tournament/match/match.twig',[
         'error'    => $error,
         'node'     => $node,
         'previous' => $previous_it->current(),
         'next'     => $next_matches,
         'possible_pts' => $mphdl->getPointList(),
         'points'   => $pts,
         'debug' => null
      ]);
   }

   public function updateKoMatch(Request $request, Response $response, array $args): Response
   {
      /** @var Category $category */
      $category = $request->getAttribute('category');

      /* load the structure and find the current node/match */
      $structure = $this->structureLoadService->load($request->getAttribute('category'));
      $node = $structure->ko->findByName($args['matchName']) ?? throw new EntityNotFoundException('Match not found: ' . $args['matchName']);

      /* prepare error message */
      $error = '';

      if( !$node->isDetermined() )
      {
         $error = "Match ist noch nicht valide";
      }
      else
      {
         /* load match point handler to evaluate the input */
         $mphdl = $category->getMatchPointHandler();

         /* load and validate the input data */
         $data = $request->getParsedBody();
         $rules = [
            'participant' => v::in([$node->getRedParticipant()->id, $node->getWhiteParticipant()->id]),
            'action' => v::in(array_merge($mphdl->getPointList(), ['winner', 'undo'])),
            'undo' => v::optional(v::intVal()->positive())
         ];
         $err_list = DataValidationService::validate($data, $rules);

         if( !empty($err_list))
         {
            $error = 'Eingabe abgelehnt';
         }
         else
         {
            $record = $node->provideMatchRecord($category);
            $participant = $this->p_repo->getParticipantById($data['participant']) ?? throw new EntityNotFoundException('Unknown Participant');

            $saveRecord = false;

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
            else if ($data['action'] === 'undo')
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

      return $this->showKoMatch($request, $response, $args, $structure, $error);
   }
}