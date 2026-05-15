<?php declare(strict_types=1);

namespace Tournament\Model\TournamentStructure\MatchParticipant;

use Tournament\Model\Category\Category;

interface MatchParticipant
{
   function getDisplayName(): string;
   function getId(): ?int;

   function isComposite(): bool;

   function isDummy(): bool;

   function setStartSlot(Category $c, ?string $slotName): void;
   function getStartSlot(Category $c): ?string;

   function setPreAssignedSlot(Category $c, ?string $slotName): void;
   function getPreAssignedSlot(Category $c): ?string;

   function getClub(): ?string;
}