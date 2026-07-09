<?php

namespace Base\Model;

/**
 * a collection class to manage objects that have an attribute "id"
 * and should be identified via their id, as well.
 */
class IdObjectCollection extends ObjectCollection
{
   static protected function get_id($value): mixed
   {
      return $value->id;
   }

   public function offsetSet($offset, $value): void
   {
      $value_id = static::get_id($value);
      if( $offset !== null && $offset != $value_id ) // do not require typ match on value_id
      {
         throw new \OutOfBoundsException("invalid offset: must be identical to object id, got " . $offset . " vs " . $value_id);
      }

      if( $value_id === null )
      {
         parent::offsetSet(spl_object_hash($value), $value);
      }
      else
      {
         parent::offsetSet($value_id, $value);
      }
   }

   public function search($value): mixed
   {
      $value_id = static::get_id($value) ?? spl_object_hash($value);
      $found = $this->elements[$id = $value_id] ?? $this->elements[$id = spl_object_hash($value)] ?? null;
      return $value === $found? $id : false;
   }

   public function contains($value): bool
   {
      return $this->search($value) !== false;
   }

   public function unshift($value): void
   {
      $id = static::get_id($value) ?? spl_object_hash($value);
      $this->elements = [$id => $value] + $this->elements;
   }

   public function reverse(): static
   {
      return static::new(array_reverse($this->elements, true), $this->element_type);
   }

   public function intersect(ObjectCollection $other, ?callable $cmp = null): static
   {
      // in case of IdObjectCollection, we can use the ID for the sorting, and make this
      // method also functional out-of-the box for non-stringable objects
      // no need to check whether $other is of the same class, that is done in parent::intersect() anyway
      $cmp ??= fn($a, $b) => static::get_id($a) <=> static::get_id($b);
      return parent::intersect($other, $cmp);
   }

   /**
    * for IdObjectCollection, enforce key preservation
    */
   public function chunk(int $length, bool $preserve_keys = false): array
   {
      return parent::chunk($length, true);
   }
}
