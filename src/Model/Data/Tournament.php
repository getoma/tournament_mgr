<?php

namespace App\Model\Data;

use Respect\Validation\Validator as v;

class Tournament
{
   // Represents a tournament.
   /* CREATE TABLE tournaments (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(255) NOT NULL,
      date DATE NOT NULL,
      status enum('planning','active','done') NOT NULL default 'planning',
      notes TEXT default '',
      CONSTRAINT UC_TOURNAMENT UNIQUE (name, date)
   ); */

   public function __construct(
      public ?int $id = null,             // Unique identifier for the tournament         
      public string $name,                // Name of the tournament
      public string $date,                // Date of the tournament
      public string $status = 'planning', // Status of the tournament (e.g., scheduled, ongoing, completed)
      public ?string $notes = null        // Additional notes about the tournament
   ) {
   }

   /* get the validation rules for the tournament */
   public static function getValidationRules(string $context = 'update'): array
   {
      $rules = [
         'name' => v::stringType()->notEmpty()->length(1, max: 100)
            ->noneOf(v::equals('create'), v::equals('update'), v::equals('delete')),
         'date' => v::date('Y-m-d'),
         'notes' => v::optional(v::stringType()->length(0, 500))
      ];

      if ($context === 'update')
      {
         /* nothing to add */
      }

      return $rules;

   }


}
