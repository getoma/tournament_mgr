<?php

namespace Tournament\Model\TournamentStructure;

use Tournament\Model\Participant\Participant;
use Tournament\Model\Participant\ParticipantCollection;
use Tournament\Model\Participant\CategoryAssignment;
use Tournament\Model\PlacementCostCalculator\SlotPlacement;
use Tournament\Model\PlacementCostCalculator\SlotPlacmentCollection;
use Tournament\Model\TournamentStructure\MatchNode\KoNode;
use Tournament\Model\TournamentStructure\MatchNode\MatchNodeCollection;
use Tournament\Model\TournamentStructure\MatchSlot\MatchSlotCollection;
use Tournament\Model\TournamentStructure\MatchSlot\ByeSlot;
use Tournament\Model\TournamentStructure\MatchSlot\ParticipantSlot;
use Tournament\Model\TournamentStructure\MatchSlot\PoolWinnerSlot;

/**
 * ParticipantHandler - handle assignment of Participants to starting slots for a given
 * TournamentStructure
 * This module is there to move the participant assignment algorithms into a separate file,
 * and this class is tightly coupled to TournamentStructure
 */
class ParticipantHandler
{
   function __construct(private TournamentStructure $struc)
   {
   }

   /**
    * load a collection of slot-assigned participants into the Tournament structure
    */
   public function loadParticipants(ParticipantCollection $participants)
   {
      if (!$this->struc->pools->empty())
      {
         $this->loadPoolParticipants($participants);
      }
      else if ($this->struc->ko)
      {
         $this->loadKoParticipants($participants);
      }
      else
      {
         throw new \LogicException('No structure generated, yet');
      }
   }

   /**
    * extract the pool id from a slot name including plausibility checking of the slotname
    */
   public static function getPoolIdFromSlotName(string $slotName, bool $throw_if_invalid = true): ?string
   {
      if( preg_match('/^\d+\.\d+$/', $slotName) ) return static::getPoolIdFromSlotNameInt($slotName);
      if( $throw_if_invalid ) throw new \InvalidArgumentException("'$slotName' is not a valid pool slot name");
      return null;
   }

   /**
    * extract the pool id from a slot name without plausibility check
    */
   private static function getPoolIdFromSlotNameInt(string $slotName): string
   {
      return array_first(explode('.', $slotName));
   }

   /**
    * extract the KoNode name from a slot name
    */
   public static function getKoNodeNameFromSlotName(string $slotName, bool $throw_if_invalid = true): ?string
   {
      return KoNode::getNodeNameFromSlotName($slotName, $throw_if_invalid);
   }

   /**
    * assign participants to each KO first round slot according the given mapping
    */
   private function loadKoParticipants(ParticipantCollection $participants)
   {
      $slots = $this->getSlots($this->struc->ko->getFirstRound());
      foreach ($participants as $p)
      {
         /** @var Participant $p */
         $slot_name = $p->categories[$this->struc->category->id]->slot_name;
         if (isset($slot_name) && isset($slots[$slot_name])) $slots[$slot_name]->participant = $p;
         else $this->struc->unmapped_participants[] = $p;
      }
   }

   /**
    * assign participants into pools according the given mapping
    */
   private function loadPoolParticipants(ParticipantCollection $participants)
   {
      /* distribute the participants to a separate collection for each pool */
      $pool_participants = [];
      foreach ($participants as $p)
      {
         /** @var Participant $p */
         $slot_name = $p->categories[$this->struc->category->id]->slot_name;
         if( $slot_name )
         {
            $poolId = $this->getPoolIdFromSlotNameInt($slot_name);
            if ($this->struc->pools->keyExists($poolId))
            {
               $pool_participants[$poolId] ??= ParticipantCollection::new();
               $pool_participants[$poolId][] = $p;
            }
            else
            {
               $this->struc->unmapped_participants[] = $p;
            }
         }
         else
         {
            $this->struc->unmapped_participants[] = $p;
         }
      }

      /* forward the collected participants to each pool */
      foreach ($pool_participants as $id => $col)
      {
         $this->struc->pools[$id]->setParticipants($col);
      }
   }

   /**
    * populate a TournamentStructure with a collection of Participants
    */
   public function populate(ParticipantCollection $participants): ParticipantCollection
   {
      /* prepare the placement cost calculator */
      $calculator = $this->struc->category->getPlacementCostCalculator();
      $calculator->loadStructure($this->struc->ko);

      /* get the list of starting slots, but only from matches that are not started, yet */
      $first_round = $this->struc->ko->getFirstRound();
      $starting_slots = $this->getSlots($first_round);

      /* extract all previous slot assignements */
      $assigned = $this->getSlotPlacements($starting_slots);

      /* get the actual number of participants we have to allocate */
      $participantCount = $assigned->count() + $participants->count();

      if ($this->struc->pools->empty())
      {
         /* in case of a pure KO mode, put the BYE slots to fixed places. */
         $this->removeByeSlots($starting_slots, $participantCount);
      }

      /* remove any to-be-set participants from unmapped participants for now */
      $participants->walk( fn($p) => $this->struc->unmapped_participants->drop($p) );

      /* add any manuell presets */
      $get_pre_assign = fn(Participant $p) => $p->categories[$this->struc->category->id]?->pre_assign ?? null;
      foreach ($participants as $p)
      {
         /** @var Participant $p */
         $pre_assign_slot = $get_pre_assign($p);
         if (!$pre_assign_slot) continue;

         $slotName = null;
         if ($this->struc->pools->empty())
         {
            /* pre-assign value is a MatchNode Name/ID, assign to its red slot */
            $node = $first_round->findNode($pre_assign_slot);
            if (isset($node) && !$node->slotRed->getParticipant()) $slotName = $node->slotRed->getName();
         }
         else
         {
            /* pre-assign value is a pool name, get a slot pointing to this pool */
            $pool = $this->struc->pools[$pre_assign_slot] ?? null;
            if (isset($pool)) $slotName = $pool->getName();
         }

         if (isset($slotName))
         {
            $assigned[] = new SlotPlacement($starting_slots[$slotName], $p);
            $participants->drop($p); // remove them from any further considerations
         }
      }

      /* derive the number of participants per starting slot */
      $slot_tracker = $this->deriveSlotAllocationCounters($starting_slots, $participantCount);

      /* register the already set assignments in the slot_tracker */
      foreach( $assigned as $set )
      {
         $slotName = $set->slot->getName();
         if( isset($slot_tracker[$slotName]) )
         {
            $slot_tracker[$slotName] -= 1;
            if (!$slot_tracker[$slotName]) unset($slot_tracker[$slotName]);
         }
      }

      /* shuffle and seed the new participants */
      $shuffled = $this->shuffleParticipantList($participants);
      while ($slot_tracker && $participant = $shuffled->shift())
      {
         $bestSlots = [];
         $bestCost = PHP_FLOAT_MAX;
         /* check each available slot for the cost it would generate, memorize the cheapest */
         foreach (array_keys($slot_tracker) as $slotName)
         {
            $cost = $calculator->calculateCost($participant, $slotName, $assigned);
            if (abs($cost - $bestCost) < PHP_FLOAT_EPSILON) // another slot with same best cost
            {
               $bestSlots[] = $slotName;
            }
            else if ($cost < $bestCost) // new best cost found
            {
               $bestCost = $cost;
               $bestSlots = [$slotName];
            }
         }
         $selected_idx = array_rand($bestSlots);
         $bestSlotName = $bestSlots[$selected_idx];

         $assigned[] = new SlotPlacement($starting_slots[$bestSlotName], $participant);
         $slot_tracker[$bestSlotName] -= 1;
         if (!$slot_tracker[$bestSlotName]) unset($slot_tracker[$bestSlotName]);
      }

      /* above algorithm will not yield optimal results - the best slot for a participant
       * might already be taken at the time this participant is considered
       * To fix this, we do a second round where we consider to swap participants if that improves their cost
       */
      /* create a list of cost/participant, and sort it descending by individual cost.
       * only consider the participants that are added via this call, and do not touch any pre-assigned participants */
      $optimize_list = $assigned->filter(fn($sp) => $participants->contains($sp->participant));
      $costs = array_map(fn($sp) => ['slot' => $sp, 'cost' => $calculator->calculateCost($sp->participant, $sp->slot->getName(), $assigned)], $optimize_list->values() );
      usort($costs, fn($a,$b) => $b['cost'] <=> $a['cost']);
      /* now check for each participant whether a switch with any other participant will improve the situation. */
      foreach( $costs as $entry )
      {
         /** @var SlotPlacement $from */
         $from = $entry['slot'];
         if( $get_pre_assign($from->participant) ) continue; // skip participants with fixed assignment
         $current_from_cost = $calculator->calculateCost($from->participant, $from->slot->getName(), $assigned);
         $bestCost = PHP_FLOAT_MAX;
         $bestTgt = null;
         foreach($optimize_list as $tgt ) // check with each other target
         {
            /** @var SlotPlacement $tgt */
            if( $tgt->slot === $from->slot ) continue; // skip non-switches
            if ($get_pre_assign($tgt->participant)) continue; // skip participants with fixed assignment

            /* calculate current cost for both participants */
            $current_cost = $current_from_cost + $calculator->calculateCost($tgt->participant, $tgt->slot->getName(), $assigned);

            /* attempt a switch (needs to be done to also update $assigned contents) */
            list($from->slot, $tgt->slot) = [$tgt->slot, $from->slot];

            /* calculate new cost */
            $new_cost = $calculator->calculateCost($from->participant, $from->slot->getName(), $assigned)
                      + $calculator->calculateCost($tgt->participant, $tgt->slot->getName(), $assigned);

            /* switch back for now */
            list($from->slot, $tgt->slot) = [$tgt->slot, $from->slot];

            /* store back if this switch would reduce the cost */
            if( $new_cost < $current_cost && $new_cost < $bestCost )
            {
               $bestTgt = $tgt;
               $bestCost = $new_cost;
            }
         }

         if( isset($bestTgt) )
         {
            /* actually execute the switch */
            list($from->slot, $bestTgt->slot) = [$bestTgt->slot, $from->slot];
         }
      }

      /* done, transform and return */
      $result = $this->updateSlotAssignments($assigned, $shuffled);
      $this->loadParticipants($result);
      return $result;
   }

   /**
    * pre-place BYEs if desired
    * distribute them evenly across the white slots
    * if there are more BYEs than matches, let the red BYEs be random
    * via the shuffling algorithm below
    * Having more BYEs than matches is not a relevant case, because this
    * means that the user should simply reduce the number of rounds.
    */
   private function removeByeSlots(MatchSlotCollection $starting_slots, int $participantCount): void
   {
      $slotCount = $starting_slots->count();
      $numBYEs = min($slotCount - $participantCount, $slotCount / 2); // limit at white slot count
      if ($numBYEs > 0)
      {
         // get list of all white slots (= every second starting slot)
         $slotNames = array_filter($starting_slots->keys(), fn($i) => $i % 2, ARRAY_FILTER_USE_KEY);
         // iteratively half the list of slots until we have a chunk for each BYE
         $slotStack = [$slotNames];
         while (count($slotStack) < $numBYEs)
         {
            $next = array_shift($slotStack);
            $half = count($next) / 2; // list is always even, no need to round
            $slotStack[] = array_slice($next, 0, $half);
            $slotStack[] = array_slice($next, $half);
         }
         // delete the first slot of each chunk (could also be the last, depends on taste)
         foreach ($slotStack as $chunk)
         {
            $starting_slots->offsetUnset($chunk[0]);
         }
      }
   }

   private function deriveSlotAllocationCounters(MatchSlotCollection $starting_slots, int $participantCount): array
   {
      /* calculate the number of participants per slot. */
      if ($this->struc->pools->empty() || $participantCount <= $starting_slots->count())
      {
         /* in pure KO mode (or if at most one participant per pool), it is exactly one per slot. */
         return array_fill_keys($starting_slots->keys(), 1);
      }
      else
      {
         /* With pools, spread evenly - with the first pools having the maximum number of participants,
          * and the later pools the minimum.
          * This is done because the pools are assigned to areas in an alternating manner.
          * In a pure random assignment, there is a relevant chance that one area catches all the big pools,
          * and another area only small pools. */
         $result = array_fill_keys($starting_slots->keys(), intval(floor($participantCount / $starting_slots->count())));
         $to_add = $participantCount % $starting_slots->count();
         foreach ($result as &$count)
         {
            if (!$to_add--) break;
            $count++;
         }
         return $result;
      }
   }

   /**
    * shuffle all participants, but consider possible shuffling parameters
    * TODO: consider "club interleaving" - keep a equal distribution of clubs,
    *       this *might* result into better seeding results - so far the seeding
    *       seems to work well enough without.
    */
   private function shuffleParticipantList(ParticipantCollection $participants): ParticipantCollection
   {
      $rng = new \Random\Randomizer();
      return ParticipantCollection::new($rng->shuffleArray($participants->values()));
   }

   /**
    * transform the resulting structure from the internally used SlotPlacementCollection
    * to the required ParticipantCollection
    */
   private function updateSlotAssignments(SlotPlacmentCollection $assigned, ParticipantCollection $unassigned): ParticipantCollection
   {
      $catId    = $this->struc->category->id;
      $result   = ParticipantCollection::new();
      $counters = [];

      /* store the assigned slot name in each participant, and add them to the result */
      foreach ($assigned as $a)
      {
         $a->participant->categories[$catId] ??= new CategoryAssignment($catId);
         /** @var SlotPlacement $a */
         if ($a->slot instanceof ParticipantSlot)
         {
            /* just take over directly */
            $a->participant->categories[$catId]->slot_name = $a->slot->getName();
         }
         else if ($a->slot instanceof PoolWinnerSlot)
         {
            /* pools - multiple participants per slot, we have to maintain a counter to ensure unique slot names for the Database */
            $poolName = $a->slot->getName();
            $counters[$poolName] ??= 0;
            $slot_name = "{$poolName}.{$counters[$poolName]}";
            $a->participant->categories[$catId]->slot_name = $slot_name;
            $counters[$a->slot->getName()] += 1;
         }
         else
         {
            throw new \LogicException('unexpected slot type ' . get_class($a->slot));
         }
         $result[] = $a->participant;
      }

      /* also add all unassigned participants to the result */
      foreach ($unassigned as $p)
      {
         $p->categories[$catId] ??= new CategoryAssignment($catId);
         $p->categories[$catId]->slot_name = null;
         $result [] = $p;
      }

      /* done */
      return $result;
   }

   /**
    * extract the MatchNode slots from a node collection
    */
   private function getSlots(MatchNodeCollection $nodes): MatchSlotCollection
   {
      $result = MatchSlotCollection::new();
      foreach ($nodes as $node)
      {
         /** @var KoNode $node */
         if ($node->slotRed->getName())   $result[$node->slotRed->getName()] = $node->slotRed;
         if ($node->slotWhite->getName()) $result[$node->slotWhite->getName()] = $node->slotWhite;
      }
      $result->ksort(SORT_STRING);
      return $result;
   }

   /**
    * extract any filled slots into a SlotPlacementCollection
    */
   private function getSlotPlacements(MatchSlotCollection $slots): SlotPlacmentCollection
   {
      $result = SlotPlacmentCollection::new();
      foreach ($slots as $slot)
      {
         if ($slot instanceof PoolWinnerSlot)
         {
            foreach ($slot->pool->getParticipants() as $p)
            {
               $result[] = new SlotPlacement($slot, $p);
            }
         }
         elseif ($slot instanceof ParticipantSlot)
         {
            if( $slot->participant ) $result[] = new SlotPlacement($slot, $slot->participant);
         }
         elseif ($slot instanceof ByeSlot )
         {
            /* nothing */
         }
         else
         {
            throw new \LogicException("unexpected slot type " . get_class($slot));
         }
      }
      return $result;
   }
}