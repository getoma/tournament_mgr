<?php

namespace Tournament\Controller;

use DateTime;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use Slim\Views\Twig;
use Slim\Routing\RouteContext;

use Tournament\Model\MatchRecord\MatchRecord;

use Tournament\Repository\MatchDataRepository;
use Tournament\Repository\ParticipantRepository;

use Tournament\Service\TournamentStructureService;
use Tournament\Exception\EntityNotFoundException;

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

      return $this->view->render($response, 'category/home.twig', [
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

      return $this->view->render($response, 'category/area_ko.twig', [
         'ko'         => $chunk->root->getRounds(),
         'chunk'      => $chunk,
      ]);
   }

   /**
    * Show the pool view assigned to a specific area and chunk for a specific category
    */
   public function showPoolArea(Request $request, Response $response, array $args): Response
   {
      // Load the tournament structure for this category
      $structure = $this->structureLoadService->load($request->getAttribute('category'));
      $area_pools = $structure->getPoolsByArea($request->getAttribute('area')->id);
      return $this->view->render($response, 'category/area_pool.twig', [
         'pools' => $area_pools
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

   public function showKoMatch(Request $request, Response $response, array $args): Response
   {
      $structure = $this->structureLoadService->load($request->getAttribute('category'));
      $node = $structure->ko->findByName($args['matchName']) ?? throw new EntityNotFoundException('Match not found: ' . $args['matchName']);

      return $this->view->render($response,'category/match.twig',[
         'node' => $node
      ]);
   }

   public function updateKoMatch(Request $request, Response $response, array $args): Response
   {
      $structure = $this->structureLoadService->load($request->getAttribute('category'));
      $node = $structure->ko->findByName($args['matchName']) ?? throw new EntityNotFoundException('Match not found: ' . $args['matchName']);

      $error = '';

      if( $node->isModifiable() )
      {
         $saveRecord = false;

         $record = $node->getMatchRecord()
            ?? new MatchRecord(
               id: null,
               name: $node->name,
               category: $request->getAttribute('category'),
               area: $node->area,
               redParticipant: $node->getRedParticipant(),
               whiteParticipant: $node->getWhiteParticipant(),
         );

         $data = $request->getParsedBody();
         if( $data['action'] === 'winner' )
         {
            $participant = $this->p_repo->getParticipantById($data['participant']) ?? throw new EntityNotFoundException('Unknown Participant');

            if( $participant == $record->redParticipant || $participant == $record->whiteParticipant )
            {
               $record->winner = $participant;
               $record->finalized_at = new \DateTime();
            }
            else
            {
               $error = "Gewinner passt nicht zu den Teilnehmern, aktualisierung wird abgelehnt";
            }

            $saveRecord = true;
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
      else
      {
         $error = 'Diesem Kampf können keine Ergebnisse zugewiesen werden!';
      }

      return $this->view->render($response, 'category/match.twig', [
         'error' => $error,
         'node' => $node
      ]);
   }
}