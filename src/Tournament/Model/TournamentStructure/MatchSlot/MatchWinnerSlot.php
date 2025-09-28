<?php

namespace Tournament\Model\TournamentStructure\MatchSlot;

use Tournament\Model\TournamentStructure\MatchSlot;
use Tournament\Model\TournamentStructure\KoNode;
use Tournament\Model\Data\Participant;

class MatchWinnerSlot extends MatchSlot
{
   public function __construct(public KoNode $matchNode)
   {
   }

   public function isBye(): bool
   {
      return $this->matchNode->isBye();
   }

   public function str(): string
   {
      return $this->isBye()? '--' : 'Winner match ' . $this->matchNode->name;
   }

   public function getParticipant(): ?Participant
   {
      return $this->matchNode->getWinner();
   }
}
