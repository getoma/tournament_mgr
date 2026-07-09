<?php

declare(strict_types=1);

namespace Tests\Tournament\Model\Participant;

use Tournament\Model\Category\Category;
use Tournament\Model\Participant\Participant;
use Tournament\Model\Participant\ParticipantChangeLog;

use PHPUnit\Framework\TestCase;

class ParticipantChangeLogTest extends TestCase
{

   // ===== create() tests =====

   /**
    * helper: create a Participant instance with given content
    */
   private function createParticipant(
      int $id,
      int $tournamentId,
      string $lastname,
      string $firstname,
      ?string $club = null,
      bool $withdrawn = false,
      array $categories = []
   ): Participant
   {
      $participant = new Participant($id, $tournamentId, $lastname, $firstname, $club, $withdrawn);
      foreach ($categories as $categoryId => $slotName)
      {
         $category = new Category($categoryId, $tournamentId, 'Category ' . $categoryId);
         $participant->setStartSlot($category, $slotName);
      }

      return $participant;
   }

   /**
    * helper: Assert that a changelog entry with the given change_type and
    * details exists in the collection
    */
   private function assertChangeLogEntryExists(
      ParticipantChangeLog $collection,
      string $changeType,
      array $details,
      int $entityId,
      int $groupId
   ): void
   {
      $found = false;
      foreach ($collection as $entry)
      {
         if (
            $entry->change_type === $changeType
            && $entry->entity_id === $entityId
            && $entry->group_id === $groupId
            && $entry->details === $details
         )
         {
            $found = true;
            break;
         }
      }
      $this->assertTrue(
         $found,
         "Expected changelog entry with type '$changeType' and details " . json_encode($details) . " not found"
      );
   }

   public function testCreateReturnsEmptyCollectionForIdenticalParticipants(): void
   {
      $previous = $this->createParticipant(1, 1, 'Doe', 'John', 'Club A', false, [1 => 'A']);
      $current = $this->createParticipant(1, 1, 'Doe', 'John', 'Club A', false, [1 => 'A']);

      $result = ParticipantChangeLog::create($previous, $current);

      $this->assertCount(0, $result);
   }

   public function testCreateDetectsRenameClubAndWithdrawnStatusChange(): void
   {
      $previous = $this->createParticipant(1, 1, 'Doe', 'John', 'Club A', false, [1 => 'A']);
      $current = $this->createParticipant(1, 1, 'Doe', 'Jane', 'Club B', true, [1 => 'A']);

      $result = ParticipantChangeLog::create($previous, $current);

      $this->assertCount(3, $result);
      $this->assertChangeLogEntryExists(
         $result,
         'rename',
         ['from' => 'Doe, John', 'to' => 'Doe, Jane'],
         1,
         1
      );
      $this->assertChangeLogEntryExists(
         $result,
         'club_change',
         ['from' => 'Club A', 'to' => 'Club B'],
         1,
         1
      );
      $this->assertChangeLogEntryExists(
         $result,
         'withdrawn_status_change',
         ['from' => 'active', 'to' => 'withdrawn'],
         1,
         1
      );
   }

   public function testCreateDetectsCategoryAddedRemovedAndSlotChanged(): void
   {
      $previous = $this->createParticipant(1, 1, 'Doe', 'John', 'Club A', false, [1 => 'A', 2 => 'B']);
      $current = $this->createParticipant(1, 1, 'Doe', 'John', 'Club A', false, [2 => 'C', 3 => 'D']);

      $result = ParticipantChangeLog::create($previous, $current);

      $this->assertCount(3, $result);
      $this->assertChangeLogEntryExists(
         $result,
         'category_removed',
         ['category_id' => 1, 'slot_name' => 'A'],
         1,
         1
      );
      $this->assertChangeLogEntryExists(
         $result,
         'category_added',
         ['category_id' => 3, 'slot_name' => 'D'],
         1,
         1
      );
      $this->assertChangeLogEntryExists(
         $result,
         'category_slot_changed',
         ['category_id' => 2, 'slot_name' => 'C', 'from' => 'B'],
         1,
         1
      );
   }

   public function testCreateDetectsCategorySlotChangeOnly(): void
   {
      $previous = $this->createParticipant(1, 1, 'Doe', 'John', 'Club A', false, [1 => 'A']);
      $current = $this->createParticipant(1, 1, 'Doe', 'John', 'Club A', false, [1 => 'B']);

      $result = ParticipantChangeLog::create($previous, $current);

      $this->assertCount(1, $result);
      $this->assertChangeLogEntryExists(
         $result,
         'category_slot_changed',
         ['category_id' => 1, 'slot_name' => 'B', 'from' => 'A'],
         1,
         1
      );
   }

   // ===== compress() tests =====

   /**
    * Helper: create a ChangeLogEntry with given parameters
    */
   private function createChangeLogEntry(
      string $changeType,
      array $details,
      int $entityId = 1,
      int $groupId = 1,
      ?int $id = null
   ): \Base\Model\ChangeLogEntry
   {
      return new \Base\Model\ChangeLogEntry(
         id: $id,
         entity_type: 'Participant',
         entity_id: $entityId,
         group_id: $groupId,
         change_type: $changeType,
         details: $details
      );
   }

   public function testCompressEmptyCollectionReturnsEmpty(): void
   {
      $log = ParticipantChangeLog::new();
      $result = $log->compress();
      $this->assertCount(0, $result);
   }

   public function testCompressMultipleRenamesCompressesToLatest(): void
   {
      $log = ParticipantChangeLog::new([
         $this->createChangeLogEntry('rename', ['from' => 'John', 'to' => 'Jane'], id: 1),
         $this->createChangeLogEntry('rename', ['from' => 'Jane', 'to' => 'Jane Smith'], id: 2),
      ]);

      $result = $log->compress();

      $this->assertCount(1, $result);
      $this->assertChangeLogEntryExists(
         $result,
         'rename',
         ['from' => 'John', 'to' => 'Jane Smith'],
         1,
         1
      );
   }

   public function testCompressMultipleClubChangesCompressesToLatest(): void
   {
      $log = ParticipantChangeLog::new([
         $this->createChangeLogEntry('club_change', ['from' => 'Club A', 'to' => 'Club B'], id: 1),
         $this->createChangeLogEntry('club_change', ['from' => 'Club B', 'to' => 'Club C'], id: 2),
      ]);

      $result = $log->compress();

      $this->assertCount(1, $result);
      $this->assertChangeLogEntryExists(
         $result,
         'club_change',
         ['from' => 'Club A', 'to' => 'Club C'],
         1,
         1
      );
   }

   public function testCompressRevertedRenameIsDropped(): void
   {
      $log = ParticipantChangeLog::new([
         $this->createChangeLogEntry('rename', ['from' => 'John', 'to' => 'Jane'], id: 1),
         $this->createChangeLogEntry('rename', ['from' => 'Jane', 'to' => 'John'], id: 2),
      ]);

      $result = $log->compress();

      $this->assertCount(0, $result);
   }

   public function testCompressRevertedWithdrawnStatusIsDropped(): void
   {
      $log = ParticipantChangeLog::new([
         $this->createChangeLogEntry('withdrawn_status_change', ['from' => 'active', 'to' => 'withdrawn'], id: 1),
         $this->createChangeLogEntry('withdrawn_status_change', ['from' => 'withdrawn', 'to' => 'active'], id: 2),
      ]);

      $result = $log->compress();

      $this->assertCount(0, $result);
   }

   public function testCompressCategoryAddThenRemoveIsCancelled(): void
   {
      $log = ParticipantChangeLog::new([
         $this->createChangeLogEntry('category_added', ['category_id' => 1, 'slot_name' => 'A'], id: 1),
         $this->createChangeLogEntry('category_removed', ['category_id' => 1, 'slot_name' => 'A'], id: 2),
      ]);

      $result = $log->compress();

      $this->assertCount(0, $result);
   }

   public function testCompressCategoryRemoveThenAddBecomesSlotChange(): void
   {
      $log = ParticipantChangeLog::new([
         $this->createChangeLogEntry('category_removed', ['category_id' => 1, 'slot_name' => 'A'], id: 1),
         $this->createChangeLogEntry('category_added', ['category_id' => 1, 'slot_name' => 'B'], id: 2),
      ]);

      $result = $log->compress();

      $this->assertCount(1, $result);
      $this->assertChangeLogEntryExists(
         $result,
         'category_slot_changed',
         ['category_id' => 1, 'slot_name' => 'B', 'from' => 'A'],
         1,
         1
      );
   }

   public function testCompressCategoryAddThenSlotChangeCompressesToAdd(): void
   {
      $log = ParticipantChangeLog::new([
         $this->createChangeLogEntry('category_added', ['category_id' => 1, 'slot_name' => 'A'], id: 1),
         $this->createChangeLogEntry('category_slot_changed', ['category_id' => 1, 'slot_name' => 'B', 'from' => 'A'], id: 2),
      ]);

      $result = $log->compress();

      $this->assertCount(1, $result);
      $this->assertChangeLogEntryExists(
         $result,
         'category_added',
         ['category_id' => 1, 'slot_name' => 'B'],
         1,
         1
      );
   }

   public function testCompressMultipleSlotChangesCompressesToFirstAndLast(): void
   {
      $log = ParticipantChangeLog::new([
         $this->createChangeLogEntry('category_slot_changed', ['category_id' => 1, 'slot_name' => 'B', 'from' => 'A'], id: 1),
         $this->createChangeLogEntry('category_slot_changed', ['category_id' => 1, 'slot_name' => 'C', 'from' => 'B'], id: 2),
      ]);

      $result = $log->compress();

      $this->assertCount(1, $result);
      $this->assertChangeLogEntryExists(
         $result,
         'category_slot_changed',
         ['category_id' => 1, 'slot_name' => 'C', 'from' => 'A'],
         1,
         1
      );
   }

   public function testCompressSlotChangeRevertedToOriginalIsDropped(): void
   {
      $log = ParticipantChangeLog::new([
         $this->createChangeLogEntry('category_slot_changed', ['category_id' => 1, 'slot_name' => 'B', 'from' => 'A'], id: 1),
         $this->createChangeLogEntry('category_slot_changed', ['category_id' => 1, 'slot_name' => 'A', 'from' => 'B'], id: 2),
      ]);

      $result = $log->compress();

      $this->assertCount(0, $result);
   }

   public function testCompressMultipleEntitiesAreIsolated(): void
   {
      $log = ParticipantChangeLog::new([
         $this->createChangeLogEntry('rename', ['from' => 'John', 'to' => 'Jane'], entityId: 1, id: 1),
         $this->createChangeLogEntry('rename', ['from' => 'Jane', 'to' => 'John'], entityId: 1, id: 2),
         $this->createChangeLogEntry('rename', ['from' => 'Bob', 'to' => 'Alice'], entityId: 2, id: 3),
      ]);

      $result = $log->compress();

      $this->assertCount(1, $result);
      $this->assertChangeLogEntryExists(
         $result,
         'rename',
         ['from' => 'Bob', 'to' => 'Alice'],
         2,
         1
      );
   }

   public function testCompressMultipleCategoriesAreIsolated(): void
   {
      $log = ParticipantChangeLog::new([
         $this->createChangeLogEntry('category_added', ['category_id' => 1, 'slot_name' => 'A'], id: 1),
         $this->createChangeLogEntry('category_removed', ['category_id' => 1, 'slot_name' => 'A'], id: 2),
         $this->createChangeLogEntry('category_added', ['category_id' => 2, 'slot_name' => 'B'], id: 3),
      ]);

      $result = $log->compress();

      $this->assertCount(1, $result);
      $this->assertChangeLogEntryExists(
         $result,
         'category_added',
         ['category_id' => 2, 'slot_name' => 'B'],
         1,
         1
      );
   }

   public function testCompressMultipleGroupsAreIsolated(): void
   {
      $log = ParticipantChangeLog::new([
         $this->createChangeLogEntry('rename', ['from' => 'John', 'to' => 'Jane'], groupId: 1, id: 1),
         $this->createChangeLogEntry('rename', ['from' => 'Jane', 'to' => 'John'], groupId: 1, id: 2),
         $this->createChangeLogEntry('rename', ['from' => 'Bob', 'to' => 'Alice'], groupId: 2, id: 3),
      ]);

      $result = $log->compress();

      $this->assertCount(1, $result);
      $this->assertChangeLogEntryExists(
         $result,
         'rename',
         ['from' => 'Bob', 'to' => 'Alice'],
         1,
         2
      );
   }

   public function testCompressMetadataUpdatedToLatest(): void
   {
      $now = new \DateTime();
      $later = new \DateTime('+1 hour');
      $log = ParticipantChangeLog::new([
         $this->createChangeLogEntry('rename', ['from' => 'John', 'to' => 'Jane'], id: 100),
         $this->createChangeLogEntry('rename', ['from' => 'Jane', 'to' => 'Jane Smith'], id: 200),
      ]);
      $log[0]->changed_at = $now;
      $log[0]->user_id = 5;
      $log[1]->changed_at = $later;
      $log[1]->user_id = 10;

      $result = $log->compress();

      $this->assertCount(1, $result);
      $entry = $result->first();
      $this->assertEquals($later, $entry->changed_at);
      $this->assertSame(10, $entry->user_id);
      $this->assertNull($entry->id);
   }

   public function testCompressComplexMultiStepCategoryScenario(): void
   {
      $log = ParticipantChangeLog::new([
         $this->createChangeLogEntry('category_added', ['category_id' => 1, 'slot_name' => 'A'], id: 1),
         $this->createChangeLogEntry('category_slot_changed', ['category_id' => 1, 'slot_name' => 'B', 'from' => 'A'], id: 2),
         $this->createChangeLogEntry('category_removed', ['category_id' => 1, 'slot_name' => 'B'], id: 3),
         $this->createChangeLogEntry('category_added', ['category_id' => 1, 'slot_name' => 'C'], id: 4),
      ]);

      $result = $log->compress();

      $this->assertCount(1, $result);
      $this->assertChangeLogEntryExists(
         $result,
         'category_added',
         ['category_id' => 1, 'slot_name' => 'C'],
         1,
         1
      );
   }

   public function testCompressRemovalDropsRelatedSlotChanges(): void
   {
      $log = ParticipantChangeLog::new([
         $this->createChangeLogEntry('category_slot_changed', ['category_id' => 1, 'slot_name' => 'B', 'from' => 'A'], id: 1),
         $this->createChangeLogEntry('category_removed', ['category_id' => 1, 'slot_name' => 'B'], id: 2),
      ]);

      $result = $log->compress();

      $this->assertCount(1, $result);
      $this->assertChangeLogEntryExists(
         $result,
         'category_removed',
         ['category_id' => 1, 'slot_name' => 'B'],
         1,
         1
      );
   }

   public function testCompressMixedChangeTypesPreserved(): void
   {
      $log = ParticipantChangeLog::new([
         $this->createChangeLogEntry('rename', ['from' => 'John', 'to' => 'Jane'], id: 1),
         $this->createChangeLogEntry('club_change', ['from' => 'Club A', 'to' => 'Club B'], id: 2),
         $this->createChangeLogEntry('category_added', ['category_id' => 1, 'slot_name' => 'A'], id: 3),
      ]);

      $result = $log->compress();

      $this->assertCount(3, $result);
      $this->assertChangeLogEntryExists(
         $result,
         'rename',
         ['from' => 'John', 'to' => 'Jane'],
         1,
         1
      );
      $this->assertChangeLogEntryExists(
         $result,
         'club_change',
         ['from' => 'Club A', 'to' => 'Club B'],
         1,
         1
      );
      $this->assertChangeLogEntryExists(
         $result,
         'category_added',
         ['category_id' => 1, 'slot_name' => 'A'],
         1,
         1
      );
   }
}
