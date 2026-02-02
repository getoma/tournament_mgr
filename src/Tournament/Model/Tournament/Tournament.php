<?php

namespace Tournament\Model\Tournament;

use Respect\Validation\Validator as v;
use Tournament\Model\Category\CategoryCollection;

class Tournament implements \Tournament\Model\Base\DbItem
{
   use \Tournament\Model\Base\DbItemTrait;

   public TournamentStatus $status;

   public function __construct(
      public ?int $id,              // Unique identifier for the tournament
      public string $name,          // Name of the tournament
      public string $date,          // Date of the tournament
      public ?string $notes = null, // Additional notes about the tournament
      TournamentStatus|string $status = TournamentStatus::Planning, // Status of the tournament (e.g., scheduled, ongoing, completed)
      public CategoryCollection $categories = new CategoryCollection()
   )
   {
      $this->status = $status instanceof TournamentStatus ? $status : TournamentStatus::from($status);
   }

   public function updateFromArray(array $data): void
   {
      /* status is to be handled explicitly via policy handling, and should be set explicitly */
      if (isset($data['name'])) $this->name = $data['name'];
      if (isset($data['date'])) $this->date = $data['date'];
      if (isset($data['notes'])) $this->notes = $data['notes'];
   }

   /* get the validation rules for the tournament */
   protected static function validationRules(): array
   {
      return [
         'name'   => v::stringType()->notEmpty()->length(1, max: 100)
                     ->noneOf(v::equals('create'), v::equals('update'), v::equals('delete')),
         'date'   => v::date('Y-m-d'),
         'notes'  => v::optional(v::stringType()->length(0, 500)),
      ];
   }

   /* get the state handler */
   public function getStateHandler()
   {
      return new TournamentStateHandler($this);
   }
}
