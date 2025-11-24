<?php

namespace Tournament\Model\Category;

use Respect\Validation\Validator as v;

class Category extends \Tournament\Model\Base\DbItem
{
   // Represents a competition category within a tournament.

   public CategoryConfiguration $config; // Configuration for the category
   public CategoryMode $mode;            // Tournament mode (e.g., "ko", "pool", "combined")

   public function __construct(
      ?int $id,                              // Unique identifier for the category
      public readonly int $tournament_id,    // Identifier for the tournament this category belongs to
      public string $name,                   // Name of the category (e.g., "Juniors -60kg")
      string|CategoryMode $mode = CategoryMode::KO, // Tournament mode (e.g., "ko", "pool", "combined")
      ?CategoryConfiguration $config = null, // detailled configuration for the category (e.g., seeding strategy, pool sizes)
   )
   {
      $this->id = $id;
      $this->config = $config ?? new CategoryConfiguration();
      $this->mode = is_string($mode) ? CategoryMode::from($mode) : $mode;
   }

   /* validation rules for the category */
   public static function validationRules(): array
   {
      return [
         'mode' => v::in(array_column(CategoryMode::cases(), 'value')),
         'name' => v::stringType()->notEmpty()->length(1, max: 100),
      ]
      + CategoryConfiguration::validationRules();
   }

   public function updateFromArray(array $data): void
   {
      // Updates the category's properties from an associative array.
      if (isset($data['name'])) $this->name = $data['name'];
      if (isset($data['mode'])) $this->mode = CategoryMode::from($data['mode']);
      $this->config->updateFromArray($data);
   }

   /**
    * put creation of MatchPointHandler into Category,
    * as it might depend on specific Category-wide configurations
    * (e.g. whether Hansokus cause Ippons)
    */
   public function getMatchPointHandler(): \Tournament\Model\MatchPointHandler\MatchPointHandler
   {
      return new \Tournament\Model\MatchPointHandler\KendoMatchPointHandler();
   }

   /**
    * same for PoolRankHandler
    */
   public function getPoolRankHandler(): \Tournament\Model\PoolRankHandler\PoolRankHandler
   {
      return new \Tournament\Model\PoolRankHandler\GenericPoolRankHandler($this->getMatchPointHandler());
   }

   /**
    * same same for PairingHandler
    */
   public function getMatchPairingHandler(): \Tournament\Model\MatchPairingHandler\MatchPairingHandler
   {
      return new \Tournament\Model\MatchPairingHandler\GenericMatchPairingHandler();
   }
}

