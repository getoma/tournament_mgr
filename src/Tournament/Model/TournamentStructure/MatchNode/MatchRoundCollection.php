<?php

namespace Tournament\Model\TournamentStructure\MatchNode;

use Base\Model\ObjectCollection;

class MatchRoundCollection extends ObjectCollection
{
   static protected function elements_type(): string
   {
      return MatchNodeCollection::class;
   }

   public function flatten(): MatchNodeCollection
   {
      return MatchNodeCollection::new( array_merge(...array_map(fn($e) => $e->values(), $this->elements)));
   }
}
