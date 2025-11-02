<?php

namespace Tournament\Model\TournamentStructure\MatchSlot;

use Tournament\Model\TournamentStructure\MatchNode\KoNode;
use Tournament\Model\Participant\Participant;

class MatchWinnerSlot extends MatchSlot
{
   public function __construct(public readonly KoNode $matchNode)
   {
   }

   public function isBye(): bool
   {
      return $this->matchNode->isObsolete();
   }

   public function str(): string
   {
      return $this->isBye()? '--' : 'Winner match ' . $this->matchNode->name;
   }

   public function getParticipant(): ?Participant
   {
      return $this->matchNode->getWinner();
   }

   public function freezeResult(): void
   {
      $this->matchNode->frozen = true;
   }
}
