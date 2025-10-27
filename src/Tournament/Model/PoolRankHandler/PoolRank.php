<?php

namespace Tournament\Model\PoolRankHandler;

use Tournament\Model\Participant\Participant;

class PoolRank
{
   public function __construct(
      public Participant $participant,
      public int $rank,
      public int $wins,
      public int $points )
   {

   }
}