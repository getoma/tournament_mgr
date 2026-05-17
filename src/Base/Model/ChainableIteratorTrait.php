<?php

namespace Base\Model;

/**
 * default implementation of ChainableIterator interface,
 * using the methods of SeekableIterator
 */
trait ChainableIteratorTrait
{
   /* SeekableIterator methods required */
   abstract public function next(): void;
   abstract public function prev(): void;
   abstract public function seek(mixed $position): void;

   public function copy(): ChainableIterator
   {
      return clone $this;
   }

   public function skip(): ChainableIterator
   {
      $copy = clone $this;
      $copy->next();
      return $copy;
   }

   public function back(): ChainableIterator
   {
      $copy = clone $this;
      $copy->prev();
      return $copy;
   }

   public function jump(int $position): ChainableIterator
   {
      $copy = clone $this;
      $copy->seek($position);
      return $copy;
   }
}