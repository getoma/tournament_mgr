<?php

namespace Tournament\Model\Participant;

use Respect\Validation\Validator as v;

class Participant implements \Tournament\Model\Base\DbItem
{
   use \Tournament\Model\Base\DbItemTrait;

   public function __construct(
      public ?int $id,                    // Unique identifier for the participant
      public readonly int $tournament_id, // Identifier for the tournament this participant belongs to
      public string $lastname,            // Last name of the participant
      public string $firstname,           // First name of the participant
      public ?string $club = null,        // Club/Association of participant
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
         'categories' => v::arrayType()->each(v::numericVal()->intVal()->notEmpty()->min(0))
      ];
   }

   public function updateFromArray(array $data): void
   {
      if (isset($data['lastname'])) $this->lastname = $data['lastname'];
      if (isset($data['firstname'])) $this->firstname = $data['firstname'];
      if (array_key_exists('club', $data)) $this->club = $data['club']; // null is allowed here
      $this->updateCategories($data['categories']);
   }

   static public function createFromArray(int $tournament_id, array $data): static
   {
      $result = new static(
         id: null,
         tournament_id: $tournament_id,
         lastname: $data['lastname'] ?? throw new \DomainException('no lastname provided'),
         firstname: $data['firstname'] ?? throw new \DomainException('no firstname provided'),
         club: $data['club'] ?? null,
      );
      $result->updateCategories($data['categories'] ?? []);
      return $result;
   }

   private function updateCategories(array $category_id_list): void
   {
      // categories: drop any no longer provided
      $this->categories = $this->categories->filter(fn($ca) => in_array($ca->categoryId, $category_id_list));
      // add any new category assignment
      foreach ($category_id_list as $catId)
      {
         if (!$this->categories->keyExists($catId)) $this->categories[] = $catId;
      }
   }

}