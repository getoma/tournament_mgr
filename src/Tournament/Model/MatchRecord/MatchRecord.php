<?php declare(strict_types=1);

namespace Tournament\Model\MatchRecord;

use Tournament\Model\TournamentStructure\MatchNode\MatchSide;
use Tournament\Model\TournamentStructure\MatchParticipant\MatchParticipant;

interface MatchRecord
{
   public function getId(): int;

   public function getMatchName(): string;

   public function isComposite(): bool;

   public function isFinalized(): bool;

   public function setWinner(?MatchParticipant $p): void;

   public function getWinner(): ?MatchParticipant;

   public function getDefeated(): ?MatchParticipant;

   public function getParticipant(MatchSide $side): ?MatchParticipant;

   public function getOpponent(MatchParticipant $p): MatchParticipant;
}