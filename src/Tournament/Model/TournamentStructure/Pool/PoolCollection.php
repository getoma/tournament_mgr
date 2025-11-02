<?php

namespace Tournament\Model\TournamentStructure\Pool;

class PoolCollection extends \Base\Model\ObjectCollection
{
   protected static function elements_type(): string
   {
      return Pool::class;
   }
}
