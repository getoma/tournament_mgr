<?php declare(strict_types=1);

namespace Tournament\Model\Participant;

use Respect\Validation\Validator as v;

class Participant implements \Tournament\Model\Base\DbItem, \Tournament\Model\TournamentStructure\MatchParticipant\MatchParticipant
{
   use \Tournament\Model\Base\DbItemTrait;

   public function __construct(
      public ?int $id,                    // Unique identifier for the participant
      public readonly int $tournament_id, // Identifier for the tournament this participant belongs to
      public string $lastname,            // Last name of the participant
      public string $firstname,           // First name of the participant
      public ?string $club = null,        // Club/Association of participant
      public bool $withdrawn = false,     // whether participant registration was withdrawn
      public CategoryAssignmentCollection $categories = new CategoryAssignmentCollection() // Categories the participant is registered in
   )
   {
   }

   /* get the validation rules for the participant */
   public static function validationRules(): array
   {
      return [
         'lastname'   => v::stringType()->notEmpty()->length(1, max: 255),
         'firstname'  => v::stringType()->notEmpty()->length(1, max: 255),
         'club'       => v::stringType()->length(max:127),
         'categories' => v::arrayType()->each(v::numericVal()->intVal()->notEmpty()->min(0)),
         'withdrawn'  => v::optional(v::scalarVal()->boolVal())
      ];
   }

   public function updateFromArray(array $data): void
   {
      if (isset($data['lastname'])) $this->lastname = $data['lastname'];
      if (isset($data['firstname'])) $this->firstname = $data['firstname'];
      if (array_key_exists('club', $data)) $this->club = $data['club']; // null is allowed here
      if (isset($data['withdrawn']))  $this->withdrawn = (bool)$data['withdrawn'];
      if (isset($data['categories'])) $this->categories->updateFromArray($data['categories']);
   }

   static public function createFromArray(int $tournament_id, array $data): static
   {
      $result = new static(
         id: null,
         tournament_id: $tournament_id,
         lastname: $data['lastname'] ?? throw new \DomainException('no lastname provided'),
         firstname: $data['firstname'] ?? throw new \DomainException('no firstname provided'),
         club: $data['club'] ?? null,
         withdrawn: $data['withdrawn'] ?? false,
      );
      $result->categories->updateFromArray($data['categories'] ?? []);
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
      return $this->lastname . ", " . $this->firstname;
   }

   public function isComposite(): bool
   {
      return false;
   }

   /**
    * check whether this is a dummy/placeholder participant
    * do NOT use id, id might also be null for participants that are not part of the DB, yet.
    */
   public function isDummy(): bool
   {
      return empty($this->lastname);
   }

   /**
    * in some cases, it might be necessary to have a placeholder participant for some calculations
    * natively provide this. A dummy/placeholder participant is identified by having no lastname set
    */
   public static function dummy(): static
   {
      return new static(null, 0, '', '');
   }
}