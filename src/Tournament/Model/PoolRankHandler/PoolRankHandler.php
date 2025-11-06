<?php

namespace Tournament\Model\PoolRankHandler;

use Tournament\Model\TournamentStructure\MatchNode\MatchNodeCollection;

interface PoolRankHandler
{
   public function deriveRanking(MatchNodeCollection $matches): PoolRankCollection;
}