<?php

namespace Tournament\Model\TournamentStructure;

use Tournament\Model\Participant\Participant;
use Tournament\Model\Participant\ParticipantCollection;
use Tournament\Model\Participant\SlottedParticipantCollection;
use Tournament\Model\PlacementCostCalculator\SlotPlacement;
use Tournament\Model\PlacementCostCalculator\SlotPlacmentCollection;
use Tournament\Model\TournamentStructure\MatchNode\MatchNodeCollection;
use Tournament\Model\TournamentStructure\MatchSlot\MatchSlotCollection;
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
   public function loadParticipants(SlottedParticipantCollection $participants)
   {
      $this->struc->unmapped_participants = $participants->unslotted;

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
    * assign participants to each KO first round slot according the given mapping
    */
   private function loadKoParticipants(SlottedParticipantCollection $participants)
   {
      $slots = $this->getSlots($this->struc->ko->getFirstRound());
      foreach ($participants as $slotId => $p)
      {
         if (isset($slots[$slotId])) $slots[$slotId]->participant = $p;
         else $this->struc->unmapped_participants[] = $p;
      }
   }

   /**
    * assign participants into pools according the given mapping
    */
   private function loadPoolParticipants(SlottedParticipantCollection $participants)
   {
      /* distribute the participants to a separate collection for each pool */
      $pool_participants = [];
      foreach ($participants as $slotId => $p)
      {
         list($poolId, $slotNr) = explode('.', $slotId);
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

      /* forward the collected participants to each pool */
      foreach ($pool_participants as $id => $col)
      {
         $this->struc->pools[$id]->setParticipants($col);
      }
   }

   /**
    * populate a TournamentStructure with a collection of Participants
    * return the map of slot assignments for each participant
    */
   public function populate(ParticipantCollection $participants): SlottedParticipantCollection
   {
      $calculator = $this->struc->category->getPlacementCostCalculator();
      $calculator->loadStructure($this->struc->ko);

      $first_round = $this->struc->ko->getFirstRound();
      $starting_slots = $this->getSlots($first_round);

      /* in case of a pure KO mode, put the BYE slots to fixed places. */
      if ($this->struc->pools->empty())
      {
         $this->removeByeSlots($starting_slots, $participants);
      }

      /* derive the number of participants per starting slot */
      $slot_tracker = $this->deriveSlotAllocationCounters($starting_slots, $participants);

      /* initialize the list of slot assignments */
      $assigned = SlotPlacmentCollection::new();

      /* helper function to get a possible pre-assign value from a participant */
      $get_pre_assign = fn(Participant $p) => $p->categories[$this->struc->category->id]?->pre_assign ?? null;

      /* assign any manuell presets */
      foreach( $participants->values() as $p ) // copy the participant collection, because we will modify the original now
      {
         /** @var Participant $p */
         $pre_assign_slot = $get_pre_assign($p);
         if (!$pre_assign_slot) continue;

         $slotName = null;
         if ($this->struc->pools->empty() )
         {
            /* pre-assign value is a MatchNode Name/ID, assign to its red slot */
            $node = $first_round->findNode( $pre_assign_slot );
            if( isset($node) ) $slotName = $node->slotRed->getName();
         }
         else
         {
            /* pre-assign value is a pool name, get a slot pointing to this pool */
            $pool = $this->struc->pools[$pre_assign_slot] ?? null;
            if (isset($pool)) $slotName = $pool->getName();
         }

         if( isset($slotName ) )
         {
            $assigned[] = new SlotPlacement($starting_slots[$slotName], $p);
            $participants->drop($p);
            $slot_tracker[$slotName] -= 1;
            if (!$slot_tracker[$slotName]) unset($slot_tracker[$slotName]);
         }
      }

      /* shuffle and seed the participants */
      $shuffled = $this->shuffleParticipantList($participants);
      while ($slot_tracker && $participant = array_shift($shuffled))
      {
         $bestSlots = [];
         $bestCost = PHP_FLOAT_MAX;
         /* check each available slot for the cost it would generate, memorize the cheapest */
         foreach ($slot_tracker as $slotName => $free_count)
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
         if (!$slot_tracker[$slotName]) unset($slot_tracker[$slotName]);
      }

      /* above algorithm will not yield optimal results - the best slot for a participant
       * might already be taken at the time this participant is considered
       * To fix this, we do a second round where we consider to swap participants if that improves their cost
       */
      /* create a list of cost/participant, and sort it descending by individual cost */
      $costs = array_map(fn($sp) => ['slot' => $sp, 'cost' => $calculator->calculateCost($sp->participant, $sp->slot->getName(), $assigned)], $assigned->values() );
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
         foreach( $assigned as $tgt ) // check with each other target
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
      $result = $this->getSlottedCollection($assigned, $shuffled);
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
   private function removeByeSlots(MatchSlotCollection $starting_slots, ParticipantCollection $participants): void
   {
      $slotCount = $starting_slots->count();
      $numBYEs = min($slotCount - $participants->count(), $slotCount / 2); // limit at white slot count
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

   private function deriveSlotAllocationCounters(MatchSlotCollection $starting_slots, ParticipantCollection $participants): array
   {
      /* calculate the number of participants per slot. */
      if ($this->struc->pools->empty() || $participants->count() <= $starting_slots->count())
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
         $result = array_fill_keys($starting_slots->keys(), intval(floor($participants->count() / $starting_slots->count())));
         $to_add = $participants->count() % $starting_slots->count();
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
   private function shuffleParticipantList(ParticipantCollection $participants): array
   {
      $rng = new \Random\Randomizer();
      return $rng->shuffleArray($participants->values());
   }

   /**
    * transform the resulting structure from the internally used SlotPlacementCollection
    * to the required SlottedParticipantCollection
    */
   private function getSlottedCollection(SlotPlacmentCollection $assigned, array $unassigned): SlottedParticipantCollection
   {
      $result   = SlottedParticipantCollection::new();
      $counters = [];
      foreach ($assigned as $a)
      {
         /** @var SlotPlacement $a */
         if ($a->slot instanceof ParticipantSlot)
         {
            /* just take over directly */
            $result[$a->slot->getName()] = $a->participant;
         }
         else if ($a->slot instanceof PoolWinnerSlot)
         {
            /* pools - multiple participants per slot, we have to maintain a counter to ensure unique slot names for the Database */
            $counters[$a->slot->getName()] ??= 0;
            $result["{$a->slot->getName()}.{$counters[$a->slot->getName()]}"] = $a->participant;
            $counters[$a->slot->getName()] += 1;
         }
         else
         {
            throw new \LogicException('unexpected slot type ' . get_class($a->slot));
         }
      }
      foreach ($unassigned as $p)
      {
         $result->unslotted[] = $p;
      }
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
         if ($node->slotRed->getName())   $result[] = $node->slotRed;
         if ($node->slotWhite->getName()) $result[] = $node->slotWhite;
      }
      return $result;
   }
}