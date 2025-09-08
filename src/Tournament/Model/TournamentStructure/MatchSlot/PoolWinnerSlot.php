<?php

namespace Tournament\Model\TournamentStructure\MatchSlot;

use Tournament\Model\TournamentStructure\MatchSlot;
use Tournament\Model\Data\Participant;

class PoolWinnerSlot extends MatchSlot
{
   public function __construct(public int $poolId, public int $rank)
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