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
      return ParticipantHandler::getPoolIdFromSlotName($slotName, $throw_if_invalid);
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

   /**
    * Create a knockout structure based on the pools.
    * This method will distribute the pool winner start slots so that participants of the first pool will meet
    * as late as possible again.
    * If there are any wildcards, this algorithm will assign them to the higher ranking participants according
    * pool results first.
    *
    * This is done by iteratively halving the pool winner lists into smaller clusters, with a conflict resolution
    * algorithm that determines the best canditates to add to each smaller chunk by a simple cost analysis.
    */
   private function createPoolKoFirstRound(PoolCollection $pools, ?int $winnersPerPool = null): MatchNodeCollection
   {
      $winnersPerPool ??= 2;
      $poolsPerPlace = [array_fill(0, $winnersPerPool, $pools->keys())];
      $splitTarget = $winnersPerPool * $pools->count(); // target for splitting the pool winners into two halves
      $dummy_pool_id = $pools->count() + 1; // track IDs of dummy pools during splitting, which will be replaced by a wildcard later.
      while ($splitTarget >= 4)
      {
         /* track usage of each pool while distributing pool winners, use pool ids as key */
         $nextRound = [];
         $splitTarget = ceil($splitTarget / 2); // halve the target for the next round, rounding up
         /* split down each current chunk into two new clusters */
         foreach ($poolsPerPlace as $chunk)
         {
            /* add a dummy pool entry if not an even number of entries
             * always add them at lowest rank, so they will be assigned to top ranks at match pairing
             */
            if(array_sum(array_map('count', $chunk)) % 2) array_unshift($chunk[$winnersPerPool - 1], $dummy_pool_id++);

            /* chunk format: [ 0 => [pool1_idx, pool2_idx, ...], 1 => [pool1_idx, pool2_idx, ...] ] */
            $poolUsage = array_fill_keys(array_merge(...$chunk), 0); // to track usage of each available pool
            $split_chunk = []; // the separated chunk for the next round
            $split_count = 0; // count how many pools we have split so far
            while ($split_count < $splitTarget)
            {
               /* select one new candidate from each placement rank, which will result into selecting
                * from a different pool each time - as a result, pool members will be separated across clusters
                */
               for ($i = 0; ($i < $winnersPerPool) && ($split_count < $splitTarget); ++$i)
               {
                  // find the pool with the least usage
                  $chunkPoolUsage = array_intersect_key($poolUsage, array_flip($chunk[$i]));
                  if(empty($chunkPoolUsage)) continue;
                  $poolId = array_search(min($chunkPoolUsage), $chunkPoolUsage);
                  // add the pool to the split chunk
                  $split_chunk[$i][] = $poolId;
                  // and remove it from the current chunk
                  $chunk[$i] = array_diff($chunk[$i], [$poolId]);
                  // increment the usage of the pool
                  $poolUsage[$poolId] += $winnersPerPool - $i; // the higher the rank, the higher the recorded usage
                  $split_count++;
               }
            }

            /* prepare next round of splitting */
            $nextRound[] = $split_chunk;
            $nextRound[] = $chunk;
         }
         $poolsPerPlace = $nextRound; // set the next round of pools to the current round
      }

      /* now we have an array of clusters in $poolsPerPlace,
       * each with 2 or 3 pools, that we can use to create the first round of the knockout structure */
      $firstRound = MatchNodeCollection::new(); // current round of matches, will be filled with MatchNode objects
      $nextMatchId = 1; // local match ID, starting at 1
      foreach ($poolsPerPlace as $chunk)
      {
         // format of chunk: [ 0 => [poolId1, poolId2, ...], 1 => [poolId1, poolId2, ...] ]
         // with the key being the place/rank of the pool winner in the knockout structure
         ksort($chunk); // make sure array is sorted by place/rank
         $slots = [];
         foreach ($chunk as $place => $poolIds)
         {
            // create PoolWinnerSlot objects for each pool winner in the chunk
            foreach ($poolIds as $poolId)
            {
               if( $pools->keyExists($poolId) ) // catch dummy pools that may have been generated during the splitting
               {
                  $slots[] = new PoolWinnerSlot($pools[$poolId], $place + 1); // place starts at 0, but we want it to start at 1
               }
               else
               {
                  $slots[] = new ByeSlot();
               }
            }
         }

         if (count($slots) === 2)
         {
            $firstRound[] = new KoNode($nextMatchId++, $this->category, slotRed: $slots[0], slotWhite: $slots[1] );
         }
         elseif(count($slots) === 3)
         {
            // one BYE needed, pair it with the best ranking participant in this chunk
            $firstRound[] = new KoNode($nextMatchId++, $this->category, slotRed: $slots[0], slotWhite: new ByeSlot());
            $firstRound[] = new KoNode($nextMatchId++, $this->category, slotRed: $slots[1], slotWhite: $slots[2]);
         }
         else
         {
            // sanity check, impossible with the split algorithm above
            throw new \LogicException('Unexpected number of slots in chunk: ' . count($slots));
         }
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
