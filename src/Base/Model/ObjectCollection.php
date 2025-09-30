<?php

namespace Base\Model;

/**
 * a basic collection class to manage objects of same type
 */
abstract class ObjectCollection implements \IteratorAggregate, \Countable, \ArrayAccess
{
   protected array $elements = [];

   public static function new(): static
   {
      return new static();
   }

   public function __construct(iterable $data = [])
   {
      foreach ($data as $value)
      {
         $this->offsetSet(null, $value);
      }
   }

   abstract static protected function elements_type(): string;

   public function has(int|string $key): bool
   {
      return isset($this->elements[$key]);
   }

   public function empty(): bool
   {
      return count($this->elements) === 0;
   }

   public function values(): array
   {
      return array_values($this->elements);
   }

   public function keys(): array
   {
      return array_keys($this->elements);
   }

   public function items(): array
   {
      return $this->elements;
   }

   public function getIterator(): \Traversable
   {
      return new \ArrayIterator($this->elements);
   }

   public function count(): int
   {
      return count($this->elements);
   }

   public function offsetExists($offset): bool
   {
      return isset($this->elements[$offset]);
   }

   public function offsetGet($offset): mixed
   {
      return $this->elements[$offset] ?? null;
   }

   public function offsetSet($offset, $value): void
   {
      $type = $this->elements_type();
      if ($value instanceof $type)
      {
         $this->elements[$offset] = $value;
      }
      else
      {
         throw new \InvalidArgumentException("invalid value: must be instance of " . $type . ", found " . get_class($value));
      }
   }

   public function offsetUnset($offset): void
   {
      unset($this->elements[$offset]);
   }

   // support empty() on object
   public function __isset($name): bool
   {
      if ($name === '0')
      {
         return $this->count() > 0;
      }
      return false;
   }
}
