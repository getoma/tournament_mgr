<?php

declare(strict_types=1);

namespace Tournament\Model\Participant;

use Respect\Validation\Validator as v;
use Tournament\Model\Category\Category;

class Team implements \Tournament\Model\Base\DbItem, \Tournament\Model\TournamentStructure\MatchParticipant\MatchParticipant
{
   use \Tournament\Model\Base\DbItemTrait;

   public function __construct(
      public ?int $id,
      public readonly int $category_id, // Identifier for the category this team was set up for
      public string $name,
      public bool $withdrawn = false,
      public ParticipantCollection $members  = new ParticipantCollection(),
      public ?string $slot_name = null,
      public ?string $pre_assign = null,
   )
   {
   }

   /* get the validation rules for the participant */
   public static function validationRules(): array
   {
      return [
         'name'   => v::stringType()->notEmpty()->length(1, max: 255),
         'withdrawn'  => v::optional(v::scalarVal()->boolVal())
      ];
   }

   public function updateFromArray(array $data): void
   {
      if (isset($data['name'])) $this->name = $data['name'];
      if (isset($data['withdrawn'])) $this->withdrawn = (bool)$data['withdrawn'];
   }

   static public function createFromArray(int $category_id, array $data): static
   {
      $result = new static(
         id: (int)$data['id'] ?? null,
         category_id: $category_id,
         name: (string)$data['name'] ?? throw new \DomainException('no team name provided'),
         withdrawn: (bool)$data['withdrawn'] ?? false,
         slot_name: $data['slot_name'] ?? null,
         pre_assign: $data['pre_assign'] ?? null,
      );
      return $result;
   }

   /**
    * MatchParticipant interface
    */
   public function getId(): int
   {
      return $this->id;
   }

   public function getDisplayName(): string
   {
      return $this->name;
   }

   public function isComposite(): bool
   {
      return true;
   }

   /**
    * check whether this is a dummy/placeholder participant
    * do NOT use id, id might also be null for participants that are not part of the DB, yet.
    */
   public function isDummy(): bool
   {
      return empty($this->name);
   }

   public function setStartSlot(Category $c, ?string $slotName): void
   {
      if( $this->category_id !== $c->id ) throw new \UnexpectedValueException("wrong category for this team");
      $this->slot_name = $slotName;
   }

   public function getStartSlot(Category $c): ?string
   {
      return $this->category_id === $c->id? $this->slot_name : null;
   }

   public function setPreAssignedSlot(Category $c, ?string $slotName): void
   {
      if ($this->category_id !== $c->id) throw new \UnexpectedValueException("wrong category for this team");
      $this->pre_assign = $slotName;
   }

   public function getPreAssignedSlot(Category $c): ?string
   {
      return $this->category_id === $c->id ? $this->pre_assign : null;
   }

   public function getClub(): ?string
   {
      return null; // teams don't have a club, but we need to implement this method for the MatchParticipant interface
   }
}