<?php

namespace Tests\Tournament\Model\TournamentStructure\Pool;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\CallSpy;
use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\MatchCreationHandler\MatchCreationHandler;
use Tournament\Model\MatchPointHandler\MatchPointHandler;
use Tournament\Model\MatchRecord\MatchRecord;
use Tournament\Model\MatchRecord\MatchRecordCollection;
use Tournament\Model\Participant\Participant;
use Tournament\Model\Participant\ParticipantCollection;
use Tournament\Model\PoolRankHandler\PoolRank;
use Tournament\Model\PoolRankHandler\PoolRankCollection;
use Tournament\Model\PoolRankHandler\PoolRankHandler;
use Tournament\Model\TournamentStructure\MatchNode\MatchNode;
use Tournament\Model\TournamentStructure\MatchNode\MatchNodeCollection;
use Tournament\Model\TournamentStructure\MatchSlot\ParticipantSlot;
use Tournament\Model\TournamentStructure\Pool\Pool;
use Tournament\Model\TournamentStructure\TournamentStructureFactory;

class PoolTest extends TestCase
{
   /**
    * This class simulates typical scenarios for pools with 3 participants
    */

   /**
    * Mocks for dependency injection to Pool
    */
   private MockObject $rankHdl;
   private MockObject $strucHdl;
   private MockObject $matchHdl;

   const POOL_NAME = "dut";

   protected function setUp(): void
   {
      $this->rankHdl  = $this->createMock(PoolRankHandler::class);
      $this->strucHdl = $this->createMock(TournamentStructureFactory::class);
      $this->matchHdl = $this->createMock(MatchCreationHandler::class);
   }

   private function createParticipantList($num = 3): ParticipantCollection
   {
      $res = new ParticipantCollection();
      for( $i = 1; $i <= 3; ++$i )
      {
         $res[] = new Participant($i, 1, '', '');
      }
      return $res;
   }

   private function generateMatches(ParticipantCollection $plist): MatchNodeCollection
   {
      $pcount = $plist->count();
      $parr = $plist->values();
      $res = MatchNodeCollection::new();
      $matchid = 1;
      $mpHdl = $this->createStub(MatchPointHandler::class);
      for ($i = 0; $i < $pcount; ++$i)
      {
         for ($j = $i + 1; $j < $pcount; ++$j)
         {
            $red   = new ParticipantSlot($parr[$i]);
            $white = new ParticipantSlot($parr[$j]);
            $res[] = new MatchNode($matchid++, $red, $white, $mpHdl);
         }
      }

      return $res;
   }

   private function setMatchHdlExpectation(ParticipantCollection $plist): MatchNodeCollection
   {
      $res = $this->generateMatches($plist);

      $this->matchHdl->expects($this->once())->method('generate')
      ->with($this->identicalTo($plist), $this->identicalTo($this->strucHdl))
      ->willReturn($res);

      return $res;
   }

   private function createPool(): Pool
   {
      return new Pool(
         self::POOL_NAME,
         $this->rankHdl,
         $this->strucHdl,
         $this->matchHdl
      );
   }

   private function createMatchRecords(MatchNodeCollection $matches, bool $lastOngoing = false): MatchRecordCollection
   {
      $category = $this->createStub(Category::class);
      $area = $this->createStub(Area::class);
      $records = MatchRecordCollection::new();
      $m_id = 1;
      foreach ($matches as $m)
      {
         /** @var MatchNode $m */
         $record = new MatchRecord($m_id++, $m->getName(), $category, $area, $m->slotRed->getParticipant(), $m->slotWhite->getParticipant());
         if (!$lastOngoing || ($m_id < $matches->count()) )
         {
            $record->winner = $m->slotRed->getParticipant();
            $record->finalized_at = new \DateTime();
         }
         $records[] = $record;
      }
      return $records;
   }

   public static function numParticipantsProvider()
   {
      return [ [2], [3], [4], [5] ];
   }

   /**
    * test correct interface behavior when no participants added, yet
    */
   public function testEmptyPool()
   {
      $dut = $this->createPool();
      $this->assertEquals(self::POOL_NAME, $dut->getName());
      $this->assertNull($dut->getArea());
      $this->assertEmpty($dut->getParticipants());
      $this->assertEmpty($dut->getMatchList());
      $this->rankHdl->expects($this->once())->method('deriveRanking')->with($this->isEmpty())->willReturn(PoolRankCollection::new());
      $this->assertEmpty($dut->getRanking());
      $this->assertNull($dut->getRanked(1));
      $this->assertFalse($dut->isConducted());
      $this->assertFalse($dut->isDecided());
      $this->assertFalse($dut->needsDecisionRound());
      $this->assertNull($dut->getCurrentDecisionRound());
      $this->assertEmpty($dut->getDecisionMatches());

      $area = $this->createStub(Area::class);
      $dut->setArea($area);
      $this->assertSame($area, $dut->getArea());
   }

   /**
    * participants added, but no matches conducted, yet.
    * @dataProvider numParticipantsProvider
    */
   public function testFreshPool(int $numParticipants)
   {
      $dut = $this->createPool();
      $plist = $this->createParticipantList($numParticipants);
      $matches = $this->setMatchHdlExpectation($plist);
      $dut->setParticipants($plist);

      $this->assertEquals(self::POOL_NAME, $dut->getName());
      $this->assertNull($dut->getArea());
      foreach ($dut->getMatchList() as $m)
      {
         $this->assertNull($m->area);
      }
      $this->assertCount($plist->count(), $dut->getParticipants());
      $this->assertCount($matches->count(), $dut->getMatchList());
      $this->assertFalse($dut->isConducted());
      $this->assertFalse($dut->isDecided());
      $this->rankHdl->expects($this->once())->method('deriveRanking')->with($this->equalTo($matches))->willReturn(PoolRankCollection::new());
      $this->assertEmpty($dut->getRanking());
      $this->assertNull($dut->getRanked(1));
      $this->assertFalse($dut->needsDecisionRound());
      $this->assertNull($dut->getCurrentDecisionRound());
      $this->assertEmpty($dut->getDecisionMatches());

      $area = $this->createStub(Area::class);
      $dut->setArea($area);
      $this->assertSame($area, $dut->getArea());
      foreach( $dut->getMatchList() as $m )
      {
         $this->assertSame($area, $m->area);
      }

      /**
       * match names should
       * - be unique
       * - contain the pool name somehow to ensure uniqueness across pools
       */
      $names = [];
      foreach( $dut->getMatchList() as $m )
      {
         $this->assertStringContainsString(self::POOL_NAME, $m->getName());
         $this->assertNotContains($m->getName(), $names);
         $names[] = $m->getName();
      }
   }

   /**
    * only first match started
    * @dataProvider numParticipantsProvider
    */
   public function testStartedPool(int $numParticipants)
   {
      $dut = $this->createPool();
      $plist = $this->createParticipantList($numParticipants);
      $matches = $this->setMatchHdlExpectation($plist);
      $dut->setParticipants($plist);

      /**
       * create a match record for the first match
       */
      $records = MatchRecordCollection::new();
      $records[] = new MatchRecord(1, $matches[0]->getName(), $this->createStub(Category::class), $this->createStub(Area::class),
                     $plist[1], $plist[2]);
      $dut->setMatchRecords($records);
      $this->assertNotNull($matches[0]->getMatchRecord());

      /**
       * create the pool ranking as it would be returned by the pool rank calculator (everyone on first place)
       */
      $ranks = PoolRankCollection::new( array_map( fn($p) => new PoolRank($p, 1), $plist->values() ) );
      $this->rankHdl->expects($this->once())->method('deriveRanking')->with($this->equalTo($matches))->willReturn($ranks);

      $this->assertFalse($dut->isConducted());
      $this->assertFalse($dut->isDecided());
      $this->assertEquals($ranks, $dut->getRanking());
      $this->assertNull($dut->getRanked(1));
      $this->assertFalse($dut->needsDecisionRound());
      $this->assertNull($dut->getCurrentDecisionRound());
      $this->assertEmpty($dut->getDecisionMatches());
   }

   /**
    * last match ongoing
    * @dataProvider numParticipantsProvider
    */
   public function testOngoingPool(int $numParticipants)
   {
      $dut = $this->createPool();
      $plist = $this->createParticipantList($numParticipants);
      $matches = $this->setMatchHdlExpectation($plist);
      $dut->setParticipants($plist);
      $dut->setMatchRecords($this->createMatchRecords($matches, lastOngoing: true));

      /**
       * create the pool ranking as it would be returned by the pool rank calculator (everyone already on a specific place)
       */
      $place = 1;
      $ranks = PoolRankCollection::new(array_map(fn($p) => new PoolRank($p, $place++), $plist->values()));
      $this->rankHdl->expects($this->once())->method('deriveRanking')->with($this->equalTo($matches))->willReturn($ranks);

      $this->assertFalse($dut->isConducted());
      $this->assertFalse($dut->isDecided());
      $this->assertEquals($ranks, $dut->getRanking());
      $this->assertNull($dut->getRanked(1));
      $this->assertFalse($dut->needsDecisionRound());
      $this->assertNull($dut->getCurrentDecisionRound());
      $this->assertEmpty($dut->getDecisionMatches());
   }

   /**
    * fully decided pool
    * @dataProvider numParticipantsProvider
    */
   public function testDecidedPool(int $numParticipants)
   {
      $dut = $this->createPool();
      $plist = $this->createParticipantList($numParticipants);
      $matches = $this->setMatchHdlExpectation($plist);
      $dut->setParticipants($plist);
      $dut->setMatchRecords($this->createMatchRecords($matches));

      /**
       * create the pool ranking as it would be returned by the pool rank calculator (everyone already on a specific place)
       */
      $ranks = PoolRankCollection::new(array_map(fn($p, $place) => new PoolRank($p, $place), $plist->values(), range(1,$plist->count())));
      $this->rankHdl->expects($this->once())->method('deriveRanking')->with($this->equalTo($matches))->willReturn($ranks);

      $this->assertTrue($dut->isConducted());
      $this->assertTrue($dut->isDecided());
      $this->assertEquals($ranks, $dut->getRanking());
      $this->assertSame($plist->front(), $dut->getRanked(1));
      $this->assertFalse($dut->needsDecisionRound());
      $this->assertNull($dut->getCurrentDecisionRound());
      $this->assertEmpty($dut->getDecisionMatches());
   }

   /**
    * decided pool with a needed tie break
    * @dataProvider numParticipantsProvider
    * REMARK: now uses CallSpy as number of calls to mpHdl are dynamically constructed,
    *         which is not natively supported by phpunit
    */
   public function testTieBreakPool(int $numParticipants)
   {
      $dut = $this->createPool();

      $spy = new CallSpy();
      $this->matchHdl->method('generate')->willReturnCallback($spy->callback('generate'));

      $plist = $this->createParticipantList($numParticipants);
      $matches = $this->generateMatches($plist);
      $spy->addReturn($matches);
      $dut->setParticipants($plist);
      $dut->setMatchRecords($this->createMatchRecords($matches));

      $this->assertEquals(1, $spy->count('generate'));
      $calls = $spy->callsOf('generate')[0];
      [$pl, $str] = $calls['args'];
      $this->assertSame($plist, $pl);
      $this->assertSame($this->strucHdl, $str);

      /**
       * create the pool ranking where the first place is not decided, yet
       */
      $spy->clear();
      $ranks = PoolRankCollection::new(array_map(fn($p, $place) => new PoolRank($p, $place), $plist->values(),
                                       array_merge([1,1], range(2,$plist->count()-1)) ));
      $decision_participants = $plist->slice(0,2);
      $this->rankHdl->expects($this->once())->method('deriveRanking')->with($this->equalTo($matches))->willReturn($ranks);

      $this->assertTrue($dut->isConducted());
      $this->assertFalse($dut->isDecided());
      $this->assertEquals($ranks, $dut->getRanking());
      $this->assertNull($dut->getRanked(1));
      $this->assertTrue($dut->needsDecisionRound());
      $this->assertNull($dut->getCurrentDecisionRound());
      $this->assertEmpty($dut->getDecisionMatches());

      /**
       * create decision match
       */
      $decision_matches = $this->generateMatches($decision_participants);
      $spy->addReturn($decision_matches);
      $decision_nodes = $dut->createDecisionRound();

      $this->assertEquals(1, $spy->count('generate'));
      $calls = $spy->callsOf('generate')[0];
      [$pl, $str] = $calls['args'];
      $this->assertEquals($decision_participants, $pl);
      $this->assertSame($this->strucHdl, $str);

      $this->assertEquals($decision_matches, $decision_nodes);
      $this->assertNotNull($dut->getCurrentDecisionRound());
      $this->assertEquals($decision_matches, $dut->getDecisionMatches());
   }
}