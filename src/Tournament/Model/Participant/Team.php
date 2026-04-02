<?php

declare(strict_types=1);

namespace Tournament\Model\Participant;

use Respect\Validation\Validator as v;

class Team implements \Tournament\Model\Base\DbItem, \Tournament\Model\TournamentStructure\MatchParticipant\MatchParticipant
{
   use \Tournament\Model\Base\DbItemTrait;

   public function __construct(
      public ?int $id,
      public readonly int $categoryId, // Identifier for the category this team was set up for
      public string $name,
      public bool $withdrawn = false,
      public readonly ParticipantCollection $members  = new ParticipantCollection(),
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

   static public function createFromArray(int $categoryId, array $data): static
   {
      $result = new static(
         id: null,
         categoryId: $categoryId,
         name: $data['name'] ?? throw new \DomainException('no team name provided'),
         withdrawn: $data['withdrawn'] ?? false,
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

   /**
    * in some cases, it might be necessary to have a placeholder participant for some calculations
    * natively provide this. A dummy/placeholder team is identified by having no name set
    */
   public static function dummy(): static
   {
      return new static(null, 0, '');
   }
}