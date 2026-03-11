<?php

namespace Tournament\Model\TournamentStructure\MatchNode;

class MatchRoundCollection extends \Base\Model\ObjectCollection
{
   protected const DEFAULT_ELEMENTS_TYPE = MatchNodeCollection::class;

   public function flatten(): MatchNodeCollection
   {
      return MatchNodeCollection::new( array_merge(...array_map(fn($e) => $e->values(), $this->elements)));
   }

   public function filterRounds(callable $callback, int $mode = 0): static
   {
      return $this->clone_with( array_map( fn($r) => $r->filter($callback, $mode), $this->elements) );
   }

   public function getNodeIterator(): MatchRoundIterator
   {
      return new MatchRoundIterator($this);
   }

   public function getNodeIteratorAt(string $name): MatchRoundIterator
   {
      $result = $this->getNodeIterator();
      $result->goto($name);
      return $result;
   }

   public function findNode(string $name): ?MatchNode
   {
      try
      {
         return $this->getNodeIteratorAt($name)->current();
      }
      catch (\OutOfBoundsException)
      {
         return null;
      }
   }
}
