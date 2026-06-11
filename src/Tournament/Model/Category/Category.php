<?php declare(strict_types=1);

namespace Tournament\Model\Category;

use Respect\Validation\Validator as v;
use Tournament\Model\MatchPointHandler\MatchPointHandler;
use Tournament\Model\TournamentStructure\MatchNodeFactory;
use Tournament\Model\TournamentStructure\TournamentStructure;

/**
 * Represents a competition category within a tournament.
 */
class Category implements \Tournament\Model\Base\DbItem
{
   use \Tournament\Model\Base\DbItemTrait;

   public CategoryConfiguration $config; // Configuration for the category
   public CategoryMode $mode;            // Tournament mode (e.g., "ko", "pool", "combined")

   private MatchPointHandler $mpHdl;

   private TournamentStructure $structure;

   /**
    * @var callable optional hook to load structure on demand
    */
   private mixed $structureLoader;

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

   protected function convertValue(string $key, mixed $value): mixed
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
    * same for MatchRankHandler
    */
   public function getMatchRankHandler(): \Tournament\Model\MatchRankHandler\MatchRankHandler
   {
      return new \Tournament\Model\MatchRankHandler\GenericMatchRankHandler($this->getMatchPointHandler());
   }

   /**
    * same same for PairingHandler
    */
   public function getMatchCreationHandler(): \Tournament\Model\MatchCreationHandler\MatchCreationHandler
   {
      return new \Tournament\Model\MatchCreationHandler\GenericMatchCreationHandler( new MatchNodeFactory($this) );
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

   /**
    * store a loaded structure, or a hook to load a structure if needed
    */
   public function setTournamentStructure(callable|TournamentStructure $struc): void
   {
      if ($struc instanceof TournamentStructure)
      {
         /* generating the tournament structure is quite expensive - add an exception here in case the
          * structure is set more than once, which would mean that on some path it is also generated
          * more than once.
          *
          * TODO: make it a warning later to not break in production
          */
         if( isset($this->structure) && $this->structure !== $struc )
         {
            throw new \LogicException('Multiple TournamentStructure generations happened!');
         }
         $this->structure = $struc;
      }
      else if( is_callable($struc) )
      {
         $this->structureLoader = $struc;
      }
      else
      {
         throw new \InvalidArgumentException('unsupported structure type: ' . gettype($struc));
      }
   }

   /**
    * return a preset structure
    */
   public function getTournamentStructure(): TournamentStructure
   {
      if( isset($this->structure) )
      {
         return $this->structure;
      }
      else if( isset($this->structureLoader) )
      {
         $load = $this->structureLoader;
         $struc = $load($this);
         if( !($struc instanceof TournamentStructure) )
         {
            throw new \DomainException('structure loading hook returned unexpected value of type ' . gettype($struc));
         }
         $this->structure = $struc;
         return $struc;
      }
      else
      {
         throw new \LogicException('attempt to get unprepared TournamentStructure from Category');
      }
   }
}

