<?php declare(strict_types=1);

namespace Tests\Tournament\Model\TestStubs;

use Tournament\Model\Category\Category;
use Tournament\Model\TournamentStructure\MatchParticipant\MatchParticipant;

class TestMatchParticipant implements MatchParticipant
{
   private ?string $start_slot = null;
   private ?string $pre_assign = null;

   public function __construct(
      private int $id,
      private string $name,
      private ?string $club = null,
      private bool $composite = false,
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

   public function getId(): int
   {
      return $this->id;
   }

   public function isComposite(): bool
   {
      return $this->composite;
   }

   public function isDummy(): bool
   {
      return false;
   }

   public function setStartSlot(Category $c, ?string $slotName): void
   {
      $this->start_slot = $slotName;
   }

   public function getStartSlot(Category $c): ?string
   {
      return $this->start_slot;
   }

   public function setPreAssignedSlot(Category $c, ?string $slotName): void
   {
      $this->pre_assign = $slotName;
   }

   public function getPreAssignedSlot(Category $c): ?string
   {
      return $this->pre_assign;
   }
}