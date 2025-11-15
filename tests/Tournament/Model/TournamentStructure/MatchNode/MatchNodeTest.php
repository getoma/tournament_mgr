<?php

namespace Tests\Tournament\Model\TournamentStructure\MatchNode;

use PHPUnit\Framework\TestCase;

use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\MatchPointHandler\MatchPointHandler;
use Tournament\Model\MatchRecord\MatchRecord;
use Tournament\Model\Participant\Participant;
use Tournament\Model\TournamentStructure\MatchSlot\MatchSlot;
use Tournament\Model\TournamentStructure\MatchNode\MatchNode;

class MatchNodeTest extends TestCase
{
   /* stub objects for each MatchSlot */
   protected $redSlot;
   protected $whiteSlot;

   /* stub objects for participants */
   protected $redParticipant;
   protected $whiteParticipant;

   /* stub callback configurations */
   protected bool $redBye;   // whether red slot is a BYE slot
   protected bool $whiteBye; // whether white slot is a BYE slot
   protected bool $redSet;   // whether red participant is set
   protected bool $whiteSet; // whether white participant is set

   /* the actual module-under-test */
   protected MatchNode $node;

   /* match point handler for the match node */
   protected MatchPointHandler $mpHdl;

   protected function setUp(): void
   {
      $this->redParticipant = $this->createStub(Participant::class);
      $this->redParticipant->id = 1;
      $this->whiteParticipant = $this->createStub(Participant::class);
      $this->whiteParticipant->id = 2;

      $this->redSlot = $this->createStub(MatchSlot::class);
      $this->redSlot->method('isBye')->WillReturnCallback(fn() => $this->redBye);
      $this->redSlot->method('getParticipant')->willReturnCallback(fn() => $this->redSet? $this->redParticipant : null);

      $this->whiteSlot = $this->createStub(MatchSlot::class);
      $this->whiteSlot->method('isBye')->WillReturnCallback(fn() => $this->whiteBye);
      $this->whiteSlot->method('getParticipant')->willReturnCallback(fn() => $this->whiteSet ? $this->whiteParticipant : null);

      $this->mpHdl = $this->createStub(MatchPointHandler::class);

      $this->node = new MatchNode("test", $this->redSlot, $this->whiteSlot, $this->mpHdl);
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
         $this->node->frozen = $frozen;

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

         /* for normal Nodes, any determined node is also modifiable, unless it is explicitly frozen */
         $this->assertEquals($this->node->isDetermined() && !$frozen, $this->node->isModifiable());
         $this->assertEquals($frozen, $this->node->isFrozen());

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

      $record = new MatchRecord(1, "test", $this->createStub(Category::class), $this->createStub(Area::class),
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
      $this->assertFalse($this->node->isTied());
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

      $record = new MatchRecord(1, "test", $this->createStub(Category::class), $this->createStub(Area::class),
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
      $this->assertFalse($this->node->isTied());
      $this->assertSame($this->whiteParticipant, $this->node->getDefeated());
      $this->assertSame($this->redParticipant, $this->node->getWinner());
      $this->assertTrue($this->node->isModifiable());
      $this->assertSame($this->redParticipant, $this->node->getRedParticipant());
      $this->assertSame($this->whiteParticipant, $this->node->getWhiteParticipant());
   }

   /**
    * MatchNode test with a valid MatchRecord, and a tie
    */
   public function testNodeValidMatchWithTie()
   {
      $this->redBye = false;
      $this->whiteBye = false;
      $this->redSet = true;
      $this->whiteSet = true;

      $record = new MatchRecord(1, "test", $this->createStub(Category::class), $this->createStub(Area::class),
                                $this->redParticipant, $this->whiteParticipant, null, false,
                                finalized_at: new \DateTime() );


      $this->node->setMatchRecord($record);

      $this->assertFalse($this->node->isObsolete());
      $this->assertFalse($this->node->isBye());
      $this->assertTrue($this->node->isReal());
      $this->assertTrue($this->node->isDetermined());
      $this->assertFalse($this->node->isPending());
      $this->assertTrue($this->node->isEstablished());
      $this->assertFalse($this->node->isOngoing());
      $this->assertTrue($this->node->isCompleted());
      $this->assertFalse($this->node->isDecided());
      $this->assertTrue($this->node->isTied());
      $this->assertSame(null, $this->node->getDefeated());
      $this->assertSame(null, $this->node->getWinner());
      $this->assertTrue($this->node->isModifiable());
      $this->assertSame($this->redParticipant, $this->node->getRedParticipant());
      $this->assertSame($this->whiteParticipant, $this->node->getWhiteParticipant());
   }

   /**
    * tie break test
    */
   public function testTiesAllowed()
   {
      $this->redBye = false;
      $this->whiteBye = false;
      $this->redSet = true;
      $this->whiteSet = true;

      $normal = new MatchNode("test", $this->redSlot, $this->whiteSlot, $this->mpHdl, tie_break: false);
      $this->assertTrue($normal->tiesAllowed());

      $record = new MatchRecord(1, "test", $this->createStub(Category::class), $this->createStub(Area::class),
                           $this->redParticipant, $this->whiteParticipant,
                           tie_break: true );
      $normal->setMatchRecord($record);
      $this->assertFalse($normal->tiesAllowed());

      $tie_break = new MatchNode("test", $this->redSlot, $this->whiteSlot, $this->mpHdl, tie_break: true);
      $this->assertFalse($tie_break->tiesAllowed());
      $record = new MatchRecord(1, "test", $this->createStub(Category::class), $this->createStub(Area::class),
                     $this->redParticipant, $this->whiteParticipant,
                     tie_break: false );
      $tie_break->setMatchRecord($record);
      $this->assertTrue($tie_break->tiesAllowed());
   }
}
