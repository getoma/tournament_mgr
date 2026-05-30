<?php declare(strict_types=1);

namespace Tournament\Service;

use Tournament\Model\Area\Area;
use Tournament\Model\Participant\ParticipantCollection;
use Tournament\Model\Participant\ParticipantChangeLog;
use Tournament\Model\TournamentStructure\KoTree;
use Tournament\Model\TournamentStructure\Pool\Pool;

use Base\Model\ChangeLogEntry;
use Base\Repository\ChangeLogRepository;
use Tournament\Repository\ParticipantRepository;

class ChangeLogEvaluationService
{
   public function __construct(
      private ChangeLogRepository $repo,
      private ParticipantRepository $p_repo,
   )
   {
   }

   public function getChangesForPool(Pool $pool): ParticipantChangeLog
   {
      $log = ParticipantChangeLog::from($this->repo->getChangeLogsByGroupId($pool->category->tournament_id))->compress();
      $pool_participants = $pool->getParticipants();
      $result = ParticipantChangeLog::new();
      foreach( $log as $e )
      {
         /** @var ChangeLogEntry $e */
         /* for a dedicated pool changes list, we only care about renames and slot changes. Skip others */
         if( in_array( $e->change_type, ['withdraw', 'club_changed'] ) ) continue;

         $slotPool = array_key_exists('slot_name', $e->details) ? Pool::getPoolIdFromSlotName($e->details['slot_name']??'', false) : null;
         $fromPool = $e->change_type === 'category_slot_changed'? Pool::getPoolIdFromSlotName($e->details['from']??'', false) : null;

         if ($slotPool && ($slotPool === $fromPool)) continue; // change of pool slot within the same pool - ignore entirely

         /* now check if this change log is relevant for this pool: */
         if( $pool_participants->keyExists($e->entity_id)      || // change log for a participant we have in this pool
             in_array($pool->getName(), [$slotPool, $fromPool])   // any slot change relevant to this pool
         )
         {
            // ...and add the corresponding participant instance for easy access in the template
            $e->details['participant'] = $this->p_repo->getParticipantById($e->entity_id);
            // rework any "category changed" logs to dump them down for the template output
            if( $e->change_type === 'category_slot_changed' )
            {
               $e->change_type = match($pool->getName())
               {
                  $slotPool => 'category_added',   // from pool perspective, this is an "added" change
                  $fromPool => 'category_removed', // from pool perspective, this is a "removed" change
               };
            }
            // take it over...
            $result[] = $e;
         }
      }
      return $result;
   }

   public function getChangesForKoTree(KoTree $tree, ?Area $area = null): ParticipantChangeLog
   {
      $log = ParticipantChangeLog::from($this->repo->getChangeLogsByGroupId($tree->root->getCategory()->tournament_id))->compress();
      $slots = $tree->getFirstRound()->filter(fn($n) => !$area || $n->getArea() === $area)->getNamedSlots();
      $participants = ParticipantCollection::new(array_filter($slots->map(fn($s) => $s->getParticipant())));
      $result = ParticipantChangeLog::new();
      foreach ($log as $e)
      {
         /** @var ChangeLogEntry $e */
         /* for a dedicated tree changes list, we only care about renames and slot changes. Skip others */
         if (in_array($e->change_type, ['withdraw', 'club_changed'])) continue;

         $hasSlotName = $slots->keyExists($e->details['slot_name'] ?? '');
         $hasFromSlot = $e->change_type === 'category_slot_changed'? $slots->keyExists($e->details['from']??'') : null;

         /* now check if this change log is relevant for this pool: */
         if (
            $participants->keyExists($e->entity_id) || // change log for a participant we have in this sub tree
            $hasSlotName || $hasFromSlot               // any slot change relevant to this sub tree
         )
         {
            // ...and add the corresponding participant instance for easy access in the template
            $e->details['participant'] = $this->p_repo->getParticipantById($e->entity_id);
            // rework any "category changed" logs to dump them down for the template output
            if ($e->change_type === 'category_slot_changed' && ($hasSlotName !== $hasFromSlot))
            {
               if( $hasSlotName ) // this is an "added" change from the current subtree perspective
               {
                  $e->change_type = 'category_added';
                  unset($e->details['from']);
               }
               else // this is a "removed" change from current subtree perspective
               {
                  $e->change_type = 'category_removed';
                  unset($e->details['slot_name']);
               }
            }
            // take it over...
            $result[] = $e;
         }
      }
      return $result;
   }
}