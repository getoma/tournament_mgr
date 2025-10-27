<?php

namespace Tournament\Model\PoolRankHandler;

class PoolRankCollection extends \Base\Model\ObjectCollection
{
   protected static function elements_type(): string
   {
      return PoolRank::class;
   }
}