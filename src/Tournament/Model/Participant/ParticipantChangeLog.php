<?php declare(strict_types=1);

namespace Tournament\Model\Participant;

use Base\Model\ChangeLogCollection;
use Base\Model\ChangeLogEntry;

class ParticipantChangeLog extends ChangeLogCollection
{
   static public function getEntityType(): ?string
   {
      return 'Participant';
   }

   static public function create(Participant $previous, Participant $current): static
   {
      $createChgLog = fn(string $type, array $details) => new ChangeLogEntry(
         id: null,
         entity_type: 'Participant',
         entity_id: $current->id,
         group_id: $current->tournament_id,
         change_type: $type,
         details: $details,
      );
      $result = static::new();
      /* general data diffs */
      $previous_name = $previous->getDisplayName();
      $current_name = $current->getDisplayName();
      if ($previous_name !== $current_name)
      {
         $result[] = $createChgLog('rename', ['from' => $previous_name, 'to' => $current_name]);
      }
      if ($previous->club !== $current->club)
      {
         $result[] = $createChgLog('club_change', ['from' => $previous->club, 'to' => $current->club]);
      }
      if ($previous->withdrawn !== $current->withdrawn)
      {
         $result[] = $createChgLog('withdrawn_status_change', [
            'from' => $previous->withdrawn ? 'withdrawn' : 'active',
            'to'   => $current->withdrawn  ? 'withdrawn' : 'active',
         ]);
      }
      $previous_categories = $previous->categories->keys();
      $current_categories = $current->categories->keys();
      /* removed categories */
      foreach (array_diff($previous_categories, $current_categories) as $removed_id)
      {
         $result[] = $createChgLog( 'category_removed', [
            'category_id' => $removed_id,
            'slot_name'   => $previous->categories[$removed_id]->slot_name,
         ]);
      }
      /* added categories */
      foreach (array_diff($current_categories, $previous_categories) as $added_id)
      {
         $result[] = $createChgLog('category_added', [
            'category_id' => $added_id,
            'slot_name'   => $current->categories[$added_id]->slot_name,
         ]);
      }
      /* start slot changes without changing category assignment */
      foreach (array_intersect($previous_categories, $current_categories) as $category_id)
      {
         $prev_ca = $previous->categories[$category_id];
         $curr_ca = $current->categories[$category_id];
         /* we don't care about pre assigned slot, as it doesn't have any immediate effect on the tournament setup */
         /* track any change to the actual start slot */
         if ($prev_ca->slot_name !== $curr_ca->slot_name)
         {
            $result[] = $createChgLog('category_slot_changed', [
               'category_id' => $category_id,
               'slot_name'   => $curr_ca->slot_name,
               'from'        => $prev_ca->slot_name,
            ]);
         }
      }
      return $result;
   }

   /**
    * compress change log - combine consecutive similar changes into a single one
    * e.g.:
    * - multiple renames of the same participant will be combined into a single rename log
    * - remove revoked changes: remove any consecutive changelogs that result back into original state (e.g. withdrawn = 1, withdrawn = 0)
    */
   public function compress(): static
   {
      $combined = [];
      foreach( $this->sorted() as $log )
      {
         /** @var ChangeLogEntry $log */
         $category_id = $log->details['category_id'] ?? 0;
         $combined[$log->group_id][$log->entity_id][$log->change_type][$category_id] ??= $log;
         $stored_log = $combined[$log->group_id][$log->entity_id][$log->change_type][$category_id];
         $drop_for = function($change_type) use (&$combined, $log, $category_id)
                     { unset($combined[$log->group_id][$log->entity_id][$change_type][$category_id]); };

         switch( $log->change_type )
         {
            case 'rename':
            case 'club_change':
            case 'withdrawn_status_change':
               $stored_log->details['to'] = $log->details['to'];
               if( $stored_log->details['to'] === $stored_log->details['from'] )
               {
                  $drop_for($log->change_type);
                  $stored_log = null;
               }
               break;

            case 'category_added':
               /* check for previous removed */
               $removed = $combined[$log->group_id][$log->entity_id]['category_removed'][$category_id] ?? null;
               if( $removed )
               {
                  /* removed and added again: make it a slot change */
                  $combined[$log->group_id][$log->entity_id]['category_slot_changed'][$category_id] = new ChangeLogEntry(
                        id: null,
                        entity_type: 'Participant',
                        entity_id: $log->entity_id,
                        group_id: $log->group_id,
                        change_type: 'category_slot_changed',
                        changed_at: $log->changed_at,
                        user_id: $log->user_id,
                        details: [
                           'category_id' => $category_id,
                           'slot_name'   => $log->details['slot_name'],
                           'from'        => $removed->details['slot_name'],
                        ],
                     );
                  /* drop both the 'add' and 'remove' logs */
                  $drop_for('category_removed');
                  $drop_for('category_added');
                  $stored_log = null;
               }
               break;

            case 'category_removed':
               /* check for previous 'added' */
               $added = $combined[$log->group_id][$log->entity_id]['category_added'][$category_id] ?? null;
               if( $added )
               {
                  /* if previous added exists, delete both add/remove logs */
                  $drop_for('category_removed');
                  $drop_for('category_added');
                  $stored_log = null;
               }
               /* also remove any 'slot_changed' log */
               $drop_for('category_slot_changed');
               break;

            case 'category_slot_changed':
               /* check for previous 'added' */
               if ($added = $combined[$log->group_id][$log->entity_id]['category_added'][$category_id]??null)
               {
                  /* update the addition instead, and drop this change */
                  $drop_for('category_slot_changed');
                  $stored_log = $added;
                  $stored_log->details['slot_name'] = $log->details['slot_name'];
               }
               else
               {
                  $stored_log->details['slot_name'] = $log->details['slot_name'];
                  if ($stored_log->details['slot_name'] === $stored_log->details['from'])
                  {
                     /* this is a change "back to previous slot" - drop the whole change log */
                     $drop_for('category_slot_changed');
                     $stored_log = null;
                  }
               }
               break;

            default:
               throw new \LogicException("unknown change log type '{$log->change_type}'");
         }

         /* take over meta data from latest change */
         if( $stored_log && $stored_log !== $log )
         {
            $stored_log->changed_at = $log->changed_at;
            $stored_log->user_id = $log->user_id;
            $stored_log->id = null; // this is no longer a changelog from the DB
         }
      }

      /* now collect all remaining log entries from the combined structure and flatten them
       * into a ChangeLogCollection, and order them again according change time */
      $result = static::new();
      array_walk_recursive($combined, function ($e) use ($result) { if( $e instanceof ChangeLogEntry ) $result[] = $e; });
      return $result->sorted();
   }

   /**
    * filter for change log entries only relevant for the provided category id
    */
   public function filter_category(int $category_id): static
   {
      return $this->filter(fn($e) => !array_key_exists('category_id', $e->details) || (int)$e->details['category_id'] === $category_id);
   }
}