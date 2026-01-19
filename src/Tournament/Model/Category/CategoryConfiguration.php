<?php

namespace Tournament\Model\Category;

use Respect\Validation\Validator as v;


class CategoryConfiguration
{
   // Represents the configuration for a competition category.
   public function __construct(
      public int $num_rounds = 4,        // Number of rounds in the tournament
      public ?int $pool_winners = null,  // Number of winners from each pool (if applicable)
      public ?int $area_cluster = null,  // Number of concurrent participants per area (if applicable)
      public ?int $max_pools = 0,        // Max number of pools (0 = unlimited)
   )
   {
      /* force-reset invalid values */
      $rules = self::validationRules();
      if (!$rules['num_rounds']->isValid($this->num_rounds) || !isset($this->num_rounds) ) $this->num_rounds = 4;
      if (!$rules['pool_winners']->isValid($this->pool_winners)) $this->pool_winners = null;
      if (!$rules['area_cluster']->isValid($this->area_cluster)) $this->area_cluster = null;
      if (!$rules['max_pools']->isValid($this->max_pools)) $this->max_pools = 0;
   }

   public static function validationRules(): array
   {
      // Returns validation rules for category configuration.
      return [
         'num_rounds'    => v::optional(v::numericVal()->intVal()->min(2)->max(10)), // Number of rounds in the tournament
         'pool_winners'  => v::optional(v::numericVal()->intVal()->min(1)->max(3)),  // number of winners from each pool
         'area_cluster'  => v::optional(v::numericVal()->intVal()->min(1)),          // clustering of area distribution
         'max_pools'     => v::optional(v::numericVal()->intVal()->min(0)),          // Max number of pools (0 = unlimited)
      ];
   }

   public function updateFromArray(array $data): void
   {
      // Updates the configuration properties from an associative array.
      if (isset($data['num_rounds']))   $this->num_rounds   = (int)$data['num_rounds'];
      if (isset($data['pool_winners'])) $this->pool_winners = empty($data['pool_winners']) ? null : (int)$data['pool_winners'];
      if (isset($data['area_cluster'])) $this->area_cluster = empty($data['area_cluster']) ? null : (int)$data['area_cluster'];
      if (isset($data['max_pools']))    $this->max_pools    = (int)$data['max_pools'];
   }

   public static function load(string $json): self
   {
      // decode json
      $data = json_decode($json, associative:true, depth: 2, flags: JSON_THROW_ON_ERROR);
      // intersect with the validation rule keys to remove obsolete keys (e.g. after SW update)
      $data = array_intersect_key($data, self::validationRules());
      // create the instance
      return new self(...$data);
   }

   public function json(): string
   {
      // Converts the configuration to an associative array, skipping empty values.
      $data = array_filter(get_object_vars($this));
      return json_encode($data);
   }
}