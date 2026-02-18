<?php

namespace Tournament\Model\PlacementCostCalculator;

use Tournament\Model\Participant\Participant;
use Tournament\Model\TournamentStructure\MatchNode\KoNode;

interface PlacementCostCalculator
{
   function calculateCost(Participant $candidate, string $slotName, SlotPlacmentCollection $placed): float;
   function loadStructure(KoNode $root): void;
}