<?php

namespace Tournament\Model\MatchRankHandler;

use Tournament\Model\MatchRecord\TeamMatchRecord;
use Tournament\Model\TournamentStructure\Pool\Pool;

/**
 * interface of a handler that allows to derive a ranking of MatchParticipants
 * based on a list of matches that have occured between them.
 */
interface MatchRankHandler
{
   /**
    * derive the current ranking of pool participants based on the pool matches
    */
   public function derivePoolRanking(Pool $pool): MatchRankCollection;

   /**
    * Evaluate a team match record to derive the winning team
    */
   public function deriveTeamMatchResults(TeamMatchRecord $record): MatchRankCollection;
}