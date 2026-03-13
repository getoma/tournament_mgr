<?php

namespace Base\Model;

/**
 * default implementation of ChainableIterator interface,
 * using the methods of SeekableIterator
 */
trait ChainableIteratorTrait
{
   public function copy(): static
   {
      return clone $this;
   }

   public function skip(): static
   {
      $copy = clone $this;
      $copy->next();
      return $copy;
   }

   public function back(): static
   {
      $copy = clone $this;
      $copy->prev();
      return $copy;
   }

   public function jump(int $position): static
   {
      $copy = clone $this;
      $copy->seek($position);
      return $copy;
   }
}