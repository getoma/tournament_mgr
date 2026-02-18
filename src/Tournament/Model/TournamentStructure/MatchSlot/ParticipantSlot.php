<?php

namespace Tournament\Model\TournamentStructure\MatchSlot;

use Tournament\Model\Participant\Participant;

class ParticipantSlot extends MatchSlot
{
   public function __construct(public ?Participant $participant = null, public ?string $slotName = null)
   {
   }

   public function isBye(): bool
   {
      return $this->participant === null;
   }

   public function str(): string
   {
      return $this->isBye() ? '--' : 'Teilnehmer ' . $this->participant->id;
   }

   public function getParticipant(): ?Participant
   {
      return $this->participant;
   }

   public function freezeResult(): void
   {
      // nothing to do
   }

   /**
    * get the slot name for any slot that is a starting slot
    * @return string name of the slot: name of owning node + color
    */
   public function getName(): ?string
   {
      return $this->slotName;
   }

}
