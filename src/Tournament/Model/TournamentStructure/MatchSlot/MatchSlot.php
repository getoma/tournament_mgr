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
}
