<?php declare(strict_types=1);

namespace Tournament\Model\Category;

use Respect\Validation\Validator as v;
use Tournament\Model\MatchPointHandler\MatchPointHandler;

/**
 * Represents a competition category within a tournament.
 */
class Category implements \Tournament\Model\Base\DbItem
{
   use \Tournament\Model\Base\DbItemTrait;

   public CategoryConfiguration $config; // Configuration for the category
   public CategoryMode $mode;            // Tournament mode (e.g., "ko", "pool", "combined")

   private MatchPointHandler $mpHdl;

   public function __construct(
      public ?int $id,                       // Unique identifier for the category
      public readonly int $tournament_id,    // Identifier for the tournament this category belongs to
      public string $name,                   // Name of the category (e.g., "Juniors -60kg")
      string|CategoryMode $mode = CategoryMode::KO, // Tournament mode (e.g., "ko", "pool", "combined")
      public bool $team_mode = false,        // false - single participants, true - teams category
      ?CategoryConfiguration $config = null, // detailled configuration for the category (e.g., seeding strategy, pool sizes)
   )
   {
      $this->config = $config ?? new CategoryConfiguration();
      $this->mode = is_string($mode) ? CategoryMode::from($mode) : $mode;
   }

   /* validation rules for the category */
   public static function validationRules(): array
   {
      return [
         'mode' => v::in(array_column(CategoryMode::cases(), 'value')),
         'name' => v::stringType()->notEmpty()->length(1, max: 100),
         'team_mode' => v::BoolVal(),
      ]
      + CategoryConfiguration::validationRules();
   }

   public function updateFromArray(array $data): void
   {
      // Updates the category's properties from an associative array.
      if (isset($data['name'])) $this->name = $data['name'];
      if (isset($data['mode'])) $this->mode = CategoryMode::from($data['mode']);
      if (isset($data['team_mode'])) $this->team_mode = (bool)$data['team_mode'];
      $this->config->updateFromArray($data);
   }

   protected function convertValue($key, $value): mixed
   {
      if( $value instanceof CategoryConfiguration )
      {
         return $value->json();
      }
      throw new \UnexpectedValueException(get_class($this) . ": Unexpected DbItem attribute for key '$key' of type " . get_class($value) ?? gettype($value));
   }

   /**
    * put creation of MatchPointHandler into Category,
    * as it might depend on specific Category-wide configurations
    * (e.g. whether Hansokus cause Ippons)
    */
   public function getMatchPointHandler(): MatchPointHandler
   {
      $this->mpHdl ??= new \Tournament\Model\MatchPointHandler\KendoMatchPointHandler();
      return $this->mpHdl;
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
   public function getMatchCreationHandler(): \Tournament\Model\MatchCreationHandler\MatchCreationHandler
   {
      return new \Tournament\Model\MatchCreationHandler\GenericMatchCreationHandler($this);
   }

   /**
    * PlacmentCostCalculator
    */
   public function getPlacementCostCalculator(): \Tournament\Model\PlacementCostCalculator\PlacementCostCalculator
   {
      $config = [];
      if( $this->config->ignore_club ) $config['club_weight'] = 0;
      return new \Tournament\Model\PlacementCostCalculator\GenericPlacementCostCalculator(...$config);
   }
}

