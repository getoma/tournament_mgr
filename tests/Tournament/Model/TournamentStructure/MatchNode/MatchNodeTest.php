<?php

namespace Tests\Tournament\Model\TournamentStructure\MatchNode;

use PHPUnit\Framework\TestCase;

use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\MatchRecord\MatchRecord;
use Tournament\Model\Participant\Participant;
use Tournament\Model\TournamentStructure\MatchSlot\MatchSlot;
use Tournament\Model\TournamentStructure\MatchNode\MatchNode;

class MatchNodeTest extends TestCase
{
   /* stub objects for each MatchSlot */
   /** @var MatchSlot $redSlot */
   protected $redSlot;
   /** @var MatchSlot $whiteSlot */
   protected $whiteSlot;

   /* stub objects for participants */
   /** @var Participant $redParticipant */
   protected $redParticipant;
   /** @var Participant $whiteParticipant */
   protected $whiteParticipant;

   /* stub objects for category/area */
   /** @var Category $category */
   protected $category;
   /** @var Area $area */
   protected $area;

   /* stub callback configurations */
   protected bool $redBye;   // whether red slot is a BYE slot
   protected bool $whiteBye; // whether white slot is a BYE slot
   protected bool $redSet;   // whether red participant is set
   protected bool $whiteSet; // whether white participant is set

   /* the actual module-under-test */
   protected MatchNode $node;

   protected function setUp(): void
   {
      $this->redParticipant   = new Participant(1, 1, '', '');
      $this->whiteParticipant = new Participant(2, 1, '', '');

      $redSlot = $this->createStub(MatchSlot::class);
      $redSlot->method('isBye')->WillReturnCallback(fn() => $this->redBye);
      $redSlot->method('getParticipant')->willReturnCallback(fn() => $this->redSet? $this->redParticipant : null);
      $this->redSlot = $redSlot;

      $whiteSlot = $this->createStub(MatchSlot::class);
      $whiteSlot->method('isBye')->WillReturnCallback(fn() => $this->whiteBye);
      $whiteSlot->method('getParticipant')->willReturnCallback(fn() => $this->whiteSet ? $this->whiteParticipant : null);
      $this->whiteSlot = $whiteSlot;

      $this->node = new MatchNode("test", $this->redSlot, $this->whiteSlot);

      $this->category = $this->createStub(Category::class);
      $this->area = $this->createStub(Area::class);
   }

   private function generateTruthTable(int $num): \Generator
   {
      for( $cur = pow(2,$num)-1; $cur >= 0; --$cur )
      {
         yield array_map( fn($b) => (bool)(($cur>>$b)&1), range(0,$num-1) );
      }
   }

   /**
    * MatchNode test while no MatchRecord assigned
    */
   public function testNodeStatusReports()
   {
      foreach( $this->generateTruthTable(5) as list($redBye,$whiteBye,$set_red,$set_white,$frozen) )
      {
         $this->redBye = $redBye;
         $this->whiteBye = $whiteBye;
         $this->redSet = $set_red;
         $this->whiteSet = $set_white;

         /* MatchSlots are in responsiblity to keep a consistent state.
          * skip tests for inconsistent slot states: slot is BYE, but has a participant set
          */
         if (($redBye && $set_red) || ($whiteBye && $set_white)) continue;

         /* isObsolete: if both slots are BYE */
         $this->assertEquals($redBye&&$whiteBye, $this->node->isObsolete());

         /* isBye: if exactly one slot is BYE */
         $this->assertEquals(($redBye || $whiteBye) && !($redBye && $whiteBye), $this->node->isBye());

         /* isReal: if neither slot is BYE */
         $this->assertEquals(!$redBye && !$whiteBye, $this->node->isReal());

         /**
          * Determined/Pending when both Participants are set.
          * We don't have a MatchRecord in this specific test
          */
         $this->assertEquals($set_red && $set_white, $this->node->isDetermined());
         $this->assertEquals($set_red && $set_white, $this->node->isPending());

         /**
          * following interface can only become true with an active match record.
          */
         $this->assertFalse($this->node->isEstablished());
         $this->assertFalse($this->node->isOngoing());
         $this->assertFalse($this->node->isCompleted());
         $this->assertSame(null, $this->node->getDefeated());

         /**
          * isDecided: without any MatchRecord, it should still return true on BYEs
          * also test check for "winner" here, as it refers to the same logic
          */
         $expected_decided = ($redBye && $set_white) || ($whiteBye && $set_red);
         $expected_winner  = $expected_decided? ($redBye? $this->whiteParticipant : $this->redParticipant) : null;
         $this->assertEquals($expected_decided, $this->node->isDecided());
         $this->assertSame($expected_winner, $this->node->getWinner());

         $this->assertSame($set_red? $this->redParticipant : null, $this->node->getRedParticipant());
         $this->assertSame($set_white? $this->whiteParticipant : null, $this->node->getWhiteParticipant());
      }
   }

   /**
    * MatchNode test with a valid MatchRecord, but no winner, yet.
    */
   public function testNodeValidMatchNoWinner()
   {
      $this->redBye = false;
      $this->whiteBye = false;
      $this->redSet = true;
      $this->whiteSet = true;

      $record = new MatchRecord(1, "test", $this->category, $this->area,
                                $this->redParticipant, $this->whiteParticipant, null, false);

      $this->node->setMatchRecord($record);

      $this->assertFalse($this->node->isObsolete());
      $this->assertFalse($this->node->isBye());
      $this->assertTrue( $this->node->isReal());
      $this->assertTrue($this->node->isDetermined());
      $this->assertFalse($this->node->isPending());
      $this->assertTrue($this->node->isEstablished());
      $this->assertTrue($this->node->isOngoing());
      $this->assertFalse($this->node->isCompleted());
      $this->assertFalse($this->node->isDecided());
      $this->assertSame(null, $this->node->getDefeated());
      $this->assertSame(null, $this->node->getWinner());
      $this->assertTrue($this->node->isModifiable());
      $this->assertSame($this->redParticipant, $this->node->getRedParticipant());
      $this->assertSame($this->whiteParticipant, $this->node->getWhiteParticipant());
   }

   /**
    * MatchNode test with a valid MatchRecord, and a winner
    */
   public function testNodeValidMatchWithWinner()
   {
      $this->redBye = false;
      $this->whiteBye = false;
      $this->redSet = true;
      $this->whiteSet = true;

      $record = new MatchRecord(1, "test", $this->category, $this->area,
                                $this->redParticipant, $this->whiteParticipant, $this->redParticipant, false,
                                finalized_at: new \DateTime() );

      $this->node->setMatchRecord($record);

      $this->assertFalse($this->node->isObsolete());
      $this->assertFalse($this->node->isBye());
      $this->assertTrue( $this->node->isReal());
      $this->assertTrue($this->node->isDetermined());
      $this->assertFalse($this->node->isPending());
      $this->assertTrue($this->node->isEstablished());
      $this->assertFalse($this->node->isOngoing());
      $this->assertTrue($this->node->isCompleted());
      $this->assertTrue($this->node->isDecided());
      $this->assertSame($this->whiteParticipant, $this->node->getDefeated());
      $this->assertSame($this->redParticipant, $this->node->getWinner());
      $this->assertTrue($this->node->isModifiable());
      $this->assertSame($this->redParticipant, $this->node->getRedParticipant());
      $this->assertSame($this->whiteParticipant, $this->node->getWhiteParticipant());
   }

   /**
    * matchRecord added to non-real match
    */
   public function testMatchRecordNonReal()
   {
      $this->redBye = true;
      $this->whiteBye = false;
      $this->redSet = false;
      $this->whiteSet = true;

      $record = new MatchRecord(
         1, "test", $this->category, $this->area,
         $this->redParticipant, $this->whiteParticipant,
         null, false, finalized_at: new \DateTime()
      );

      $this->expectException(\LogicException::class);
      $this->node->setMatchRecord($record);
   }

   /**
    * matchRecord added with wrong match node name
    */
   public function testSetWrongMatchRecord()
   {
      $this->redBye = false;
      $this->whiteBye = false;
      $this->whiteSet = true;
      $this->redSet = true;

      $record = new MatchRecord(
         1, "test_wrong", $this->category, $this->area,
         $this->redParticipant, $this->whiteParticipant,
         null, false, finalized_at: new \DateTime()
      );

      $this->expectException(\DomainException::class);
      $this->node->setMatchRecord($record);
   }

   /**
    * matchRecord added with unfitting participants
    */
   public function testMatchRecordParticipantsOverride()
   {
      $this->redBye = false;
      $this->whiteBye = false;
      $this->redSet = true;
      $this->whiteSet = true;

      /** @var Participant $newRedParticipant */
      $newRedParticipant = $this->createStub(Participant::class);

      $record = new MatchRecord(
         1, "test", $this->category, $this->area,
         $newRedParticipant, $this->whiteParticipant,
         null, false, finalized_at: new \DateTime()
      );

      $this->expectException(\DomainException::class);
      $this->node->setMatchRecord($record);
   }
}
