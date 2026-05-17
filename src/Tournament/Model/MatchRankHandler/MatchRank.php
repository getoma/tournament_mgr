<?php

namespace Tournament\Model\MatchRankHandler;

use Tournament\Model\TournamentStructure\MatchParticipant\MatchParticipant;

class MatchRank
{
   public function __construct(
      public MatchParticipant $participant,
      public int $rank = 0,
      public int $wins = 0,
      public int $ties = 0,
      public int $points = 0,
      public int $tie_breaks = 0 )
   {

   }
}