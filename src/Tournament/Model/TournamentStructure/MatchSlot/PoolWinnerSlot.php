<?php declare(strict_types=1);

namespace Tournament\Model\TournamentStructure\MatchSlot;

use Tournament\Model\TournamentStructure\MatchParticipant\MatchParticipant;
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

   public function getParticipant(): ?MatchParticipant
   {
      return $this->pool->getRanked($this->rank);
   }

   public function freezeResult(): void
   {
      $this->pool->freezeResults();
   }

   /**
    * get the slot name
    * @return string name of the pool that is connected to this slot
    * @return null  if this is not a viable starting slot
    */
   public function getName(): ?string
   {
      return $this->pool->getName();
   }
}