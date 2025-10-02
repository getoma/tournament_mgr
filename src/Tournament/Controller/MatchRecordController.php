<?php

namespace Tournament\Controller;

use DateTime;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Tournament\Exception\EntityNotFoundException;
use Tournament\Model\Data\MatchRecord;
use Tournament\Repository\MatchDataRepository;
use Tournament\Repository\ParticipantRepository;
use Tournament\Service\TournamentStructureService;

class MatchRecordController
{
   public function __construct(
      private Twig $view,
      private ParticipantRepository $p_repo,
      private MatchDataRepository $m_repo,
      private TournamentStructureService $structureLoadService,
   )
   {
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