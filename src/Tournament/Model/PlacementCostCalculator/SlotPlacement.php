<?php

namespace Tournament\Model\PlacementCostCalculator;

use Tournament\Model\TournamentStructure\MatchParticipant\MatchParticipant;
use Tournament\Model\TournamentStructure\MatchSlot\MatchSlot;

class SlotPlacement
{
   function __construct(
      public MatchSlot   $slot,
      public MatchParticipant $participant
   )
   {}
}