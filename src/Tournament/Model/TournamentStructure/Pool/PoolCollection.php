<?php

namespace Tournament\Model\TournamentStructure\Pool;

class PoolCollection extends \Base\Model\IdObjectCollection
{
   protected static function elements_type(): string
   {
      return Pool::class;
   }

   static protected function get_id($value): mixed
   {
      return $value->getName();
   }
}
