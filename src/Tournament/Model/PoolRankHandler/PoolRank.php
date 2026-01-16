<?php

namespace Tournament\Model\PoolRankHandler;

use Tournament\Model\Participant\Participant;

class PoolRank
{
   public function __construct(
      public Participant $participant,
      public int $rank = 0,
      public int $wins = 0,
      public int $ties = 0,
      public int $points = 0,
      public int $tie_breaks = 0 )
   {

   }
}