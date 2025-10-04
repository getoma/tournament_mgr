<?php

namespace Tournament\Model\Participant;

use Tournament\Model\Category\CategoryCollection;

use Respect\Validation\Validator as v;

class Participant extends \Tournament\Model\Base\DbItem
{
   public function __construct(
      ?int $id = null,                     // Unique identifier for the participant
      public readonly int $tournament_id, // Identifier for the tournament this participant belongs to
      public string $lastname,   // Last name of the participant
      public string $firstname,  // First name of the participant
      public CategoryCollection $categories = new CategoryCollection() // Categories the participant is registered in
   ) {
      $this->id = $id;
   }

   /* get the validation rules for the participant */
   public static function validationRules(string $context = 'update'): array
   {
      return [
         'lastname' => v::stringType()->notEmpty()->length(1, max: 255),
         'firstname' => v::stringType()->notEmpty()->length(1, max: 255)
      ];
   }

   public function updateFromArray(array $data): void
   {
      if (isset($data['lastname'])) $this->lastname = $data['lastname'];
      if (isset($data['firstname'])) $this->firstname = $data['firstname'];
   }

}