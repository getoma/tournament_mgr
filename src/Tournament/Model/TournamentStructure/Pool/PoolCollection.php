<?php

namespace Tournament\Model\TournamentStructure\Pool;

class PoolCollection extends \Base\Model\IdObjectCollection
{
   protected const DEFAULT_ELEMENTS_TYPE = Pool::class;

   static protected function get_id($value): mixed
   {
      return $value->getName();
   }
}
