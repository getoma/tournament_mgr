<?php declare(strict_types=1);

namespace Tournament\Model\TournamentStructure\MatchParticipant;

interface MatchParticipant
{
   function getDisplayName(): string;
   function getId(): int;

   function isComposite(): bool;

   function isDummy(): bool;
}