<?php

namespace Base\Model;

/**
 * a collection class to manage objects that have an attribute "id"
 * and should be identified via their id, as well.
 */
abstract class IdObjectCollection extends ObjectCollection
{
   public function offsetSet($offset, $value): void
   {
      if ($offset === null || (int)$offset === $value->id)
      {
         parent::offsetSet($value->id, $value);
      }
      else
      {
         throw new \OutOfBoundsException("invalid offset: must be identical to object id, got " . $offset . " vs " . $value->id);
      }
   }

   public function search($value): mixed
   {
      $found = $this->elements[$value->id] ?? null;
      return $value === $found? $value->id : false;
   }

   public function contains($value): bool
   {
      return $value === ($this->elements[$value->id]??null);
   }

   public function unshift($value): void
   {
      $this->elements = [$value->id => $value] + $this->elements;
   }

   public function reverse(): static
   {
      return $this->new(array_reverse($this->elements, true));
   }
}
