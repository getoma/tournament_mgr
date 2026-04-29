<?php declare(strict_types=1);

namespace Tests\Tournament\Model\TournamentStructure\Pool;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\Category\CategoryMode;
use Tournament\Model\MatchCreationHandler\MatchCreationHandler;
use Tournament\Model\MatchRecord\MatchRecord;
use Tournament\Model\MatchRecord\MatchRecordCollection;
use Tournament\Model\MatchRankHandler\MatchRank;
use Tournament\Model\MatchRankHandler\MatchRankCollection;
use Tournament\Model\MatchRankHandler\MatchRankHandler;
use Tournament\Model\TournamentStructure\MatchNode\MatchNode;
use Tournament\Model\TournamentStructure\MatchNode\MatchNodeCollection;
use Tournament\Model\TournamentStructure\MatchSlot\ParticipantSlot;
use Tournament\Model\TournamentStructure\Pool\Pool;
use Tournament\Model\TournamentStructure\MatchParticipant\MatchParticipantCollection;
use Tournament\Model\TournamentStructure\MatchParticipant\DummyMatchParticipant;

use Tests\CallSpy;
use Tests\IsSameParticipantList;
use Tests\Tournament\Model\TestStubs\TestMatchNode;
use Tests\Tournament\Model\TestStubs\TestMatchParticipant;

class PoolTest extends TestCase
{
   /**
    * This class simulates typical scenarios for pools with 3 participants
    */

   /**
    * Mocks for dependency injection to Pool
    */
   private MockObject $rankHdl;
   private MockObject $matchHdl;
   private Category $category;

   const POOL_NAME = "dut";

   protected function setUp(): void
   {
      $this->rankHdl  = $this->createMock(MatchRankHandler::class);
      $this->matchHdl = $this->createMock(MatchCreationHandler::class);

      $category = $this->getStubBuilder(Category::class)
         ->enableOriginalConstructor()
         ->setConstructorArgs([1, 1, 'test', CategoryMode::Combined])
         ->getStub();
      $category->method('getMatchRankHandler')->willReturn($this->rankHdl);
      $category->method('getMatchCreationHandler')->willReturn($this->matchHdl);
      $this->category = $category;
   }

   private function createParticipantList(int $num = 3): MatchParticipantCollection
   {
      return new MatchParticipantCollection(array_map( fn($i) => new TestMatchParticipant($i, 'Not a Dummy'), range(1, $num) ) );
   }

   private function generateMatches(MatchParticipantCollection $plist): MatchNodeCollection
   {
      $pcount = $plist->count();
      $parr = $plist->values();
      $res = MatchNodeCollection::new();
      $matchid = 1;
      for ($i = 0; $i < $pcount; ++$i)
      {
         for ($j = $i + 1; $j < $pcount; ++$j)
         {
            $red   = new ParticipantSlot($parr[$i]);
            $white = new ParticipantSlot($parr[$j]);
            $res[] = new TestMatchNode(strval($matchid++), $red, $white);
         }
      }

      return $res;
   }

   private function setMatchHdlExpectation(MatchParticipantCollection $plist, int $count = 1): MatchNodeCollection
   {
      $res = $this->generateMatches($plist);

      $this->matchHdl->expects($this->exactly($count))->method('generate')
      ->with(IsSameParticipantList::like($plist))
      ->willReturn($res);

      return $res;
   }

   private function createMatchRecords(MatchNodeCollection $matches, bool $lastOngoing = false): MatchRecordCollection
   {
      $records = MatchRecordCollection::new();
      foreach ($matches as $m)
      {
         /** @var MatchNode $m */
         $record = $this->createStub(MatchRecord::class);
         $record->method('getMatchName')->willReturn($m->getName());
         /*$record->method('getParticipant')->willReturnMap([
            MatchSide::RED, $m->getRedParticipant(),
            MatchSide::WHITE, $m->getWhiteParticipant()
         ]);*/
         if (!$lastOngoing || $m !== $matches->last())
         {
            $record->method('getWinner')->willReturn($m->getRedParticipant());
            $record->method('isFinalized')->willReturn(true);
         }
         $records[] = $record;
      }
      return $records;
   }

   public static function numParticipantsProvider()
   {
      foreach( range(2,5) as $cnt )
      {
         yield "$cnt Participants" => [$cnt];
      }
   }

   /**
    * test correct interface behavior when no participants added, yet
    */
   public function testEmptyPool()
   {
      $this->matchHdl->expects($this->never())->method('generate');
      $dut = new Pool(self::POOL_NAME, $this->category);
      $this->assertEquals(self::POOL_NAME, $dut->getName());
      $this->assertNull($dut->getArea());
      $this->assertEmpty($dut->getParticipants());
      $this->assertEmpty($dut->getMatchList());
      $this->rankHdl->expects($this->once())->method('derivePoolRanking')->with($this->identicalTo($dut))->willReturn(MatchRankCollection::new());
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
    */
   #[DataProvider('numParticipantsProvider')]
   public function testFreshPool(int $numParticipants)
   {
      $dut = new Pool(self::POOL_NAME, $this->category);
      $plist = $this->createParticipantList($numParticipants);
      $matches = $this->setMatchHdlExpectation($plist);
      $dut->addParticipants($plist);

      $this->assertEquals(self::POOL_NAME, $dut->getName());
      $this->assertNull($dut->getArea());
      foreach ($dut->getMatchList() as $m)
      {
         $this->assertNull($m->getArea());
      }
      $this->assertCount($plist->count(), $dut->getParticipants());
      $this->assertCount($matches->count(), $dut->getMatchList());
      $this->assertFalse($dut->isConducted());
      $this->assertFalse($dut->isDecided());
      $this->rankHdl->expects($this->once())->method('derivePoolRanking')->with($this->identicalTo($dut))->willReturn(MatchRankCollection::new());
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
         $this->assertSame($area, $m->getArea());
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
    */
   #[DataProvider('numParticipantsProvider')]
   public function testStartedPool(int $numParticipants = 2)
   {
      $dut = new Pool(self::POOL_NAME, $this->category);
      $plist = $this->createParticipantList($numParticipants);
      $matches = $this->setMatchHdlExpectation($plist);
      $dut->addParticipants($plist);

      /**
       * create a match record for the first match
       */
      $records = MatchRecordCollection::new();
      $records[] = $this->createConfiguredStub(MatchRecord::class, [
         'getMatchName' => $matches->first()->getName(),
         'isFinalized'  => false,
      ]);
      $dut->setMatchRecords($records);
      $this->assertNotNull($matches[0]->getMatchRecord());

      /**
       * create the pool ranking as it would be returned by the pool rank calculator (everyone on first place)
       */
      $ranks = MatchRankCollection::new( array_map( fn($p) => new MatchRank($p, 1), $plist->values() ) );
      $this->rankHdl->expects($this->once())->method('derivePoolRanking')->with($this->identicalTo($dut))->willReturn($ranks);

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
    */
   #[DataProvider('numParticipantsProvider')]
   public function testOngoingPool(int $numParticipants)
   {
      $dut = new Pool(self::POOL_NAME, $this->category);
      $plist = $this->createParticipantList($numParticipants);
      $matches = $this->setMatchHdlExpectation($plist);
      $dut->addParticipants($plist);
      $dut->setMatchRecords($this->createMatchRecords($matches, lastOngoing: true));

      /**
       * create the pool ranking as it would be returned by the pool rank calculator (everyone already on a specific place)
       */
      $place = 1;
      $ranks = MatchRankCollection::new(array_map(fn($p) => new MatchRank($p, $place++), $plist->values()));
      $this->rankHdl->expects($this->once())->method('derivePoolRanking')->with($this->identicalTo($dut))->willReturn($ranks);

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
    */
   #[DataProvider('numParticipantsProvider')]
   public function testDecidedPool(int $numParticipants)
   {
      $dut = new Pool(self::POOL_NAME, $this->category);
      $plist = $this->createParticipantList($numParticipants);
      $matches = $this->setMatchHdlExpectation($plist);
      $dut->addParticipants($plist);
      $dut->setMatchRecords($this->createMatchRecords($matches));

      /**
       * create the pool ranking as it would be returned by the pool rank calculator (everyone already on a specific place)
       */
      $ranks = MatchRankCollection::new(array_map(fn($p, $place) => new MatchRank($p, $place), $plist->values(), range(1,$plist->count())));
      $this->rankHdl->expects($this->once())->method('derivePoolRanking')->with($this->identicalTo($dut))->willReturn($ranks);

      $this->assertTrue($dut->isConducted());
      $this->assertTrue($dut->isDecided());
      $this->assertEquals($ranks, $dut->getRanking());
      $this->assertSame($plist->first(), $dut->getRanked(1));
      $this->assertFalse($dut->needsDecisionRound());
      $this->assertNull($dut->getCurrentDecisionRound());
      $this->assertEmpty($dut->getDecisionMatches());
   }

   /**
    * decided pool with a needed tie break
    * REMARK: now uses CallSpy as number of calls to mpHdl are dynamically constructed,
    *         which is not natively supported by phpunit
    */
   #[DataProvider('numParticipantsProvider')]
   public function testTieBreakPool(int $numParticipants=3)
   {
      $dut = new Pool(self::POOL_NAME, $this->category);

      $spy = new CallSpy();
      $this->matchHdl->expects($this->atLeastOnce())->method('generate')->willReturnCallback($spy->callback('generate'));

      $plist = $this->createParticipantList($numParticipants);
      $matches = $this->generateMatches($plist);
      $spy->addReturn($matches);
      $dut->addParticipants($plist);
      $dut->setMatchRecords($this->createMatchRecords($matches));

      $this->assertEquals(1, $spy->count('generate'));
      $calls = $spy->callsOf('generate')[0];
      [$pl] = $calls['args'];
      $this->assertThat($pl, IsSameParticipantList::like($plist));

      /**
       * create a pool ranking where the first place is not decided, yet
       */
      $spy->clear();
      $ranks = MatchRankCollection::new(array_map(fn($p, $place) => new MatchRank($p, $place), $plist->values(),
                                       array_merge([1,1], ($plist->count()>2? range(2,$plist->count()-1) : [])) ));
      $decision_participants = $plist->slice(0,2);
      $this->rankHdl->expects($this->once())->method('derivePoolRanking')->with($this->identicalTo($dut))->willReturn($ranks);

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
      [$pl] = $calls['args'];
      $this->assertEquals($decision_participants->values(), $pl->values());

      $this->assertEquals($decision_matches, $decision_nodes);
      $this->assertNotNull($dut->getCurrentDecisionRound());
      $this->assertEquals($decision_matches->values(), $dut->getDecisionMatches()->values());
   }

   /**
    * make sure reloading a pool from the DB yields the same results again
    */
   #[DataProvider('numParticipantsProvider')]
   public function testReproducability(int $numParticipants = 3)
   {
      $this->rankHdl->expects($this->never())->method('derivePoolRanking');
      $plist = $this->createParticipantList($numParticipants);
      $this->setMatchHdlExpectation($plist,2); // will check whether match creation is called with the same amount and order of participants both times

      $dut = new Pool(self::POOL_NAME, $this->category);
      $dut->addParticipants($plist);

      $dut2 = new Pool(self::POOL_NAME, $this->category);
      $dut2->loadParticipants($plist->reverse());
   }

   /**
    * make sure pool handles slot holes in participants correctly
    */
   #[DataProvider('numParticipantsProvider')]
   public function testSlotHandling(int $numParticipants = 3)
   {
      $this->rankHdl->expects($this->never())->method('derivePoolRanking');
      $spy = new CallSpy();
      $this->matchHdl->expects($this->atLeastOnce())->method('generate')->willReturnCallback($spy->callback('generate'));

      $plist = $this->createParticipantList($numParticipants);
      $matches = $this->generateMatches($plist);
      $spy->addReturn($matches);

      $dut = new Pool(self::POOL_NAME, $this->category);
      $dut->addParticipants($plist);

      $this->assertEquals(1, $spy->count('generate'));
      $calls = $spy->callsOf('generate')[0];
      [$pl] = $calls['args'];
      $this->assertThat($pl, IsSameParticipantList::like($plist));
      $spy->clear();

      /* remove a participant from the "middle" */
      $remidx = intdiv($numParticipants-1, 2);
      $first = array_slice($plist->values(), 0, $remidx);
      $last = array_slice($plist->values(), $remidx+1);
      $mplist = MatchParticipantCollection::new( array_merge($first, [new DummyMatchParticipant(false)], $last) );
      $pplist = MatchParticipantCollection::new( array_merge($last, $first) ); // also change order

      $matches_gen = $this->generateMatches($mplist);
      $spy->addReturn($matches_gen);

      $dut2 = new Pool(self::POOL_NAME, $this->category);
      $dut2->loadParticipants($pplist);

      $this->assertEquals(1, $spy->count('generate'));
      $calls = $spy->callsOf('generate')[0];
      [$pl] = $calls['args'];
      $this->assertThat($pl, IsSameParticipantList::like($mplist));

      $this->assertEquals($matches_gen->filter( fn($m) => $m->isReal() )->values(), $dut2->getMatchList()->values());
   }

}