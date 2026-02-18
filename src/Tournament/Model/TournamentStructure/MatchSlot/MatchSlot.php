<?php

namespace Tournament\Model\TournamentStructure\MatchSlot;

use Tournament\Model\Participant\Participant;

abstract class MatchSlot
{
   /**
    * return a string representation of this slot
    */
   abstract public function str(): string;

   /**
    * return the participant in this slot,
    * or null if not decided, yet.
    */
   abstract public function getParticipant(): ?Participant;

   /**
    * whether this slot represents a BYE
    */
   abstract public function isBye(): bool;

   /**
    * make sure the participant provided by this slot is no longer modified
    */
   abstract public function freezeResult(): void;

   /**
    * get the slot name for any slot that is a starting slot
    * @return string name of the slot if this is a vialable starting slot
    * @return null  if this is not a viable starting slot
    */
   public function getName(): ?string
   {
      return null;
   }
}
