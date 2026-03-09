<?php

namespace Tournament\Model\TournamentStructure\MatchNode;

use OutOfBoundsException;

class MatchNodeCollection extends \Base\Model\ObjectCollection
{
   protected const DEFAULT_ELEMENTS_TYPE = MatchNode::class;

   public function getIterator(): MatchNodeIterator
   {
      return new MatchNodeIterator($this);
   }

   public function getIteratorAt(string $name): MatchNodeIterator
   {
      $result = $this->getIterator();
      $result->goto($name);
      return $result;
   }

   public function findNode(string $name): ?MatchNode
   {
      try
      {
         return $this->getIteratorAt($name)->current();
      }
      catch( OutOfBoundsException )
      {
         return null;
      }
   }
}