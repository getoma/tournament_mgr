<?php

declare(strict_types=1);

namespace Tests\Tournament\Service;

use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\Participant\Participant;
use Tournament\Model\Participant\ParticipantChangeLog;
use Tournament\Model\TournamentStructure\KoTree;
use Tournament\Model\TournamentStructure\MatchNode\SoloKoMatch;
use Tournament\Model\TournamentStructure\MatchParticipant\MatchParticipantCollection;
use Tournament\Model\TournamentStructure\MatchSlot\ParticipantSlot;
use Tournament\Model\TournamentStructure\Pool\Pool;
use Tournament\Repository\ParticipantRepository;
use Tournament\Service\ChangeLogEvaluationService;

use Base\Model\ChangeLogEntry;
use Base\Repository\ChangeLogRepository;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ChangeLogEvaluationServiceTest extends TestCase
{
   private ChangeLogRepository&MockObject $changeLogRepo;
   private ParticipantRepository&MockObject $participantRepo;
   private ChangeLogEvaluationService $service;

   protected function setUp(): void
   {
      $this->changeLogRepo = $this->createMock(ChangeLogRepository::class);
      $this->participantRepo = $this->createMock(ParticipantRepository::class);
      $this->service = new ChangeLogEvaluationService($this->changeLogRepo, $this->participantRepo);
   }

   private function createCategory(int $categoryId = 1, int $tournamentId = 1): Category
   {
      return new Category($categoryId, $tournamentId, 'Test category', 'ko');
   }

   private function createParticipant(int $id, Category $category, string $slotName): Participant
   {
      $participant = new Participant($id, $category->tournament_id, 'Doe', 'John');
      $participant->setStartSlot($category, $slotName);
      return $participant;
   }

   private function createPool(string $name, Category $category, array $participants = []): Pool
   {
      $pool = new Pool($name, $category);
      if (!empty($participants))
      {
         $pool->loadParticipants(MatchParticipantCollection::new($participants));
      }
      return $pool;
   }

   private function createArea(int $areaId = 1, int $tournamentId = 1, string $name = 'Area 1'): Area
   {
      return new Area($areaId, $tournamentId, $name);
   }

   private function createKoTree(Category $category, ?Area $area = null): array
   {
      $participantA = new Participant(1, $category->tournament_id, 'Doe', 'John');
      $participantB = new Participant(2, $category->tournament_id, 'Foo', 'Bar');
      $slotRed = new ParticipantSlot($participantA);
      $slotWhite = new ParticipantSlot($participantB);
      $root = new SoloKoMatch('F1', $category, $slotRed, $slotWhite, $area);
      return [
         'tree' => new KoTree($root),
         'participants' => [$participantA, $participantB],
         'slot_names' => [$slotRed->getName(), $slotWhite->getName()],
      ];
   }

   private function createChangeLogEntry(string $changeType, array $details, int $entityId = 1, int $groupId = 1): ChangeLogEntry
   {
      return new ChangeLogEntry(
         id: null,
         entity_type: 'Participant',
         entity_id: $entityId,
         group_id: $groupId,
         change_type: $changeType,
         changed_at: new \DateTime(),
         user_id: null,
         details: $details,
      );
   }

   public function testGetChangesForPoolIncludesRenameForPoolParticipant(): void
   {
      $category = $this->createCategory();
      $participant = $this->createParticipant(1, $category, 'A.1');
      $pool = $this->createPool('A', $category, [$participant]);

      $this->changeLogRepo
         ->expects(self::once())
         ->method('getChangeLogsByGroupId')
         ->with($category->tournament_id)
         ->willReturn(ParticipantChangeLog::new([
            $this->createChangeLogEntry('rename', ['from' => 'Doe, John', 'to' => 'Doe, Jane'], 1),
         ]));

      $this->participantRepo
         ->expects(self::once())
         ->method('getParticipantById')
         ->with(1)
         ->willReturn($participant);

      $result = $this->service->getChangesForPool($pool);

      $this->assertCount(1, $result);
      $this->assertSame('rename', $result->first()->change_type);
      $this->assertSame($participant, $result->first()->details['participant']);
   }

   public function testGetChangesForPoolIgnoresRenameForNonPoolParticipant(): void
   {
      $category = $this->createCategory();
      $pool = $this->createPool('A', $category);

      $this->changeLogRepo
         ->expects(self::once())
         ->method('getChangeLogsByGroupId')
         ->with($category->tournament_id)
         ->willReturn(ParticipantChangeLog::new([
            $this->createChangeLogEntry('rename', ['from' => 'Doe, John', 'to' => 'Doe, Jane'], 2),
         ]));

      $this->participantRepo->expects(self::never())->method('getParticipantById');

      $result = $this->service->getChangesForPool($pool);

      $this->assertCount(0, $result);
   }

   public function testGetChangesForPoolConvertsSlotChangeToAddedWhenMovedIntoPool(): void
   {
      $category = $this->createCategory();
      $pool = $this->createPool('A', $category);
      $participant = $this->createParticipant(2, $category, 'B.1');

      $this->changeLogRepo
         ->expects(self::once())
         ->method('getChangeLogsByGroupId')
         ->with($category->tournament_id)
         ->willReturn(ParticipantChangeLog::new([
            $this->createChangeLogEntry('category_slot_changed', ['category_id' => 1, 'slot_name' => 'A.1', 'from' => 'B.1'], 2),
         ]));

      $this->participantRepo
         ->expects(self::once())
         ->method('getParticipantById')
         ->with(2)
         ->willReturn($participant);

      $result = $this->service->getChangesForPool($pool);

      $this->assertCount(1, $result);
      $this->assertSame('category_added', $result->first()->change_type);
      $this->assertSame('A.1', $result->first()->details['slot_name']);
      $this->assertSame('B.1', $result->first()->details['from']);
   }

   public function testGetChangesForPoolConvertsSlotChangeToRemovedWhenMovedOutOfPool(): void
   {
      $category = $this->createCategory();
      $pool = $this->createPool('A', $category);
      $participant = $this->createParticipant(3, $category, 'A.1');

      $this->changeLogRepo
         ->expects(self::once())
         ->method('getChangeLogsByGroupId')
         ->with($category->tournament_id)
         ->willReturn(ParticipantChangeLog::new([
            $this->createChangeLogEntry('category_slot_changed', ['category_id' => 1, 'slot_name' => 'B.1', 'from' => 'A.1'], 3),
         ]));

      $this->participantRepo
         ->expects(self::once())
         ->method('getParticipantById')
         ->with(3)
         ->willReturn($participant);

      $result = $this->service->getChangesForPool($pool);

      $this->assertCount(1, $result);
      $this->assertSame('category_removed', $result->first()->change_type);
      $this->assertSame('B.1', $result->first()->details['slot_name']);
   }

   public function testGetChangesForPoolIgnoresSlotChangeWithinSamePool(): void
   {
      $category = $this->createCategory();
      $participant = $this->createParticipant(4, $category, 'A.1');
      $pool = $this->createPool('A', $category, [$participant]);

      $this->changeLogRepo
         ->expects(self::once())
         ->method('getChangeLogsByGroupId')
         ->with($category->tournament_id)
         ->willReturn(ParticipantChangeLog::new([
            $this->createChangeLogEntry('category_slot_changed', ['category_id' => 1, 'slot_name' => 'A.2', 'from' => 'A.1'], 4),
         ]));

      $this->participantRepo->expects(self::never())->method('getParticipantById');

      $result = $this->service->getChangesForPool($pool);

      $this->assertCount(0, $result);
   }

   public function testGetChangesForKoTreeIncludesRenameForTreeParticipant(): void
   {
      $category = $this->createCategory();
      $treeData = $this->createKoTree($category);
      $tree = $treeData['tree'];
      $participant = $treeData['participants'][0];

      $this->changeLogRepo
         ->expects(self::once())
         ->method('getChangeLogsByGroupId')
         ->with($category->tournament_id)
         ->willReturn(ParticipantChangeLog::new([
            $this->createChangeLogEntry('rename', ['from' => 'Doe, John', 'to' => 'Doe, Jane'], 1),
         ]));

      $this->participantRepo
         ->expects(self::once())
         ->method('getParticipantById')
         ->with(1)
         ->willReturn($participant);

      $result = $this->service->getChangesForKoTree($tree);

      $this->assertCount(1, $result);
      $this->assertSame('rename', $result->first()->change_type);
      $this->assertSame($participant, $result->first()->details['participant']);
   }

   public function testGetChangesForKoTreeIgnoresChangeForDifferentCategory(): void
   {
      $category = $this->createCategory();
      $treeData = $this->createKoTree($category);
      $tree = $treeData['tree'];

      $this->changeLogRepo
         ->expects(self::once())
         ->method('getChangeLogsByGroupId')
         ->with($category->tournament_id)
         ->willReturn(ParticipantChangeLog::new([
            $this->createChangeLogEntry('rename', ['category_id' => 999, 'from' => 'Doe, John', 'to' => 'Doe, Jane'], 1),
         ]));

      $this->participantRepo->expects(self::never())->method('getParticipantById');

      $result = $this->service->getChangesForKoTree($tree);

      $this->assertCount(0, $result);
   }

   public function testGetChangesForKoTreeConvertsSlotChangeToAddedWhenMovedIntoTree(): void
   {
      $category = $this->createCategory();
      $treeData = $this->createKoTree($category);
      $tree = $treeData['tree'];
      $slotNames = $treeData['slot_names'];
      $participant = $this->createParticipant(3, $category, 'B.1');

      $this->changeLogRepo
         ->expects(self::once())
         ->method('getChangeLogsByGroupId')
         ->with($category->tournament_id)
         ->willReturn(ParticipantChangeLog::new([
            $this->createChangeLogEntry('category_slot_changed', ['category_id' => 1, 'slot_name' => $slotNames[0], 'from' => 'B.1'], 3),
         ]));

      $this->participantRepo
         ->expects(self::once())
         ->method('getParticipantById')
         ->with(3)
         ->willReturn($participant);

      $result = $this->service->getChangesForKoTree($tree);

      $this->assertCount(1, $result);
      $this->assertSame('category_added', $result->first()->change_type);
      $this->assertSame($slotNames[0], $result->first()->details['slot_name']);
      $this->assertArrayNotHasKey('from', $result->first()->details);
   }

   public function testGetChangesForKoTreeConvertsSlotChangeToRemovedWhenMovedOutOfTree(): void
   {
      $category = $this->createCategory();
      $treeData = $this->createKoTree($category);
      $tree = $treeData['tree'];
      $participant = $treeData['participants'][1];
      $slotNames = $treeData['slot_names'];

      $this->changeLogRepo
         ->expects(self::once())
         ->method('getChangeLogsByGroupId')
         ->with($category->tournament_id)
         ->willReturn(ParticipantChangeLog::new([
            $this->createChangeLogEntry('category_slot_changed', ['category_id' => 1, 'slot_name' => 'B.1', 'from' => $slotNames[1]], 2),
         ]));

      $this->participantRepo
         ->expects(self::once())
         ->method('getParticipantById')
         ->with(2)
         ->willReturn($participant);

      $result = $this->service->getChangesForKoTree($tree);

      $this->assertCount(1, $result);
      $this->assertSame('category_removed', $result->first()->change_type);
      $this->assertArrayNotHasKey('slot_name', $result->first()->details);
   }

   public function testGetChangesForKoTreeFiltersByAreaWhenAreaDoesNotMatch(): void
   {
      $category = $this->createCategory();
      $areaA = $this->createArea(1, $category->tournament_id, 'Area A');
      $areaB = $this->createArea(2, $category->tournament_id, 'Area B');
      $treeData = $this->createKoTree($category, $areaA);
      $tree = $treeData['tree'];

      $this->changeLogRepo
         ->expects(self::once())
         ->method('getChangeLogsByGroupId')
         ->with($category->tournament_id)
         ->willReturn(ParticipantChangeLog::new([
            $this->createChangeLogEntry('rename', ['from' => 'Doe, John', 'to' => 'Doe, Jane'], 1),
         ]));

      $this->participantRepo->expects(self::never())->method('getParticipantById');

      $result = $this->service->getChangesForKoTree($tree, $areaB);

      $this->assertCount(0, $result);
   }
}
