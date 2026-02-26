<?php

namespace Tournament\Model\TournamentStructure\MatchNode;

class MatchRoundCollection extends \Base\Model\ObjectCollection
{
   protected const DEFAULT_ELEMENTS_TYPE = MatchNodeCollection::class;

   public function flatten(): MatchNodeCollection
   {
      return MatchNodeCollection::new( array_merge(...array_map(fn($e) => $e->values(), $this->elements)));
   }
}
