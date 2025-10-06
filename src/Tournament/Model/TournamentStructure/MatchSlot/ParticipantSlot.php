<?php

namespace Tournament\Model\TournamentStructure\MatchSlot;

use Tournament\Model\Participant\Participant;

class ParticipantSlot extends MatchSlot
{
   public function __construct(public ?Participant $participant = null)
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

}
