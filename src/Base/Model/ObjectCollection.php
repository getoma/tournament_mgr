<?php

namespace Base\Model;

/**
 * a basic collection class to manage objects of same type
 */
abstract class ObjectCollection implements \IteratorAggregate, \Countable, \ArrayAccess
{
   protected array $elements = [];

   public static function new(iterable $data = []): static
   {
      return new static($data);
   }

   public function __construct(iterable $data = [])
   {
      foreach ($data as $value)
      {
         $this->offsetSet(null, $value);
      }
   }

   abstract static protected function elements_type(): string;

   public function keyExists(int|string $key): bool
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

   public function column(string $attr): array
   {
      return array_column($this->elements, $attr);
   }

   public function keys(): array
   {
      return array_keys($this->elements);
   }

   public function items(): array
   {
      return $this->elements;
   }

   public function front(): mixed
   {
      return $this->elements[array_key_first($this->elements)] ?? null;
   }

   public function back(): mixed
   {
      return $this->elements[array_key_last($this->elements)] ?? null;
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
         if( isset($offset) )
         {
            $this->elements[$offset] = $value;
         }
         else
         {
            $this->elements[] = $value;
         }
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

   public function drop($value): bool
   {
      $offset = array_search($value, $this->elements, true);
      if ($offset === false) return false;
      $this->offsetUnset($offset);
      return true;
   }

   public function unshift($value): void
   {
      array_unshift($this->elements, $value);
   }

   public function search($value): mixed
   {
      return array_search($value, $this->elements, true);
   }

   public function contains($value): bool
   {
      return $this->search($value) !== false;
   }

   public function reverse(): static
   {
      return static::new(array_reverse($this->elements));
   }

   public function column_map(string $attr): self
   {
      $result = self::new();
      $keys = array_column($this->elements, $attr);
      $result->elements = array_combine($keys, $this->elements);
      return $result;
   }

   public function slice(int $offset, ?int $length = null): static
   {
      return new static(array_slice($this->elements, $offset, $length));
   }

   public function filter(callable $callback): static
   {
      return new static(array_filter($this->elements, $callback));
   }

   public function any(callable $callback): bool
   {
      /* array_any only available from php8.4, and we need to be 8.3-compatible for now */
      foreach ($this->elements as $k => $e)
      {
         if ($callback($e, $k)) return true;
      }
      return false;
   }

   public function all(callable $callback): bool
   {
      /* array_all only available from php8.4, and we need to be 8.3-compatible for now */
      foreach( $this->elements as $k => $e)
      {
         if( !$callback($e, $k) ) return false;
      }
      return true;
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
