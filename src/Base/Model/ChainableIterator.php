<?php

namespace Base\Model;

/**
 * extend the iterator interface with additional methods
 * that allow chaining
 */
interface ChainableIterator extends \SeekableIterator
{
   public function copy(): ChainableIterator;
   public function skip(): ChainableIterator;
   public function back(): ChainableIterator;
   public function jump(int $position): ChainableIterator;
}
