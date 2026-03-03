<?php

namespace Tournament\Model\TournamentStructure;

use Tournament\Model\Area\AreaCollection;
use Tournament\Model\TournamentStructure\MatchNode\MatchNodeCollection;

/**
 * AreaAssignmentHandler - handle assignment of Areas to MatchNodes for a given
 * TournamentStructure
 * This module is there to move the area assignment algorithms into a separate file,
 * and this class is tightly coupled to TournamentStructure
 */
class AreaAssignmentHandler
{
   function __construct(private TournamentStructure $struc)
   {
   }

   /**
    * Assign the areas to the pools.
    * The areas are assigned in a round-robin fashion, so that each pool gets a
    * different area assigned.
    */
   public function assignPoolAreas(AreaCollection $areas): void
   {
      $numAreas = $areas->count();
      $areas_i  = $areas->values(); // turn into indexed list
      $area_idx = 0;
      foreach ($this->struc->pools as $pool)
      {
         $area = $areas_i[$area_idx++ % $numAreas];
         $pool->setArea($area);
      }
   }

   /**
    * Distribute the ko tree to the areas.
    * parameter $cluster currently ignored, TBD
    */
   public function assignKoAreas(AreaCollection $areas, ?int $cluster): void
   {
      $numAreas   = $areas->count();
      $area_usage = array_fill(0, $numAreas, 0); // track usage of each area
      $rounds     = $this->struc->ko->getRounds();
      $areas_i    = $areas->values();

      $area_usage = $this->symmetricDistributeAreas($rounds->first(), $areas_i);

      /** @var KoNode $node */
      foreach ($rounds->slice(1) as $round)
      {
         if( $round->count() === 1 )
         {
            /* finale always in the center */
            $round->first()->area = $areas_i[intdiv($numAreas-1,2)];
         }
         else
         {
            $area_usage = $this->innodeBasedAreaDistribute($round, $areas_i, $area_usage);
         }
      }
   }

   /**
    * symmetrically distribute a collection of nodes without taking any previous
    * assignments into account
    * This method is meant to be used to assign areas to the very first round of a KO structure
    *
    * @param $round - the collection of nodes to assign to areas
    * @param $areas - a 0-based indexed array of all areas to use
    * @return array - number of assignments per area in $areas, with 0-based indexing
    */
   private function symmetricDistributeAreas(MatchNodeCollection $round, array $areas): array
   {
      /**
       * calculate a symmetric distribution of all areas in the first round
       */
      $numAreas = count($areas);
      $first_round_node_count = $round->count();
      $base_match_count_per_area = intdiv($first_round_node_count, $numAreas);
      $remainder = $first_round_node_count % $numAreas;

      $area_usage = array_fill(0, $numAreas, $base_match_count_per_area);

      if ($remainder === 1)
      {
         /* there is exactly one node to put in addition - put it in the middle area */
         $area_usage[intdiv($numAreas - 1, 2)] += 1;
      }
      elseif ($remainder >= 2)
      {
         /* evenly distribute the nodes in a symmetric pattern, using a formula that
          * was brought to me by some AI, most definitely has a well-defined mathematic line of reason
          * and I would love to explain here in detail, but would you just look at the time!
          */
         for ($j = 0; $j < $remainder; ++$j)
         {
            $pos = (int)round($j * ($numAreas - 1) / ($remainder - 1));
            $area_usage[$pos] += 1;
         }
      }
      else
      {
         /* remainder is 0, meaning all nodes are already accounted for */
      }

      /* apply the derived area usage to the first round */
      $area_idx = 0;
      $area_cnt = 0;
      foreach ($round as $node)
      {
         $node->area = $areas[$area_idx];
         if (++$area_cnt >= $area_usage[$area_idx])
         {
            $area_idx += 1;
            $area_cnt = 0;
         }
      }

      return $area_usage;
   }

   /**
    * distribute areas to a collection of match nodes, based on the area assignments of the previous round
    * This function works best for rounds where there are at least as many nodes as there are areas
    *
    * @param $round - the collection of nodes to assign to areas
    * @param $areas - a 0-based indexed array of all areas to use
    * @param $usage - usage counters for each area, with 0-based indexing
    * @return array - the updated $usage tracker
    */
   private function innodeBasedAreaDistribute(MatchNodeCollection $round, array $areas, array $usage): array
   {
      $numAreas = count($areas);
      $nodeCount = $round->count();

      /* first trivial assignment: if both incoming nodes were on the same area, use this one here as well */
      foreach ($round as $node)
      {
         list($redSlot, $whiteSlot) = [$node->slotRed, $node->slotWhite];
         /** @var MatchWinnerSlot $redSlot */
         /** @var MatchWinnerSlot $whiteSlot */
         if ($redSlot->matchNode->area === $whiteSlot->matchNode->area)
         {
            $node->area = $redSlot->matchNode->area;
            $area_idx = array_find_key($areas, fn($a) => $a === $node->area);
            $usage[$area_idx] += 1;
         }
      }

      /* second step: try to find a fitting area for nodes with split innodes, based on a cost analysis
       * we only calculate the upper half of the tree, and then mirror the results to the lower half afterwards
       */
      $idx_assignment = [];
      foreach ($round->slice(0, $nodeCount / 2) as $node_idx => $node)
      {
         if ($node->area) continue; // already assigned in first step

         list($redSlot, $whiteSlot) = [$node->slotRed, $node->slotWhite];
         /** @var MatchWinnerSlot $redSlot */
         /** @var MatchWinnerSlot $whiteSlot */

         /* decide between the two incoming nodes based on the current area usage */
         $redAreaIdx = array_find_key($areas, fn($a) => $a === $redSlot->matchNode->area);
         $whiteAreaIdx = array_find_key($areas, fn($a) => $a === $whiteSlot->matchNode->area);
         if($usage[$redAreaIdx] === $usage[$whiteAreaIdx] )
         {
            /* we can freely select - use the "outer one" */
            $area_idx = $whiteAreaIdx > intdiv($numAreas,2)? $whiteAreaIdx : $redAreaIdx;
         }
         else
         {
            /* use the one with less usage */
            $area_idx = $usage[$redAreaIdx] < $usage[$whiteAreaIdx]? $redAreaIdx : $whiteAreaIdx;
         }
         $node->area = $areas[$area_idx];
         $usage[$area_idx] += 1;
         $idx_assignment[$node_idx] = $area_idx;
      }

      /* third step: mirror the assignments into the lower half */
      foreach( $idx_assignment as $sister_node_idx => $sister_area_idx )
      {
         $node_idx = $nodeCount - 1 - $sister_node_idx;
         $area_idx = $numAreas  - 1 - $sister_area_idx;
         $round[$node_idx]->area = $areas[$area_idx];
         $usage[$area_idx] += 1;
      }

      /* done */
      return $usage;
   }
}