<?php

namespace Tournament\Model\TournamentStructure\MatchNode;

use Tournament\Model\TournamentStructure\MatchSlot\MatchSlotCollection;

class MatchNodeCollection extends \Base\Model\ObjectCollection
{
   protected const DEFAULT_ELEMENTS_TYPE = MatchNode::class;

   public function getIterator(): MatchNodeIterator
   {
      return new MatchNodeIterator($this);
   }

   public function getNodeIteratorAt(string $name): MatchNodeIterator
   {
      $result = $this->getIterator();
      $result->goto($name);
      return $result;
   }

   public function findNode(string $name): ?MatchNode
   {
      try
      {
         return $this->getNodeIteratorAt($name)->current();
      }
      catch(\OutOfBoundsException)
      {
         return null;
      }
   }

   public function getNamedSlots(): MatchSlotCollection
   {
      $result = MatchSlotCollection::new();
      /** @var MatchNode $node */
      foreach ($this->elements as $node)
      {
         foreach ($node->getSlots() as $slot)
         {
            if ($slot->getName()) $result[] = $slot;
         }
      }
      return $result;
   }
}