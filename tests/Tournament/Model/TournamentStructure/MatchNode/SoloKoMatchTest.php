<?php declare(strict_types=1);

namespace Tests\Tournament\Model\TournamentStructure\MatchNode;

use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\TournamentStructure\MatchNode\SoloKoMatch;
use Tournament\Model\MatchRecord\MatchRecord;

/**
 * SoloKoMatchTest repeats all SoloMatch tests by deriving from their test class
 * and just replacing the to-be-tested object with a SoloKoMatch instance.
 */
class SoloKoMatchTest extends SoloMatchTest
{
   protected function setUp(): void
   {
      parent::setUp();
      $this->node = new SoloKoMatch("test", $this->category, $this->redSlot, $this->whiteSlot);
   }

   /**
    * tie break test - KoNodes may never be ties, so override base tests
    */
   public function testTieBreak()
   {
      $this->redBye = false;
      $this->whiteBye = false;
      $this->redSet = true;
      $this->whiteSet = true;

      $this->assertFalse($this->node->tiesAllowed());
      $this->assertFalse($this->node->isTieBreak());

      /* by assigning a tie_break via match record, ties shouldn't be allowed anymore */
      $record = new MatchRecord(1, "test", $this->createStub(Category::class), $this->createStub(Area::class),
                           $this->redParticipant, $this->whiteParticipant,
                           tie_break: true );
      $this->node->setMatchRecord($record);
      $this->assertFalse($this->node->tiesAllowed());
      $this->assertTrue($this->node->isTieBreak());

      /* also test with setting this property at creation of the node itself */
      $node_class = get_class($this->node);
      $tie_break_node = new $node_class("test", $this->category, $this->redSlot, $this->whiteSlot, tieBreak: true);
      $this->assertFalse($tie_break_node->tiesAllowed());
      /* match record should not be able to cancel any logical tie break */
      $record = new MatchRecord(1, "test", $this->createStub(Category::class), $this->createStub(Area::class),
                     $this->redParticipant, $this->whiteParticipant,
                     tie_break: false );
      $tie_break_node->setMatchRecord($record);
      $this->assertFalse($tie_break_node->tiesAllowed());
      $this->assertTrue($this->node->isTieBreak());
   }

   /**
    * ties allowed test - test is kinda obsolete for KoNodes, as the to-be-tested parameter
    * is explicitly not provided by SoloKoMatch
    */
   public function testTiesAllowed()
   {
      $this->redBye = false;
      $this->whiteBye = false;
      $this->redSet = true;
      $this->whiteSet = true;

      /* Test with setting "no ties allowed" at creation */
      $tie_break_node = new SoloKoMatch("test", $this->category, $this->redSlot, $this->whiteSlot);
      $this->assertFalse($tie_break_node->tiesAllowed());
   }
}