<?php declare(strict_types=1);

namespace Tournament\Model\TournamentStructure\MatchSlot;

use Tournament\Model\TournamentStructure\MatchNode\KoNode;
use Tournament\Model\TournamentStructure\MatchParticipant\MatchParticipant;

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
      return $this->isBye()? '--' : 'Winner match ' . $this->matchNode->getName();
   }

   public function getParticipant(): ?MatchParticipant
   {
      return $this->matchNode->getWinner();
   }

   public function freezeResult(): void
   {
      $this->matchNode->freeze();
   }
}
