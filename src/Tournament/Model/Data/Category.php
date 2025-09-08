<?php

namespace Tournament\Model\Data;

use Respect\Validation\Validator as v;

class Category
{
   // Represents a competition category within a tournament.
   /* CREATE TABLE categories (
      id INT AUTO_INCREMENT PRIMARY KEY,
      tournament_id INT NOT NULL,
      name VARCHAR(255) NOT NULL,
      mode ENUM('pool', 'ko', 'combined') NOT NULL,
      config_json JSON DEFAULT NULL, -- detailled configuration
      structure_json JSON DEFAULT NULL, -- Turnierbaum als json
      FOREIGN KEY (tournament_id) REFERENCES tournaments(id) ON DELETE CASCADE,
      CONSTRAINT UC_CATEGORY UNIQUE (tournament_id, name)
  ); */

   public CategoryConfiguration $config; // Configuration for the category

   public function __construct(
      public ?int $id,              // Unique identifier for the category
      public int $tournament_id,    // Identifier for the tournament this category belongs to
      public string $name,          // Name of the category (e.g., "Juniors -60kg")
      public string $mode = 'ko',   // Tournament mode (e.g., "ko", "pool", "combined")
      array $config = [],           // detailled configuration for the category (e.g., seeding strategy, pool sizes)
   )
   {
      $this->config = CategoryConfiguration::fromArray($config);
   }

   public static function get_modes(): array
   {
      // Returns the available tournament modes.
      return ['ko' => 'KO', 'pool' => 'Pool', 'combined' => 'Combined'];
   }

   public static function get_seedings(): array
   {
      // Returns the available seeding strategies.
      return ['random' => 'Random', 'manual' => 'Manual'];
   }

   /* validation rules for the category */
   public static function getValidationRules(string $context = 'update'): array
   {
      $shared_rules = [
         'mode' => v::in(array_keys(self::get_modes())),
      ];

      $creation_rules = [
         'name' => v::stringType()->notEmpty()->length(1, max: 100),
      ];

      switch ($context)
      {
         case 'create':
         case 'update':
         default:
            return array_merge($creation_rules, $shared_rules);

         case 'details':
            return array_merge($shared_rules, CategoryConfiguration::getValidationRules());

         case 'details_only':
            return CategoryConfiguration::getValidationRules();
      }
   }
}

class CategoryConfiguration
{
   // Represents the configuration for a competition category.
   public function __construct(
      public int $num_rounds = 4,        // Number of rounds in the tournament
      public ?int $pool_winners = null,  // Number of winners from each pool (if applicable)
      public ?int $area_cluster = null,  // Number of concurrent participants per area (if applicable)
   )
   {
   }

   public static function getValidationRules(): array
   {
      // Returns validation rules for category configuration.
      return [
         'pool_winners'  => v::optional(v::numericVal()->intVal()->min(1)->max(3)),
         'num_rounds'    => v::numericVal()->intVal()->min(2)->max(10),     // Number of rounds in the tournament
         'area_cluster'  => v::optional(v::numericVal()->intVal()->min(1)), // clustering of area distribution
      ];
   }

   public static function fromArray(array $data): self
   {
      // Creates a CategoryConfiguration instance from an associative array.
      return new self(
         num_rounds:   (int)($data['num_rounds']??0)   ?: 4, // Default to 4 rounds if not specified
         pool_winners: (int)($data['pool_winners']??0) ?: null,
         area_cluster: (int)($data['area_cluster']??0) ?: null,
      );
   }

   public function toArray(): array
   {
      // Converts the configuration to an associative array, skipping null values.
      $data = get_object_vars($this);
      return array_filter($data, function ($value)
      {
         return $value !== null;
      });
   }
}
