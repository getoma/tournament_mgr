<?php

namespace Tests\Tournament\Model\MatchPointHandler;

use DateTime;
use PHPUnit\Framework\TestCase;
use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\MatchPointHandler\KendoMatchPointHandler;
use Tournament\Model\MatchRecord\MatchPoint;
use Tournament\Model\MatchRecord\MatchRecord;
use Tournament\Model\Participant\Participant;

class KendoMatchPointHandlerTest extends TestCase
{
   private Participant $redP;
   private Participant $whiteP;

   protected function setUp(): void
   {
      $this->redP   = new Participant(1, 1, '', '');
      $this->whiteP = new Participant(2, 1, '', '');
   }

   private function createMatchRecord(int $id = 1): MatchRecord
   {
      return new MatchRecord(
         id: $id,
         name: "test",
         category: $this->createStub(Category::class),
         area: $this->createStub(Area::class),
         redParticipant: $this->redP,
         whiteParticipant: $this->whiteP,
      );
   }

   public function testPoints(): void
   {
      $unit = new KendoMatchPointHandler();
      $rec = $this->createMatchRecord();

      /* add M for red */
      $ptM = new MatchPoint(id: null, point: 'M', given_at: new \DateTime(), participant: $rec->redParticipant);
      $this->assertTrue( $unit->addPoint($rec, $ptM) );
      $this->assertCount(1, $rec->points);
      $this->assertEquals($ptM, $rec->points->back());
      $this->assertNull($unit->getWinner($rec));
      $this->assertFalse($unit->isDecided($rec));

      /* try to add the same point again */
      $this->assertFalse( $unit->addPoint($rec, $ptM) );

      /* add K for white */
      $ptK = new MatchPoint(id: 1, point: 'K', given_at: new \DateTime(), participant: $rec->whiteParticipant);
      $this->assertTrue($unit->addPoint($rec, $ptK));
      $this->assertCount(2, $rec->points);
      $this->assertEquals($ptK, $rec->points->back());
      $this->assertNull($unit->getWinner($rec));
      $this->assertFalse($unit->isDecided($rec));

      /* add D for red */
      $ptD = new MatchPoint(id: 2, point: 'D', given_at: new \DateTime(), participant: $rec->redParticipant);
      $this->assertTrue($unit->addPoint($rec, $ptD));
      $this->assertCount(3, $rec->points);
      $this->assertEquals($ptD, $rec->points->back());
      $this->assertEquals($rec->redParticipant, $unit->getWinner($rec));
      $this->assertTrue($unit->isDecided($rec));

      /* try to add T for white */
      $ptT = new MatchPoint(id: 3, point: 'T', given_at: new \DateTime(), participant: $rec->whiteParticipant);
      $this->assertFalse($unit->addPoint($rec, $ptT));
      $this->assertCount(3, $rec->points);
      $this->assertEquals($ptD, $rec->points->back());

      /* remove D again */
      $this->assertTrue($unit->removePoint($rec, $ptD));
      $this->assertCount(2, $rec->points);
      $this->assertNull($unit->getWinner($rec));
      $this->assertFalse($unit->isDecided($rec));

      /* add T for white */
      $this->assertTrue($unit->addPoint($rec, $ptT));
      $this->assertCount(3, $rec->points);
      $this->assertEquals($ptT, $rec->points->back());
      $this->assertEquals($rec->whiteParticipant, $unit->getWinner($rec));
      $this->assertTrue($unit->isDecided($rec));

      /* remove T again, by id */
      $this->assertTrue($unit->removePoint($rec, 3));
      $this->assertCount(2, $rec->points);
      $this->assertNull($unit->getWinner($rec));
      $this->assertFalse($unit->isDecided($rec));

      /* remove an earlier point, by id */
      $this->assertTrue($unit->removePoint($rec, 1));
      $this->assertCount(1, $rec->points);
      $this->assertEquals($ptM, $rec->points->back());

      /* try to remove it again */
      $this->assertTrue($unit->removePoint($rec, 1)); // still true, because there is no such point afterwards
      $this->assertCount(1, $rec->points);
      $this->assertEquals($ptM, $rec->points->back());
   }

   public function testInvalid(): void
   {
      $unit = new KendoMatchPointHandler();
      $rec = $this->createMatchRecord();

      /* add non-existing point for red */
      $ptE = new MatchPoint(id: null, point: 'E', given_at: new \DateTime(), participant: $rec->redParticipant);
      $this->assertFalse($unit->addPoint($rec, $ptE));
      $this->assertEmpty($rec->points);

      /* add point for invalid participant */
      $p = new Participant(100, 1, '', '');
      $ptP = new MatchPoint(id: null, point: 'M', given_at: new \DateTime(), participant: $p);
      $this->assertFalse($unit->addPoint($rec, $ptP));
      $this->assertEmpty($rec->points);
   }

   public function testPenalty(): void
   {
      $unit = new KendoMatchPointHandler();
      $rec = $this->createMatchRecord();

      /* add H for red */
      $ptHR1 = new MatchPoint(id: null, point: 'H', given_at: new \DateTime(), participant: $rec->redParticipant);
      $this->assertTrue($unit->addPoint($rec, $ptHR1));
      $this->assertCount(1, $rec->points);
      $this->assertCount(1, $unit->getActivePenalties($rec));

      /* add H for white */
      $ptHW1 = new MatchPoint(id: null, point: 'H', given_at: new \DateTime(), participant: $rec->whiteParticipant);
      $this->assertTrue($unit->addPoint($rec, $ptHW1));
      $this->assertCount(2, $rec->points);
      $this->assertCount(2, $unit->getActivePenalties($rec));

      /* add another H for red - will result in I for white */
      $ptHR2 = new MatchPoint(id: null, point: 'H', given_at: new \DateTime(), participant: $rec->redParticipant);
      $this->assertTrue($unit->addPoint($rec, $ptHR2));
      $this->assertCount(4, $rec->points);
      $this->assertEquals('I', $rec->points->back()->point);
      $this->assertFalse($unit->isDecided($rec));
      $this->assertCount(1, $unit->getActivePenalties($rec));

      /* remove the last H again, which should also remove the I */
      $this->assertTrue($unit->removePoint($rec, $ptHR2));
      $this->assertCount(2, $rec->points);
      $this->assertCount(2, $unit->getActivePenalties($rec));

      /* add H for white */
      $ptHW2 = new MatchPoint(id: null, point: 'H', given_at: new \DateTime(), participant: $rec->whiteParticipant);
      $this->assertTrue($unit->addPoint($rec, $ptHW2));
      $this->assertCount(4, $rec->points);
      $this->assertEquals('I', $rec->points->back()->point);
      $this->assertFalse($unit->isDecided($rec));
      $this->assertCount(1, $unit->getActivePenalties($rec));

      /* add another H for white */
      $ptHW3 = new MatchPoint(id: null, point: 'H', given_at: new \DateTime(), participant: $rec->whiteParticipant);
      $this->assertTrue($unit->addPoint($rec, $ptHW3));
      $this->assertCount(5, $rec->points);
      $this->assertFalse($unit->isDecided($rec));
      $this->assertCount(2, $unit->getActivePenalties($rec));

      /* add a normal point for red, which should make him a winner */
      $ptRM = new MatchPoint(id: null, point: 'M', given_at: new \DateTime(), participant: $rec->redParticipant);
      $this->assertTrue($unit->addPoint($rec, $ptRM));
      $this->assertCount(6, $rec->points);
      $this->assertEquals($ptRM, $rec->points->back());
      $this->assertEquals($rec->redParticipant, $unit->getWinner($rec));
   }
}