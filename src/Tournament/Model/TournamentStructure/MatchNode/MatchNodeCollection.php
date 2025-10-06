<?php

namespace Tournament\Model\TournamentStructure\MatchNode;

use Base\Model\ObjectCollection;

class MatchNodeCollection extends ObjectCollection
{
   static protected function elements_type(): string
   {
      return MatchNode::class;
   }

   public function __construct(iterable $data = [])
   {
      /* need to duplicate parent constructor, because we disallow offsetSet in this class */
      foreach ($data as $value)
      {
         parent::offsetSet(null, $value);
      }
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

   public function offsetSet($offset, $value): void
   {
      throw new \LogicException("attempt to modify a MatchNodeCollection");
   }

   public function offsetUnset($offset): void
   {
      throw new \LogicException("attempt to modify a MatchNodeCollection");
   }
}