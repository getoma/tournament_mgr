<?php

namespace Tournament\Model\TournamentStructure\MatchNode;

use Base\Model\ObjectCollection;

class MatchNodeCollection extends ObjectCollection
{
   static protected function elements_type(): string
   {
      return MatchNode::class;
   }

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
}