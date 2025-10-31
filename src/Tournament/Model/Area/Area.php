<?php

namespace Tournament\Model\Area;

use Respect\Validation\Validator as v;

class Area extends \Tournament\Model\Base\DbItem
{
   // Represents an area in a tournament
   public function __construct(
      ?int $id,                           // Unique identifier for the area
      public readonly int $tournament_id, // Identifier for the tournament this area belongs to
      public string $name,                // Name of the area (e.g., "Area A")
   ) {
      $this->id = $id;
   }

   /* get the validation rules for the area */
   public static function validationRules(): array
   {
      return [
         'name' => v::stringType()->notEmpty()->length(1, max: 100)
                   ->noneOf(v::equals('create'), v::equals('update'), v::equals('delete')),
      ];
   }

   public function updateFromArray(array $data): void
   {
      if (isset($data['name'])) $this->name = $data['name'];
   }
}
