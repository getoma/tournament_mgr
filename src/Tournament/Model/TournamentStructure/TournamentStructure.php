<?php

namespace Tournament\Model\TournamentStructure;

use Tournament\Model\TournamentStructure\MatchSlot\ParticipantSlot;
use Tournament\Model\TournamentStructure\MatchSlot\MatchWinnerSlot;
use Tournament\Model\TournamentStructure\MatchSlot\PoolWinnerSlot;
use Tournament\Model\TournamentStructure\MatchSlot\ByeSlot;
use Tournament\Model\TournamentStructure\KoChunk;
use Tournament\Model\TournamentStructure\Pool;
use Tournament\Model\TournamentStructure\KoNode;
use Tournament\Model\Data\Category;
use Tournament\Model\Data\Area;
use Tournament\Model\Data\MatchRecordCollection;

class TournamentStructure
{
   /** @var Pool[] */
   public array $pools = [];

   /** @var KoNode */
   public ?KoNode $ko = null;

   /** @var KoChunk[] */
   public array $chunks = [];

   /** @var ?int */
   public ?int $finale_rounds_cnt = null;

   /**
    * @param Category the category this structure is for
    * @param ?array   areas the list of combat areas
    * @param ?array[int,Participant] the list of participants
    */
   public function __construct(public Category $category, public array $areas, $participants = [], ?MatchRecordCollection $matchRecords = null)
   {
      if ($this->category->mode === 'ko')
      {
         $firstRound = $this->createKoFirstRound();
         $allRounds  = $this->fillKO($firstRound);
         $this->assignKoAreas($areas, $allRounds);
         $this->assignKoParticipants($participants, $firstRound);
      }
      elseif ($this->category->mode === 'pool')
      {
         $this->pools = $this->createAutoPools();
         $this->assignPoolAreas($areas);
         $this->assignPoolParticipants($participants);
      }
      elseif ($this->category->mode === 'combined')
      {
         // For combined mode, we need to create both pools and knockout structure
         $this->pools = $this->createAutoPools();
         $firstRound = $this->createPoolKoFirstRound();
         $allRounds = $this->fillKO($firstRound);
         $this->assignPoolAreas($areas);
         $this->assignKoAreas($areas, $allRounds);
         $this->assignPoolParticipants($participants);
      }
      else
      {
         throw new \InvalidArgumentException('Unknown tournament mode: ' . $this->category->mode);
      }

      if ($matchRecords !== null)
      {
         if( $this->ko )
         {
            $this->ko->setMatchRecords($matchRecords);
         }
         foreach ($this->pools as $pool)
         {
            $pool->setMatchRecords($matchRecords);
         }
      }
   }

   public function getPoolsByArea(Area|int $area): array
   {
      $areaid = ($area instanceof Area)? $area->id : $area;
      return array_filter($this->pools, fn($pool) => $pool->getArea()?->id === $areaid);
   }

   /**
    * create a list of matches for the first round of a knock-out structure
    */
   private function createKoFirstRound(): array
   {
      return array_map(
         fn($i) => new KoNode($i, new ParticipantSlot(), new ParticipantSlot()),
         range(1, pow(2, $this->category->config->num_rounds - 1))
      );
   }

   /**
    * Create random pools based on the participants.
    * This method will create pools with a maximum size defined in the category configuration.
    * Autogeneration of pools is only valid for combined mode.
    */
   private function createAutoPools(): array
   {
      $numSlots = pow(2, $this->category->config->num_rounds);
      $slotsPerPool = $this->category->config->pool_winners ?? 2;
      $numPools = pow(2, floor(log($numSlots / $slotsPerPool, 2))); // number of pools, must be a power of 2, rest filled up with BYEs
      return array_map(fn($i) => new Pool($i), range(0, $numPools - 1));
   }

   /**
    * replicate manually configured pools into the pool structure.
    */
   private function createManualPools(array $poolList): array
   {
      return [];
   }

   /**
    * Create a knockout structure based on the pools.
    * This method will distribute the pool winner start slots so that participants of the first pool will meet
    * as late as possible again.
    *
    * This is done by iteratively halving the pool winner lists into smaller chunks, with a conflict resolution
    * algorithm that determines the best canditates to add to each smaller chunk via some sort of cost analysis.
    */
   private function createPoolKoFirstRound(): array
   {
      $slotsPerPool = $this->category->config->pool_winners ?? 2; // number of pool winners per pool
      $poolsPerPlace = [array_fill(0, $slotsPerPool, array_keys($this->pools))];
      $splitTarget = $slotsPerPool * count($this->pools); // target for splitting the pool winners into two halves
      while ($splitTarget >= 4)
      {
         /* track usage of each pool while distributing pool winners, use pool ids as key */
         $nextRound = [];
         $splitTarget /= 2; // halve the target for the next round
         /* split down each current chunk into two new chunks */
         foreach ($poolsPerPlace as $chunk)
         {
            /* chunk format: [ 0 => [pool1_idx, pool2_idx, ...], 1 => [pool1_idx, pool2_idx, ...] ] */
            $poolUsage = array_fill_keys(array_merge(...$chunk), 0); // to track usage of each available pool
            $split_chunk = []; // the separated chunk for the next round
            $split_count = 0; // count how many pools we have split so far
            while ($split_count < $splitTarget)
            {
               /* select one new candidate from each placement rank */
               for ($i = 0; ($i < $slotsPerPool) && ($split_count < $splitTarget); $i++)
               {
                  // find the pool with the least usage
                  $chunkPoolUsage = array_intersect_key($poolUsage, array_flip($chunk[$i]));
                  $poolId = array_search(min($chunkPoolUsage), $chunkPoolUsage);
                  // add the pool to the split chunk
                  $split_chunk[$i][] = $poolId;
                  // and remove it from the current chunk
                  $chunk[$i] = array_diff($chunk[$i], [$poolId]);
                  // increment the usage of the pool
                  $poolUsage[$poolId] += $slotsPerPool - $i; // the higher the rank, the higher the recorded usage
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
      $firstRound = []; // current round of matches, will be filled with MatchNode objects
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
               $slots[] = new PoolWinnerSlot($poolId, $place + 1); // place starts at 0, but we want it to start at 1
            }
         }

         if (count($slots) === 2)
         {
            $firstRound[] = new KoNode($nextMatchId++, slotRed: $slots[0], slotWhite: $slots[1]);
         }
         elseif (count($slots) >= 3)
         {
            $firstRound[] = new KoNode($nextMatchId++, slotRed: $slots[1], slotWhite: $slots[2]);

            if (count($slots) === 3)
            {
               $firstRound[] = new KoNode($nextMatchId++, slotRed: $slots[0], slotWhite: new ByeSlot());
            }
            elseif (count($slots) === 4)
            {
               $firstRound[] = new KoNode($nextMatchId++, slotRed: $slots[0], slotWhite: $slots[3]);
            }
            else
            {
               // sanity check, impossible with the split algorithm above
               throw new \InvalidArgumentException('Unexpected number of slots in chunk: ' . count($slots));
            }
         }
      }
      return $firstRound;
   }

   /**
    * complete the KO tree from a list of first-round-matches.
    * Store the final node in the class, and return the full list of rounds
    * for any further operations.
    */
   private function fillKO(array $currentRound): array
   {
      $nextMatchId = count($currentRound)+1; // next match ID, starting after the last match in the first round
      $rounds = [$currentRound];

      // now create the subsequent rounds until we reach the final match
      while (count($currentRound) > 1)
      {
         $previousRound = $currentRound; // get the last round
         $currentRound = [];
         // create the next round of matches, using the winners of the previous round
         for ($i = 0; $i < count($previousRound); $i += 2)
         {
            $slotRed   = new MatchWinnerSlot($previousRound[$i]);
            $slotWhite = new MatchWinnerSlot($previousRound[$i + 1]);
            $currentRound[] = new KoNode($nextMatchId++, slotRed: $slotRed, slotWhite: $slotWhite);
         }
         $rounds[] = $currentRound;
      }

      // now store the final match as the KO structure
      $this->ko = $currentRound[0]; // the last match is the final match

      // return the full list of rounds
      return $rounds;
   }

   /**
    * assign participants to each KO first round slot according the given mapping
    */
   private function assignKoParticipants(array $participants, array $firstRound)
   {
      /* map nodes by their localid */
      $names = array_map(fn($n) => $n->name, $firstRound);
      $firstRound = array_combine($names, $firstRound);

      /* assign participants */
      foreach ($participants as $slotId => $p)
      {
         $nodeName = substr($slotId, 0, -1);
         $color = substr($slotId, -1);
         if (array_key_exists($nodeName, $firstRound))
         {
            if ($color === 'r') $firstRound[$nodeName]->slotRed->participant = $p;
            elseif ($color === 'w') $firstRound[$nodeName]->slotWhite->participant = $p;
            // else move them into "unmapped" notification TODO
         }
      }
   }

   /**
    * assign participants into pools according the given mapping
    */
   private function assignPoolParticipants(array $participants)
   {
      foreach ($participants as $slotId => $p)
      {
         list($poolId, $slotNr) = explode('.', $slotId);
         if (array_key_exists($poolId, $this->pools))
         {
            $this->pools[$poolId]->participants[$slotNr] = $p;
         }
      }
      foreach ($this->pools as $pool)
      {
         $pool->generateMatches();
      }
   }

   /**
    * shuffle in a new list of participants
    */
   public function shuffleParticipants(array $participants): array
   {
      if (empty($this->pools))
      {
         return $this->shuffleKoParticipants($participants);
      }
      else
      {
         return $this->shufflePoolParticipants($participants);
      }
   }

   /**
    * shuffle in a new list of participants into a KO structure
    */
   private function shuffleKoParticipants(array $participants): array
   {
      /* algorithm for participant shuffling, that makes sure that BYEs are spread out evenly:
       * 1) create an array of ParticipantSlot objects for each participant, and shuffle it
       * 3) Split the array into two halves, the first half will be the red slots and the second half will be the white slots
       * 4) fill up both array with null ParticipantSlot objects until we have $numSlots/2 objects in both arrays
       * 5) shuffle both arrays again
       *
       * Assuming, that under normal circumstances we will have more then half of the expected participants set,
       * this algorithm will ensure that the BYEs are spread out evenly across the matches and will always be in the white slots,
       * while the red slots will always be filled with actual participants.
       *
       * Just to make this algorithm also work in case we have less than half of the expected participants,
       * we will fill up the red slots with null ParticipantSlot objects as well.
       */

      // get references to all MatchNodes in the first round.
      $firstRound = $this->ko->getRounds()[0];
      // Initial shuffle to randomize assignment of participants to initial colors
      shuffle($participants);
      // Split into red and white slots
      $numNodes   = count($firstRound);
      $redSlots   = array_slice($participants, 0, $numNodes);
      $whiteSlots = array_slice($participants, $numNodes);
      // Fill up both arrays with null ParticipantSlot objects until we have $numNodes objects in both arrays
      $redSlots   = array_merge($redSlots,   array_fill(0, $numNodes - count($redSlots),   null));
      $whiteSlots = array_merge($whiteSlots, array_fill(0, $numNodes - count($whiteSlots), null));
      // Shuffle both arrays again to ensure randomness of BYEs distribution
      shuffle($redSlots);
      shuffle($whiteSlots);

      // now assign the slots to each match
      $newMapping = [];
      for ($i = 0; $i < count($firstRound); $i++)
      {
         $firstRound[$i]->slotRed->participant = $redSlots[$i];
         if ($redSlots[$i])
         {
            $slotId = $firstRound[$i]->name . "r";
            $newMapping[$slotId] = $redSlots[$i];
         }

         $firstRound[$i]->slotWhite->participant = $whiteSlots[$i];
         if ($whiteSlots[$i])
         {
            $slotId = $firstRound[$i]->name . "w";
            $newMapping[$slotId] = $whiteSlots[$i];
         }
      }

      return $newMapping;
   }

   /**
    * shuffle in a new list of participants into the pools
    */
   private function shufflePoolParticipants($participants): array
   {
      shuffle($participants);
      $numParticipants = count($participants);
      $poolCount = count($this->pools);
      $minCount = intdiv($numParticipants, $poolCount); // minimum number of participants per pool
      $extra = $numParticipants % $poolCount; // number of pools with one additional participant

      $newMapping = [];
      $offset = 0;
      for ($i = 0; $i < $poolCount; $i++)
      {
         $slice_size = $minCount + (int)($i < $extra);
         $p_slice = array_slice($participants, $offset, $slice_size);
         for ($j = 0; $j < $slice_size; $j++)
         {
            $slotId = $i . "." . $j;
            $this->pools[$i]->participants[] = $p_slice[$j];
            $newMapping[$slotId] = $p_slice[$j];
         }
         $offset += $slice_size;
      }

      return $newMapping;
   }

   /**
    * Assign the areas to the pools.
    * The areas are assigned in a round-robin fashion, so that each pool gets a
    * different area assigned.
    * The$nodeName of the pool is set to the area$nodeName + "-" + pool index
    * (e.g. "Area-1-1", "Area-2-1", "Area-1-2", "Area-2-2", ...)
    * This way, the pools can be easily identified by their area assignment.
    */
   private function assignPoolAreas($areas)
   {
      if (empty($areas)) return;
      $numAreas = count($areas);
      $numPools = count($this->pools);
      for ($i = 0; $i < $numPools; $i++)
      {
         $area = $areas[intdiv($i,$numAreas)];
         $this->pools[$i]->setArea($area);
      }
   }

   /**
    * Distribute the ko tree to the areas and create ko tree chunks if configured
    * In order to minimize the number of concurrent participants on the same
    * area, there is a parameter "area_cluster".
    * The tree is split into #numArea * #area_cluster clusters. This means
    * with 2 Areas and area_cluster=2 we have 8 clusters: Area-1-1, Area-2-1, Area-1-2, Area-2-2
    */
   private function assignKoAreas($areas, $rounds)
   {
      if (empty($areas)) return;

      $cluster           = $this->category->config->area_cluster;
      $numAreas          = count($areas);
      $numClusters       = $numAreas * ($cluster ?? 1);
      $finale_rounds_cnt = ceil(log($numClusters, 2));
      $first_finale_idx  = count($rounds) - $finale_rounds_cnt;

      /**
       * split and assign the tree to the defined clusters
       * @var SoloMatch $node
       */
      if( $first_finale_idx > 0 )
      {
         foreach ($rounds[$first_finale_idx] as $i => $node)
         {
            $chunkslots = [];
            /** @var MatchWinnerSlot $slot */
            foreach( [$node->slotRed, $node->slotWhite] as $j => $slot )
            {
               $match_idx = 2*$i + $j;
               $area_idx  = $match_idx % $numAreas; // index of the area cluster, starting at 0
               $area_chunk_idx  = intdiv($match_idx, $numAreas); // start at 1, so we can use it as a suffix
               $area_chunk_id   = ($area_idx + 1) . "-" . ($area_chunk_idx + 1); // do NOT use area.name, or renaming areas will destroy the slot mapping
               $area = $areas[$area_idx];

               /* if chunks are explicitly requested, split it now accordingly
                * otherwise, keep the tree in one big chunk, and only assign areas as if there was one cluster for each area
                */
               if( $cluster !== null )
               {
                  $this->chunks[$area_chunk_id] = new KoChunk($slot->matchNode, $area_chunk_id, $area);
               }
               else
               {
                  foreach( $slot->matchNode->getMatchList() as $m )
                  {
                     $m->area = $area;
                  }
               }
            }
         }
      }
      else
      {
         /* not enough rounds to split into pre-finale chunks */
         $finale_rounds_cnt = count($rounds);
      }

      /**
       * assign the finale rounds to the areas.
       */
      $area_usage = array_fill(0, $numAreas, 0); // track usage of each area
      $final_matches = array_merge(...array_slice($rounds, -$finale_rounds_cnt)); // get all final matches from the last rounds

      /** @var SoloMatch $node */
      foreach ($final_matches as $node)
      {
         /* find all areas with the least usage, and select one area that is also used in the previous matches, if possible */
         $min_usage = min($area_usage);
         $available_areas_idx_list = array_keys(array_filter($area_usage, fn($usage) => $usage === $min_usage));
         $available_areas = array_intersect_key($areas, array_flip($available_areas_idx_list));
         if ($redArea = $node->slotRed->matchNode->area)
         {
            $area_idx = array_search($redArea, $available_areas);
         }
         if (($area_idx === false) && ($whiteArea = $node->matchNode->area))
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
