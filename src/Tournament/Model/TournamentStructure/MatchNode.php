<?php

namespace Tournament\Model\TournamentStructure;

use Tournament\Model\TournamentStructure\MatchSlot;
use Tournament\Model\Data\Participant;
use Tournament\Model\Data\Area;

class MatchNode
{

   public function __construct(
      public string $name,
      public MatchSlot $slotRed,
      public MatchSlot $slotWhite,
      public ?Area $area = null,
   )
   {
      if( $this->slotRed === $this->slotWhite )
      {
         throw new \InvalidArgumentException("invalid match: red and white slot must be different");
      }
   }

   public function isBye(): bool
   {
      return $this->slotRed->isBye() && $this->slotWhite->isBye();
   }

   /**
    * get the current winner participant of this match, or null if not decided, yet
    */
   public function getWinner(): ?Participant
   {
      if ($this->slotRed->isBye())   return $this->slotWhite->getParticipant();
      if ($this->slotWhite->isBye()) return $this->slotRed->getParticipant();

      /* TODO: derive winner from Database */

      return null;
   }
}
