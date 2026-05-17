<?php declare(strict_types=1);

namespace Tournament\Model\TournamentStructure\MatchParticipant;

use Tournament\Model\Category\Category;

class DummyMatchParticipant implements MatchParticipant
{
   private array $start_slots = [];

   public function __construct(
      private bool $composite,
      private string $name = 'dummy',
      private ?string $club = null,
   )
   {
   }

   public function getDisplayName(): string
   {
      return $this->name;
   }

   public function getClub(): ?string
   {
      return $this->club;
   }

   public function getId(): ?int
   {
      return null;
   }

   public function isComposite(): bool
   {
      return $this->composite;
   }

   public function isDummy(): bool
   {
      return true;
   }

   public function setStartSlot(Category $c, ?string $slotName): void
   {
      $this->start_slots[$c->id] = $slotName;
   }

   public function getStartSlot(Category $c): ?string
   {
      return $this->start_slots[$c->id] ?? null;
   }

   public function setPreAssignedSlot(Category $c, ?string $slotName): void
   {
      throw new \LogicException("attempt to pre-assign a placeholder");
   }

   public function getPreAssignedSlot(Category $c): ?string
   {
      return null;
   }
}