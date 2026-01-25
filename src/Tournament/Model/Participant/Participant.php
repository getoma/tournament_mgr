<?php

namespace Tournament\Model\Participant;

use Respect\Validation\Validator as v;

class Participant extends \Tournament\Model\Base\DbItem
{
   public function __construct(
      ?int $id,                           // Unique identifier for the participant
      public readonly int $tournament_id, // Identifier for the tournament this participant belongs to
      public string $lastname,            // Last name of the participant
      public string $firstname,           // First name of the participant
      public ?string $club = null,        // Club/Association of participant
      public CategoryAssignmentCollection $categories = new CategoryAssignmentCollection() // Categories the participant is registered in
   ) {
      $this->id = $id;
   }

   /* get the validation rules for the participant */
   public static function validationRules(): array
   {
      return [
         'lastname'  => v::stringType()->notEmpty()->length(1, max: 255),
         'firstname' => v::stringType()->notEmpty()->length(1, max: 255),
         'club'      => v::stringType()->length(max:127),
      ];
   }

   public function updateFromArray(array $data): void
   {
      if (isset($data['lastname'])) $this->lastname = $data['lastname'];
      if (isset($data['firstname'])) $this->firstname = $data['firstname'];
      if (array_key_exists('club', $data)) $this->club = $data['club']; // null is allowed here
   }

}