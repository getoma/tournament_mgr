<?php

namespace Tournament\Model\Area;

use Respect\Validation\Validator as v;

class Area
{
   // Represents an area in a tournament.
   /* CREATE TABLE areas (
      id INT AUTO_INCREMENT PRIMARY KEY,
      tournament_id INT NOT NULL,
      name VARCHAR(255) NOT NULL,
      FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
      CONSTRAINT UC_AREA UNIQUE (tournament_id, name)
   );
   */

   public function __construct(
      public ?int $id,           // Unique identifier for the area
      public int $tournament_id, // Identifier for the tournament this area belongs to
      public string $name,       // Name of the area (e.g., "Area A")
   ) {
   }

   /* get the validation rules for the area */
   public static function getValidationRules(string $context = 'update'): array
   {
      // $context doesn't matter
      return [
         'name' => v::stringType()->notEmpty()->length(1, max: 100)
            ->noneOf(v::equals('create'), v::equals('update'), v::equals('delete')),
      ];
   }
}
