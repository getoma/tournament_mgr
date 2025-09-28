<?php

namespace Tournament\Model\Data;

use Respect\Validation\Validator as v;

class Participant
{
   // Represents a participant in a tournament.
   /* CREATE TABLE participants (
         id INT AUTO_INCREMENT PRIMARY KEY,
         tournament_id INT NOT NULL,
         lastname VARCHAR(255) NOT NULL,
         firstname VARCHAR(255) NOT NULL,
         FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
         CONSTRAINT UC_PARTICIPANT_NAME UNIQUE (tournament_id, lastname, firstname));
   */

   public function __construct(
      public int $id,           // Unique identifier for the participant
      public int $tournament_id, // Identifier for the tournament this participant belongs to
      public string $lastname,   // Last name of the participant
      public string $firstname,  // First name of the participant
      public CategoryCollection $categories = new CategoryCollection() // Categories the participant is registered in
   ) {
   }

   /* get the validation rules for the participant */
   public static function getValidationRules(string $context = 'update'): array
   {
      return [
         'lastname' => v::stringType()->notEmpty()->length(1, max: 255),
         'firstname' => v::stringType()->notEmpty()->length(1, max: 255)
      ];
   }

}