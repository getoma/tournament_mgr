<?php

namespace Tests\Tournament\Model\TournamentStructure\MatchNode;

use Tournament\Model\TournamentStructure\MatchNode\KoNode;

use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\MatchRecord\MatchRecord;

/**
 * KoNodeTest derives from MatchNodeTest to automatically perform all basic tests for KoNode
 * as well, so we can make sure that KoNode really behaves the same for all MatchNode interfaces
 */
class KoNodeTest extends MatchNodeTest
{
   protected function setUp(): void
   {
      parent::setUp();
      $this->node = new KoNode("test", $this->redSlot, $this->whiteSlot);
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

      $normal = new KoNode("test", $this->redSlot, $this->whiteSlot);
      $this->assertFalse($normal->tiesAllowed());

      $record = new MatchRecord(1, "test", $this->createStub(Category::class), $this->createStub(Area::class),
                           $this->redParticipant, $this->whiteParticipant,
                           tie_break: false );
      $normal->setMatchRecord($record);
      $this->assertFalse($normal->tiesAllowed());

      $record = new MatchRecord(1, "test", $this->createStub(Category::class), $this->createStub(Area::class),
                     $this->redParticipant, $this->whiteParticipant,
                     tie_break: true );
      $normal->setMatchRecord($record);
      $this->assertFalse($normal->tiesAllowed());
   }

}