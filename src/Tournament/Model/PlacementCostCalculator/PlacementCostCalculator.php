<?php declare(strict_types=1);

namespace Tournament\Model\PlacementCostCalculator;

use Tournament\Model\Participant\Participant;
use Tournament\Model\TournamentStructure\KoTree;

interface PlacementCostCalculator
{
   function calculateCost(Participant $candidate, string $slotName, SlotPlacmentCollection $placed): float;
   function loadStructure(KoTree $ko): void;
}