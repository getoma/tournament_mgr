<?php

namespace Base\Model;

/**
 * a collection class to manage objects that have an attribute "id"
 * and should be identified via their id, as well.
 */
abstract class IdObjectCollection extends ObjectCollection
{
   static protected function get_id($value): mixed
   {
      return $value->id;
   }

   public function offsetSet($offset, $value): void
   {
      $value_id = static::get_id($value);
      if ( ($offset === null) || ($offset == $value_id) )
      {
         parent::offsetSet($value_id, $value);
      }
      else
      {
         throw new \OutOfBoundsException("invalid offset: must be identical to object id, got " . $offset . " vs " . $value_id);
      }
   }

   public function search($value): mixed
   {
      $value_id = static::get_id($value);
      $found = $this->elements[$value_id] ?? null;
      return $value === $found? $value_id : false;
   }

   public function contains($value): bool
   {
      return $value === ($this->elements[static::get_id($value)]??null);
   }

   public function unshift($value): void
   {
      $this->elements = [static::get_id($value) => $value] + $this->elements;
   }

   public function reverse(): static
   {
      return $this->new(array_reverse($this->elements, true));
   }
}
