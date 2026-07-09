<?php declare(strict_types=1);

namespace Tournament\Model\Category;

use Respect\Validation\Validator as v;

final class CategoryConfiguration
{
   use \Base\Model\FlatJsonTrait;

   // internal helper members
   private static array $validation_rules;

   // Represents the configuration for a competition category.
   public function __construct(

      public int $num_rounds   = 4 {     // Number of rounds in the tournament
         set(int|string $value) => $this->setter(__PROPERTY__, $value);
      },
      public int $pool_winners = 2 {     // Number of winners from each pool (if applicable)
         set(int|string $value) => $this->setter(__PROPERTY__, $value);
      },
      public int $team_size = 5 {        // participants per team in team modes
         set(int|string $value) => $this->setter(__PROPERTY__, $value);
      },
      public int $area_cluster = 0 {     // Number of concurrent participants per area (if applicable) (0 = no clustering),
         set(int|string $value) => $this->setter(__PROPERTY__, $value);
      },
      public int $max_pools    = 0 {     // Max number of pools (0 = unlimited)
         set(int|string $value) => $this->setter(__PROPERTY__, $value);
      },
      public bool $ignore_club = false { // consider the club memberships at starting slot seeding
         set(bool|string $value) => $this->setter(__PROPERTY__, $value);
      },
   )
   {
   }

   /**
    * return all Respect\Validation rules for sanitizing any user inputs to configuration
    */
   public static function validationRules(): array
   {
      /* construct validation rules only once here */
      self::$validation_rules ??= [
         'num_rounds'    => v::optional(v::intVal()->min(2)->max(10)), // Number of rounds in the tournament
         'pool_winners'  => v::optional(v::intVal()->min(1)->max(3)),  // number of winners from each pool
         'team_size'     => v::optional(v::intVal()->min(2)->max(9)),  // participants per team in this category
         'area_cluster'  => v::optional(v::intVal()->min(0)),          // clustering of area distribution
         'max_pools'     => v::optional(v::intVal()->min(0)),          // Max number of pools (0 = unlimited)
         'ignore_club'   => v::optional(v::BoolVal()),
      ];
      return self::$validation_rules;
   }
}