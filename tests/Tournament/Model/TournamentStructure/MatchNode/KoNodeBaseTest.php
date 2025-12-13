<?php

namespace Tests\Tournament\Model\TournamentStructure\MatchNode;

use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\TournamentStructure\MatchNode\KoNode;
use Tournament\Model\MatchRecord\MatchRecord;

/**
 * KoNodeBaseTest repeats all basic MatchNode tests by deriving from their test class
 * and just replacing the to-be-tested object with a KoNode instance.
 */
class KoNodeBaseTest extends MatchNodeTest
{
   protected function setUp(): void
   {
      parent::setUp();
      $this->node = new KoNode("test", $this->redSlot, $this->whiteSlot, $this->mpHdl);
   }

   /**
    * tie break test - KoNodes may never be ties, so override base tests
    */
   public function testTiesAllowed()
   {
      $this->redBye = false;
      $this->whiteBye = false;
      $this->redSet = true;
      $this->whiteSet = true;

      $this->assertFalse($this->node->tiesAllowed());

      $record = new MatchRecord(1, "test", $this->createStub(Category::class), $this->createStub(Area::class),
                           $this->redParticipant, $this->whiteParticipant,
                           tie_break: false );
      $this->node->setMatchRecord($record);
      $this->assertFalse($this->node->tiesAllowed());

      $record = new MatchRecord(1, "test", $this->createStub(Category::class), $this->createStub(Area::class),
                     $this->redParticipant, $this->whiteParticipant,
                     tie_break: true );
      $this->node->setMatchRecord($record);
      $this->assertFalse($this->node->tiesAllowed());
   }
}