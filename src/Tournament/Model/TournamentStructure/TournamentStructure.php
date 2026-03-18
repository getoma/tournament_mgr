<?php

namespace Tournament\Model\TournamentStructure;

use Tournament\Model\TournamentStructure\MatchSlot\ParticipantSlot;
use Tournament\Model\TournamentStructure\MatchSlot\MatchWinnerSlot;
use Tournament\Model\TournamentStructure\MatchSlot\PoolWinnerSlot;
use Tournament\Model\TournamentStructure\MatchSlot\ByeSlot;
use Tournament\Model\TournamentStructure\MatchNode\KoNode;
use Tournament\Model\TournamentStructure\KoChunk;
use Tournament\Model\TournamentStructure\Pool\PoolCollection;

use Tournament\Model\Area\AreaCollection;
use Tournament\Model\Category\Category;
use Tournament\Model\Category\CategoryMode;
use Tournament\Model\MatchRecord\MatchRecordCollection;
use Tournament\Model\Participant\ParticipantCollection;
use Tournament\Model\TournamentStructure\MatchNode\MatchNode;
use Tournament\Model\TournamentStructure\MatchNode\MatchNodeCollection;
use Tournament\Model\TournamentStructure\MatchNode\MatchRoundCollection;
use Tournament\Model\TournamentStructure\Pool\Pool;

/**
 * A class to generate and manage a Tournament structure, consisting of Pools and/or a KO tree
 * to fully load up the structure, the various loading methods need to be called in a proper order:
 * - __construct()
 * - generateStructure()
 * - loadParticipants()
 * - loadMatchRecords()
 */
class TournamentStructure
{
   /** @var PoolCollection list of pools */
   public PoolCollection $pools;

   /** @var KoNode finale node of the KO tree */
   public ?KoNode $ko = null;

   /** @var KoChunk[] list of KO clusters*/
   private array $clusters = [];

   /** @var ?int number of finale rounds after the clusters*/
   private int $finale_rounds_cnt = 0;

   /** @var ParticipantCollection of all participants that don't have a start place right now */
   public ParticipantCollection $unmapped_participants;

   /** @var ParticipantHandler */
   private readonly ParticipantHandler $participantHandler;

   public function __construct(
      public Category $category,
      public AreaCollection $areas
   )
   {
      $this->pools = PoolCollection::new();
      $this->unmapped_participants = ParticipantCollection::new();
      $this->participantHandler = new ParticipantHandler($this);
   }

   /**
    * generate the whole structure - it's a rather expensive operation,
    * therefore it is not done inside the constructor
    */
   public function generateStructure()
   {
      if ($this->category->mode === CategoryMode::KO)
      {
         $this->ko = $this->fillKO( $this->createKoFirstRound($this->category->config->num_rounds) );
      }
      elseif ($this->category->mode === CategoryMode::Pool)
      {
         throw new \DomainException('Pure pool mode currently not supported');
      }
      elseif ($this->category->mode === CategoryMode::Combined)
      {
         $cfg = $this->category->config;
         $this->pools = $this->createAutoPools($cfg->num_rounds, $cfg->pool_winners, $cfg->max_pools);
         $this->ko = $this->fillKO( $this->createPoolKoFirstRound($this->pools, $this->category->config->pool_winners) );
      }
      else
      {
         throw new \DomainException('Unknown tournament mode: ' . $this->category->mode->value);
      }

      if( !$this->areas->empty() )
      {
         if ($this->ko)
         {
            AreaAssignmentHandler::assignKoAreas($this->ko, $this->areas, $this->category->config->area_cluster);
         }
         if (!$this->pools->empty())
         {
            AreaAssignmentHandler::assignPoolAreas($this->pools, $this->areas);
         }
      }
   }

   /**
    * extract the pool id from a slot name - pool slot naming is defined by ParticipantHandler
    */
   public static function getPoolIdFromSlotName(string $slotName, bool $throw_if_invalid = true): ?string
   {
      return Pool::getPoolIdFromSlotName($slotName, $throw_if_invalid);
   }

   /**
    * extract the KoNode name from a slot name - Node slot naming is defined by the KoNode class
    */
   public static function getKoNodeNameFromSlotName(string $slotName, bool $throw_if_invalid = true): ?string
   {
      return KoNode::getNodeNameFromSlotName($slotName, $throw_if_invalid);
   }

   /**
    * find a node within this structure based on its name
    * @param $name - the node name to search for
    * @param $pool_hint - null: node may be in pools or KO, don't know | false: node is in KO part | string: node should be in this pool
    */
   public function findNode(string $name, mixed $pool_hint = null): ?MatchNode
   {
      $result = null;

      if (!$pool_hint) // try to find it in KO first
      {
         $result = $this->ko->findByName($name);
         if ($result || ($pool_hint === false)) return $result; // Node is not supposed to be inside pools, return whatever we found, even if nothing
      }

      if( $this->pools->empty() ) return null;

      $pools = $pool_hint? [$this->pools[$pool_hint] ?? throw new \OutOfBoundsException('Pool not found: ' . $pool_hint)] : $this->pools;
      foreach( $pools as $pool )
      {
         /** @var Pool $pool */
         $result = $pool->getMatchList()->findNode($name);
         if( $result ) break;
      }

      return $result;
   }

   /**
    * load a collection of slot-assigned participants into the Tournament structure
    */
   public function loadParticipants(ParticipantCollection $participants): void
   {
      $this->participantHandler->loadParticipants($participants);
   }

   /**
    * populate a TournamentStructure with a collection of Participants
    */
   public function populate(ParticipantCollection $participants): ParticipantCollection
   {
      return $this->participantHandler->populate($participants);
   }

   /**
    * load a list of explicit area mappings into the structure
    */
   public function loadAreaMappings(AreaMapping $mapping)
   {
      foreach( $mapping->pool_mappings as $poolName => $area_id )
      {
         $area = $this->areas[$area_id];
         $this->pools[$poolName]->setArea($area);
      }

      if( $mapping->node_mappings )
      {
         foreach( $this->ko->getMatchList() as $node )
         {
            if( $area_id = $mapping->node_mappings[$node->getName()]??null )
            {
               $area = $this->areas[$area_id];
               $node->setArea($area);
            }
         }
      }
   }

   /**
    * load a list of match records into the structure
    */
   public function loadMatchRecords(MatchRecordCollection $matchRecords)
   {
      foreach ($this->pools as $pool)
      {
         $pool->setMatchRecords($matchRecords);
      }
      if ($this->ko)
      {
         $this->ko->setMatchRecords($matchRecords);
      }
   }

   /**
    * get the list of finale rounds:
    * - if there are clusters, all rounds after cluster execution
    * - if there are no clusters, return the full KO structure
    */
   public function getFinaleRounds(): MatchRoundCollection
   {
      return $this->ko->getRounds(-$this->finale_rounds_cnt);
   }

   /**
    * get an ordered list of all matches in this structure
    */
   public function getMatchList(): MatchNodeCollection
   {
      $result = MatchNodeCollection::new();
      /* collect all pools in order */
      foreach( $this->pools as $pool )
      {
         $result->mergeInPlace($pool->getMatchList());
      }
      /* if there are clusters defined, collect those in order - each cluster added fully before the next one */
      if( $this->clusters )
      {
         foreach( $this->clusters as $cluster )
         {
            $result->mergeInPlace($cluster->root->getMatchList());
         }
      }
      /* add the rest of the KO structure */
      $result->mergeInPlace($this->getFinaleRounds()->flatten());
      return $result;
   }

   /**
    * create a list of matches for the first round of a knock-out structure
    */
   private function createKoFirstRound(int $numRounds): MatchNodeCollection
   {
      return MatchNodeCollection::new( array_map(
         fn($i) => new KoNode($i, $this->category, new ParticipantSlot(), new ParticipantSlot()),
         range(1, pow(2, $numRounds - 1))
      ));
   }

   /**
    * Create random pools based on the participants.
    * This method will create pools with a maximum size defined in the category configuration.
    * Autogeneration of pools is only valid for combined mode.
    */
   private function createAutoPools(int $numRounds, ?int $winnersPerPool = null, int $maxPools = 0): PoolCollection
   {
      $winnersPerPool ??= 2;
      $numSlots = pow(2, $numRounds);
      $numPools = pow(2, floor(log($numSlots / $winnersPerPool, 2))); // number of pools, must be a power of 2, rest filled up with BYEs
      if( $maxPools > 0 ) $numPools = min($maxPools, $numPools);
      return PoolCollection::new( array_map(fn($i) => new Pool($i+1, $this->category), range(0, $numPools - 1)) );
   }

   private static function getByeIndexes(int $numBYEs, int $count): array
   {
      $step = ($count-1)/($numBYEs-1);
      $maxIdx = $count-1;
      $halfBYEs = ceil($numBYEs/2);

      $result = [];
      for ($i = 0; $i < $halfBYEs; $i++)
      {
         $pos = (int)round($i * $step);
         $result[] = $pos;
         if( count($result) < $numBYEs ) $result[] = $maxIdx - $pos;
      }
      sort($result);
      return $result;
   }

   /**
    * Create a knockout structure based on the pools.
    * This method will distribute the pool winner start slots so that participants of the first pool will meet
    * as late as possible again.
    * If there are any wildcards, this algorithm will assign them to the higher ranking participants according
    * pool results first.
    *
    * This is done by iteratively halving the pool winner lists into smaller clusters, with a conflict resolution
    * algorithm that determines the best canditates to add to each smaller chunk by a simple cost analysis.
    *
    * Current algorithm is fine-tuned to the setup of the DKenB DEM tournament. TBD to generalize this implementation
    * Constraints:
    * 1) only 2 winners per pool
    * 2) at most the same number of BYE slots as there are pools
    */
   private function createPoolKoFirstRound(PoolCollection $pools, ?int $winnersPerPool = null): MatchNodeCollection
   {
      $winnersPerPool = 2; // for now, only support 2 winners per pool

      $poolCount = $pools->count();
      $pools = $pools->values(); // get 0-based indexing of pools
      $startSlotCount = $winnersPerPool * $poolCount; // number of needed starting slots
      $maxSlotCount = pow(2, ceil(log($startSlotCount, 2))); // number of existing starting slots for full tree
      $start_slots = array_fill(0, $maxSlotCount, null);  // list of slots
      $byeCount = $maxSlotCount - $startSlotCount;

      /* place BYEs */
      if( $byeCount )
      {
         if( $byeCount > $poolCount ) throw new \LogicException("more BYEs than pools currently not supported");
         $byeIdxList = static::getByeIndexes($byeCount/2, ceil($poolCount/2)); // symmetric indexes for bye slots
         foreach( $byeIdxList as $idx )
         {
            $start_slots[2*$idx+1] = new ByeSlot();
            $start_slots[$maxSlotCount-2*$idx-1] = new ByeSlot();
         }
      }

      /* place first ranked
       * - even-numbered pools start to center
       * - odd-numbered pools end to center
       * Checking for bye slots not needed - byeSlots are always on white slots,
       * while we are now placing everyone into red slots
       */
      for( $i = 0; $i < $poolCount; $i += 2 )
      {
         $start_slots[$i] = new PoolWinnerSlot($pools[$i], 1);
         $start_slots[$maxSlotCount-$i-2] = new PoolWinnerSlot($pools[$i+1], 1);
      }

      /* now fill everything up with second ranked
       * - even-numbered pools end to center
       * - odd-numbered pools start to center
       * fill up any still unused slot
       */
      $first_half_pos = 0;
      $second_half_pos = $maxSlotCount - 1;
      for( $i = 0; $i < $poolCount; $i += 2 )
      {
         while( $start_slots[$first_half_pos] !== null  ) $first_half_pos += 1;
         $start_slots[$first_half_pos] = new PoolWinnerSlot($pools[$i+1], 2);
         while( $start_slots[$second_half_pos] !== null ) $second_half_pos -= 1;
         $start_slots[$second_half_pos] = new PoolWinnerSlot($pools[$i], 2);
      }

      /* done, now generate the first round of matches from the start slots */
      $firstRound = MatchNodeCollection::new(); // current round of matches, will be filled with MatchNode objects
      $nextMatchId = 1; // local match ID, starting at 1
      foreach( array_chunk($start_slots, 2) as $slots )
      {
         $firstRound[] = new KoNode($nextMatchId++, $this->category, $slots[0], $slots[1]);
      }
      return $firstRound;
   }

   /**
    * complete the KO tree from a list of first-round-matches.
    * Store the final node in the class and set the number of finale rounds
    */
   private function fillKO(MatchNodeCollection $currentRound): KoNode
   {
      $nextMatchId = $currentRound->count()+1; // next match ID, starting after the last match in the first round
      // use the current round to create the next round until we reach the finale
      while (count($currentRound) > 1)
      {
         $previousRound = $currentRound;
         $currentRound = MatchNodeCollection::new();
         for ($i = 0; $i < count($previousRound); $i += 2)
         {
            $slotRed   = new MatchWinnerSlot($previousRound[$i]);
            $slotWhite = new MatchWinnerSlot($previousRound[$i + 1]);
            $currentRound[] = new KoNode($nextMatchId++, $this->category, slotRed: $slotRed, slotWhite: $slotWhite);
         }
      }
      return $currentRound->first();
   }
}
