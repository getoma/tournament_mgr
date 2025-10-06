<?php

namespace Base\Model;

/**
 * extend the iterator interface with additional methods
 * that allow chaining
 */
interface ChainableIterator extends \SeekableIterator
{
   public function skip(int $count = 1): ChainableIterator;
   public function back(int $count = 1): ChainableIterator;
   public function jump(int $position): ChainableIterator;
}
