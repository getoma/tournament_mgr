<?php declare(strict_types=1);

namespace Tournament\Service;

use Tournament\Model\MatchRecord\MatchPoint;
use Tournament\Model\TournamentStructure\MatchNode\SoloMatch;
use Tournament\Model\TournamentStructure\Pool\Pool;

use Tournament\Repository\MatchDataRepository;
use Tournament\Repository\ParticipantRepository;

use Respect\Validation\Validator as v;
use Base\Service\DataValidationService;

/***
 * a collection of input processing methods needed both in normal application context and in device context
 * for handling match record data
 */
class MatchHandlingService
{
   public function __construct(
      private ParticipantRepository $p_repo,
      private MatchDataRepository $m_repo,
   )
   {
   }

   public function updateMatchPoint(SoloMatch $node, array $post_data): ?string
   {
      /* prepare error message */
      $error = null;

      if (!$node->isDetermined())
      {
         $error = "Match ist noch nicht valide";
      }
      else
      {
         $pointHdl = $node->category->getMatchPointHandler();
         /* load and validate the input data */
         $rules = [
            'participant' => v::optional(v::in([$node->getRedParticipant()->id, $node->getWhiteParticipant()->id])),
            'action' => v::in(array_merge($pointHdl->getPointList(), ['winner', 'undo', 'tie'])),
            'undo' => v::optional(v::intVal()->positive())
         ];
         $err_list = DataValidationService::validate($post_data, $rules);

         if (!empty($err_list))
         {
            $error = 'Eingabe abgelehnt';
         }
         else
         {
            $record = $node->provideMatchRecord();
            $saveRecord = false;

            if ($post_data['action'] === 'tie')
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
                  $record->setWinner(null);
                  $record->finalized_at = new \DateTime();
                  $saveRecord = true;
               }
            }
            else
            {
               $participant = $this->p_repo->getParticipantById((int)$post_data['participant']) ?? throw new \OutOfBoundsException('Unknown Participant');

               if ($post_data['action'] === 'winner')
               {
                  if ($node->isModifiable())
                  {
                     $record->setWinner($participant);
                     $record->finalized_at = new \DateTime();
                     $saveRecord = true;
                  }
                  else
                  {
                     $error = 'Gewinner kann nicht mehr neu gesetzt werden';
                  }
               }
               else if (!$node->isModifiable())
               {
                  $error = "Punkt-Änderungen nicht (mehr) erlaubt.";
               }
               else
               {
                  if ($post_data['action'] === 'undo')
                  {
                     if ($pointHdl->removePoint($record, $post_data['undo']))
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
                     $pt = new MatchPoint(null, $participant, $post_data['action'], new \DateTime());
                     if ($pointHdl->addPoint($record, $pt))
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

            if ($saveRecord)
            {
               if ($this->m_repo->saveMatchRecord($record))
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

      return $error;
   }

   public function addPoolTieBreak(Pool $pool): ?string
   {
      if (!$pool->needsDecisionRound())
      {
         return 'no tie break needed';
      }
      else
      {
         /* generate the new tie break matches and register them in the database */
         $nodes = $pool->createDecisionRound();
         foreach ($nodes as $node)
         {
            if( $node instanceof SoloMatch )
            {
               $record = $node->provideMatchRecord();
               $record->tie_break = $nodes->count() === 1; // if only a single decision match - make it a tie break match.
               if (!$this->m_repo->saveMatchRecord($record))
               {
                  return 'Match-Generierung ist fehlgeschlagen :-(';
               }
            }
            else
            {
               throw new \LogicException("unsupported node type " . get_class($node));
            }
         }
      }
      return null;
   }

   public function deletePoolTieBreak(Pool $pool, int $roundId): ?string
   {
      /* get the list of current decision matches */
      $matches = $pool->getDecisionMatches($roundId);

      /* check if we can delete them - only if no points are currently assigned and not frozen */
      $is_frozen  = $matches->any(fn($n) => $n->isFrozen());
      $has_points = $matches->any(fn($n) => !$n->getMatchRecord()->points->empty());

      /* check whether this is an actual existing tie break round, pool is not frozen, and there are no points registered, yet */
      if ($matches->empty() || $is_frozen || $has_points)
      {
         return "Entscheidungsrunde kann nicht gelöscht werden - " . match (true)
         {
            $matches->empty() => "Keine Entscheidungsrunde gefunden",
            $is_frozen        => "Pool-Ergebnisse bereits eingefroren",
            $has_points       => "Es wurden bereits Punkte erfasst",
            default           => "UNBEKANNTER GRUND"
         };
      }

      /* all fine, try to delete */
      foreach ($matches as $node)
      {
         if (!($node instanceof SoloMatch) || !$this->m_repo->deleteMatchRecordById($node->getMatchRecord()->id))
         {
            return "match data deletion failed!";
         }
      }
      return null;
   }
}