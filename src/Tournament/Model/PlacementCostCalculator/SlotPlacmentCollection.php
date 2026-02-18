<?php

namespace Tournament\Model\PlacementCostCalculator;

use Base\Model\ObjectCollection;

class SlotPlacmentCollection extends ObjectCollection
{
   static protected function elements_type(): string
   {
      return SlotPlacement::class;
   }

}