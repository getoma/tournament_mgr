<?php

namespace Tournament\Model\TournamentStructure;

use Tournament\Model\TournamentStructure\MatchSlot\ParticipantSlot;
use Tournament\Model\TournamentStructure\MatchSlot\MatchWinnerSlot;
use Tournament\Model\TournamentStructure\MatchSlot\PoolWinnerSlot;
use Tournament\Model\TournamentStructure\MatchSlot\ByeSlot;
use Tournament\Model\TournamentStructure\MatchNode\KoNode;
use Tournament\Model\TournamentStructure\KoChunk;
use Tournament\Model\TournamentStructure\Pool\PoolCollection;

use Tournament\Model\Area\Area;
use Tournament\Model\Area\AreaCollection;
use Tournament\Model\Category\Category;
use Tournament\Model\Category\CategoryMode;
use Tournament\Model\MatchRecord\MatchRecordCollection;
use Tournament\Model\Participant\ParticipantCollection;
use Tournament\Model\TournamentStructure\MatchNode\MatchNodeCollection;

/**
 * A class to generate and manage a Tournament structure, consisting of Pools and/or a KO tree
 * to fully load up the structure, the various loading methods need to be called in a proper order:
 * - __construct()
 * - generateStructure()
 * - getParticipantHandler->loadParticipants()
 * - loadMatchRecords()
 */
class TournamentStructure
{
   /** @var PoolCollection list of pools */
   public PoolCollection $pools;

   /** @var KoNode finale node of the KO tree */
   public ?KoNode $ko = null;

   /** @var KoChunk[] list of KO clusters*/
   public array $chunks = [];

   /** @var ?int number of finale rounds after the clusters*/
   public ?int $finale_rounds_cnt = null;

   /** @var ParticipantCollection of all participants that don't have a start place right now */
   public ParticipantCollection $unmapped_participants;

   /** @var TournamentStructureFactory */
   private readonly TournamentStructureFactory $factory;

   public function __construct(
      public Category $category,
      public AreaCollection $areas
   )
   {
      $this->pools = PoolCollection::new();
      $this->unmapped_participants = ParticipantCollection::new();
      $this->factory = new TournamentStructureFactory(
         $category->getMatchPointHandler(),
         $category->getPoolRankHandler(),
         $category->getMatchCreationHandler() );
   }

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
            $this->assignKoAreas($this->areas, $this->category->config->area_cluster);
         }
         if (!$this->pools->empty())
         {
            $this->assignPoolAreas($this->areas);
         }
      }
   }

   public function getParticipantHandler()
   {
      return new ParticipantHandler($this);
   }

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

   public function getPoolsByArea(Area|int $area): PoolCollection
   {
      $areaid = ($area instanceof Area)? $area->id : $area;
      return $this->pools->filter(fn($pool) => $pool->getArea()?->id === $areaid);
   }

   /**
    * create a list of matches for the first round of a knock-out structure
    */
   private function createKoFirstRound(int $numRounds): MatchNodeCollection
   {
      return MatchNodeCollection::new( array_map(
         fn($i) => $this->factory->createKoNode($i, new ParticipantSlot(), new ParticipantSlot()),
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
      return PoolCollection::new( array_map(fn($i) => $this->factory->createPool($i+1), range(0, $numPools - 1)) );
   }

   /**
    * Create a knockout structure based on the pools.
    * This method will distribute the pool winner start slots so that participants of the first pool will meet
    * as late as possible again.
    * If there are any wildcards, this algorithm will assign them to the higher ranking participants according
    * pool results first.
    *
    * This is done by iteratively halving the pool winner lists into smaller chunks, with a conflict resolution
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
         /* split down each current chunk into two new chunks */
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
                * from a different pool each time - as a result, pool members will be separated across chunks
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

      /* now we have an array of chunks in $poolsPerPlace,
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
            $firstRound[] = $this->factory->createKoNode($nextMatchId++, slotRed: $slots[0], slotWhite: $slots[1] );
         }
         elseif(count($slots) === 3)
         {
            // one BYE needed, pair it with the best ranking participant in this chunk
            $firstRound[] = $this->factory->createKoNode($nextMatchId++, slotRed: $slots[0], slotWhite: new ByeSlot());
            $firstRound[] = $this->factory->createKoNode($nextMatchId++, slotRed: $slots[1], slotWhite: $slots[2]);
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
    * Store the final node in the class, and return the full list of rounds
    * for any further operations.
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
            $currentRound[] = $this->factory->createKoNode($nextMatchId++, slotRed: $slotRed, slotWhite: $slotWhite);
         }
      }
      return $currentRound->front();
   }

   /**
    * Assign the areas to the pools.
    * The areas are assigned in a round-robin fashion, so that each pool gets a
    * different area assigned.
    */
   private function assignPoolAreas(AreaCollection $areas): void
   {
      $numAreas = $areas->count();
      $areas_i  = $areas->values(); // turn into indexed list
      $area_idx = 0;
      foreach ($this->pools as $pool)
      {
         $area = $areas_i[$area_idx++ % $numAreas];
         $pool->setArea($area);
      }
   }

   /**
    * Distribute the ko tree to the areas and create ko tree chunks if configured
    * In order to minimize the number of concurrent participants on the same
    * area, there is a parameter "area_cluster".
    * The tree is split into #numArea * #area_cluster clusters. This means
    * with 2 Areas and area_cluster=2 we have 8 clusters: Area-1-1, Area-2-1, Area-1-2, Area-2-2
    */
   private function assignKoAreas(AreaCollection $areas, ?int $cluster): void
   {
      $numAreas          = $areas->count();
      $numClusters       = $numAreas * ($cluster ?? 1);
      $rounds            = $this->ko->getRounds();
      $finale_rounds_cnt = ceil(log($numClusters, 2));
      $cluster_root_idx  = $rounds->count() - $finale_rounds_cnt - 1;

      $areas_i = $areas->values();

      /**
       * split and assign the tree to the defined clusters
       */
      if($cluster_root_idx >= 0 )
      {
         /** @var KoNode $node */
         foreach ($rounds[$cluster_root_idx] as $match_idx => $node)
         {
            $area_idx       = $match_idx % $numAreas; // index of the area cluster, starting at 0
            $area_chunk_idx = intdiv($match_idx, $numAreas); // start at 1, so we can use it as a suffix
            $area_chunk_id  = ($area_idx + 1) . "-" . ($area_chunk_idx + 1); // do NOT use area.name, or renaming areas will destroy the slot mapping
            $area           = $areas_i[$area_idx];

            /* if chunks are explicitly requested, split it now accordingly
             * otherwise, keep the tree in one big chunk, and only assign areas as if there was one cluster for each area
             */
            if( $cluster !== null )
            {
               $this->chunks[$area_chunk_id] = new KoChunk($node, $area_chunk_id, $area);
            }
            else
            {
               foreach( $node->getMatchList() as $m )
               {
                  $m->area = $area;
               }
            }
         }
      }
      else
      {
         /* not enough rounds to split into pre-finale chunks */
         $finale_rounds_cnt = $rounds->count();
      }

      /**
       * assign the finale rounds to the areas.
       */
      $area_usage = array_fill(0, $numAreas, 0); // track usage of each area
      $final_rounds = $rounds->slice(-$finale_rounds_cnt); // get all final matches from the last rounds into a single list

      /** @var KoNode $node */
      foreach ($final_rounds->flatten() as $node)
      {
         /* find all areas with the least usage, and select one area that is also used in the previous matches, if possible */
         $min_usage = min($area_usage);
         $available_areas_idx_list = array_keys(array_filter($area_usage, fn($usage) => $usage === $min_usage));
         $available_areas = array_intersect_key($areas_i, array_flip($available_areas_idx_list));
         if ( ($node->slotRed instanceOf MatchWinnerSlot) && ($redArea = $node->slotRed->matchNode->area) )
         {
            $area_idx = array_search($redArea, $available_areas);
         }
         if (($area_idx === false) && ($node->slotWhite instanceof MatchWinnerSlot) && ($whiteArea = $node->slotWhite->matchNode->area))
         {
            $area_idx = array_search($whiteArea, $available_areas);
         }
         if ($area_idx === false)
         {
            // if the area is not in the available areas, select one "from the middle" of the available areas
            $area_keys = array_keys($available_areas);
            $area_idx = $area_keys[ceil(count($area_keys) / 2)-1];
         }
         $node->area = $available_areas[$area_idx];
         $area_usage[$area_idx]++; // increment the usage of the area
      }

      /* update finale round count */
      $this->finale_rounds_cnt = $cluster? $finale_rounds_cnt : null;
   }
}
