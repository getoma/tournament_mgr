<?php

namespace Tournament\Model\TournamentStructure\MatchSlot;

use Tournament\Model\Participant\Participant;
use Tournament\Model\TournamentStructure\Pool\Pool;

class PoolWinnerSlot extends MatchSlot
{
   public function __construct(public readonly Pool $pool, public readonly int $rank)
   {
   }

   public function isBye(): bool
   {
      return false;
   }

   public function str(): string
   {
      return 'Pool ' . $this->pool->getName() . ' Platz ' . $this->rank;
   }

   public function getParticipant(): ?Participant
   {
      return $this->pool->getRanked($this->rank);
   }
}