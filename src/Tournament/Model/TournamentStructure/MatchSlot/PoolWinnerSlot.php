<?php

namespace Tournament\Model\TournamentStructure\MatchSlot;

use Tournament\Model\TournamentStructure\MatchSlot;
use Tournament\Model\Participant\Participant;

class PoolWinnerSlot extends MatchSlot
{
   public function __construct(public readonly int $poolId, public readonly int $rank)
   {
   }

   public function isBye(): bool
   {
      return false;
   }

   public function str(): string
   {
      return 'Pool ' . $this->poolId . ' Platz ' . $this->rank;
   }

   public function getParticipant(): ?Participant
   {
      return null;
   }
}