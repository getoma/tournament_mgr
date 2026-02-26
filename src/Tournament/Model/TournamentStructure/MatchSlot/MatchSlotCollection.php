<?php

namespace Tournament\Model\TournamentStructure\MatchSlot;

class MatchSlotCollection extends \Base\Model\IdObjectCollection
{
   protected const DEFAULT_ELEMENTS_TYPE = MatchSlot::class;

   static protected function get_id($value): mixed
   {
      /** @var MatchSlot $value */
      return $value->getName();
   }
}