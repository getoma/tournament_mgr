<?php declare(strict_types=1);

namespace Tournament\Model\PlacementCostCalculator;

use Tournament\Model\TournamentStructure\KoTree;
use Tournament\Model\TournamentStructure\MatchParticipant\MatchParticipant;

interface PlacementCostCalculator
{
   function calculateCost(MatchParticipant $candidate, string $slotName, SlotPlacmentCollection $placed): float;
   function loadStructure(KoTree $ko): void;
}