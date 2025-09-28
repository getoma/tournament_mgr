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
         throw new \InvalidArgumentException("invalid offset: must be identical to object id, got " . $offset . " vs " . $value->id);
      }
   }
}
