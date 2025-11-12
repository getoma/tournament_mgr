<?php

namespace Tests\Tournament\Model\TournamentStructure\MatchNode;

use Tournament\Model\TournamentStructure\MatchNode\KoNode;

/**
 * KoNodeBaseTest repeats all basic MatchNode tests by deriving from their test class
 * and just replacing the to-be-tested object with a KoNode instance.
 */
class KoNodeBaseTest extends MatchNodeTest
{
   protected function setUp(): void
   {
      parent::setUp();
      $this->node = new KoNode("test", $this->redSlot, $this->whiteSlot);
   }
}