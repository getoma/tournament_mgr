<?php

namespace Tournament\Model\PlacementCostCalculator;

use Tournament\Model\Participant\Participant;
use Tournament\Model\TournamentStructure\MatchSlot\MatchSlot;

class SlotPlacement
{
   function __construct(
      public MatchSlot   $slot,
      public Participant $participant
   )
   {}
}