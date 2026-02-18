<?php

namespace Tournament\Model\PlacementCostCalculator;

use Tournament\Model\Participant\Participant;
use Tournament\Model\TournamentStructure\MatchNode\KoNode;
use Tournament\Model\TournamentStructure\MatchSlot\MatchSlot;
use Tournament\Model\TournamentStructure\MatchSlot\MatchWinnerSlot;

class GenericPlacementCostCalculator implements PlacementCostCalculator
{
   private array $distanceMatrix = [];

   function __construct(private float $club_weight = 2,
                        private float $no_distance_penalty = 4)
   {
   }

   public function calculateCost(Participant $candidate, string $slotName, SlotPlacmentCollection $placed): float
   {
      $cost = 0;

      foreach( $placed as $placement )
      {
         /** @var SlotPlacement $placement */

         /* ignore this slot if it is identical to the current $candidate (might happen if costs are re-calculated after assigning) */
         if( $candidate === $placement->participant ) continue;

         /* retrieve the distance between $candidate and this placement slot - and apply the general penalty if distance is 0 (i.e. same slot/pool) */
         $distance = $this->distanceMatrix[$slotName][$placement->slot->getName()] ?: (1/$this->no_distance_penalty);

         /* general cost for beeing placed near any other candidate */
         $cost += 1/$distance;

         /* cost for being placed near candidates from the same club */
         if( $candidate->club && $candidate->club === $placement->participant->club )
         {
            $cost += $this->club_weight / $distance;
         }
      }

      return $cost;
   }

   public function loadStructure(KoNode $root): void
   {
      /* derive the paths for each node and collect all starting slots */
      $root_path = [];
      $start_slots = [];
      $node_stack = [$root];
      while ($node = array_shift($node_stack))
      {
         $path = $root_path[$node->getName()] ?? [];
         foreach ([$node->slotRed, $node->slotWhite] as $slot)
         {
            $node_path = array_merge([$node], $path);
            if ($slot instanceof MatchWinnerSlot)
            {
               /* we are somewhere inside the KO tree - derive path and add child nodes to the stack */
               $root_path[$slot->matchNode->getName()] = $node_path;
               $node_stack[] = $slot->matchNode;
            }
            else
            {
               /* we found an end node - add its slots to the list of starting slots */
               $path_names = array_map(fn($n) => $n->getName(), $node_path);
               $path_dict  = array_flip($path_names);
               $start_slots[] = ['name' => $slot->getName(), 'path' => $path_names, 'path_dict' => $path_dict];
            }
         }
      }

      /* now generate the distance matrix for each starting slot */
      $result = [];
      $slot_count = count($start_slots);
      for ($i = 0; $i < $slot_count; ++$i)
      {
         $iSlot = $start_slots[$i];
         for ($j = $i; $j < $slot_count; ++$j)
         {
            $jSlot = $start_slots[$j];
            $iName = $iSlot['name'];
            $jName = $jSlot['name'];
            if($iName === $jName) // the "same" KO start slot may appear on multiple places in combined mode with pools
            {
               $result[$iName][$jName] = 0;
               $result[$jName][$iName] = 0;
            }
            else
            {
               /* find the first common node of iSlot and jSlot - and store the path length to it */
               $jDict = $jSlot['path_dict'];
               $iPathLen = count($iSlot['path']);
               for ($r = 0; $r < $iPathLen; ++$r)
               {
                  $path_node_name = $iSlot['path'][$r];
                  if (isset($jDict[$path_node_name]))
                  {
                     /* as slots may appear on different places in the KO tree in combined mode with pools,
                      * there might be multiple paths between slots - only store the one with the shortest path */
                     $result[$iName][$jName] ??= PHP_INT_MAX;
                     $result[$iName][$jName] = min($r + 1, $result[$iName][$jName]);
                     $result[$jName][$iName] = $result[$iName][$jName];
                     break;
                  }
               }
            }
         }
      }

      $this->distanceMatrix = $result;
   }
}