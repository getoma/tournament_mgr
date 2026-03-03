<?php

namespace Base\Model;

/**
 * a basic collection class to manage objects of same type
 */
class ObjectCollection implements \IteratorAggregate, \Countable, \ArrayAccess
{
   /**
    * enforced elements type
    * can be set by derived classes to form a specific collection
    */
   protected const DEFAULT_ELEMENTS_TYPE = null;

   protected array $elements = [];

   public static function new(iterable $data = [], $element_type = null): static
   {
      return new static($data, $element_type);
   }

   /**
    * spawn a new object with the given content, skipping the offsetSet checks
    */
   protected function _spawn(iterable $data): static
   {
      $result = static::new([], $this->element_type);
      $result->elements = $data;
      return $result;
   }

   /**
    * spawn a new ObjectCollection with the given content, skipping the offSet handling
    * AND downgrading the class
    */
   protected function _downspawn(iterable $data): self
   {
      $result = self::new([], $this->element_type);
      $result->elements = $data;
      return $result;
   }

   public function __construct(iterable $data = [], protected $element_type = null)
   {
      $this->element_type ??= static::DEFAULT_ELEMENTS_TYPE;

      foreach ($data as $value)
      {
         $this->offsetSet(null, $value);
      }
   }

   public function copy(): static
   {
      return $this->_spawn($this->elements);
   }

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

   public function column(string $attr, ?string $indexattr = null): array
   {
      return array_column($this->elements, $attr, $indexattr);
   }

   public function keys(): array
   {
      return array_keys($this->elements);
   }

   public function items(): array
   {
      return $this->elements;
   }

   public function first(): mixed
   {
      return $this->elements[array_key_first($this->elements)] ?? null;
   }

   public function last(): mixed
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
      if (!$this->element_type || ($value instanceof $this->element_type))
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
         throw new \InvalidArgumentException("invalid value: must be instance of " . $this->element_type . ", found " . get_class($value));
      }
   }

   public function offsetUnset($offset): void
   {
      unset($this->elements[$offset]);
   }

   public function drop($value): bool
   {
      $offset = $this->search($value);
      if ($offset === false) return false;
      $this->offsetUnset($offset);
      return true;
   }

   public function unshift($value): void
   {
      array_unshift($this->elements, $value);
   }

   public function shift(): mixed
   {
      return array_shift($this->elements);
   }

   public function search($value): mixed
   {
      return array_search($value, $this->elements, true);
   }

   public function find(callable $callback): mixed
   {
      return array_find($this->elements, $callback);
   }

   public function contains($value): bool
   {
      return $this->search($value) !== false;
   }

   public function reverse(): static
   {
      return $this->_spawn(array_reverse($this->elements));
   }

   public function column_map(string $attr): self
   {
      $keys = array_column($this->elements, $attr);
      return $this->_downspawn(array_combine($keys, $this->elements));
   }

   public function map_keys(callable $callback): self
   {
      $keys = array_map($callback, $this->elements);
      return $this->_downspawn(array_combine($keys, $this->elements));
   }

   public function map(callable $callback): array
   {
      return array_map($callback, $this->elements);
   }

   public function slice(int $offset, ?int $length = null): static
   {
      return $this->_spawn(array_slice($this->elements, $offset, $length));
   }

   public function filter(callable $callback, int $mode = 0): static
   {
      return $this->_spawn(array_filter($this->elements, $callback, $mode), $this->element_type);
   }

   public function intersect(ObjectCollection $other, ?callable $cmp = null): static
   {
      if( !($other instanceof static) ) throw new \LogicException('attempt to intersect different ObjectCollections');
      $result = self::new([], $this->element_type);
      if( isset($cmp) ) $result->elements = array_uintersect($this->elements, $other->elements, $cmp);
      else $result->elements = array_intersect($this->elements, $other->elements);
      return $result;
   }

   public function intersect_key(ObjectCollection $other, ?callable $cmp = null): static
   {
      $result = self::new([], $this->element_type);
      if( isset($cmp) ) $result->elements = array_intersect_ukey($this->elements, $other->elements, $cmp);
      else $result->elements = array_intersect_key($this->elements, $other->elements);
      return $result;
   }

   public function reduce(callable $callback, $initial=null): mixed
   {
      return array_reduce($this->elements, $callback, $initial);
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

   public function walk(callable $callback, $arg = null): void
   {
      array_walk($this->elements, $callback, $arg);
   }

   public function merge(ObjectCollection|array $other): static
   {
      if( $other instanceof self ) $other = $other->values();
      return static::new(array_merge($this->elements, $other), $this->element_type);
   }

   public function ksort(int $flags = SORT_REGULAR): static
   {
      $cpy = $this->elements;
      ksort($cpy, $flags);
      return static::_spawn($cpy);
   }

   public function usort(callable $callback): static
   {
      $cpy = $this->elements;
      usort($cpy, $callback);
      return static::new($cpy); // cpy is re-indexed after sort, regenerated the id-indexes via new
   }

   /**
    * merge $other into $this without creating a new copy
    * @param $other   - the other collection to merge
    * @param $replace - if true, any duplicate in $other will replace the object inside $this. if false, duplicates in $other will be dropped
    */
   public function mergeInPlace(iterable $other, bool $replace = true): void
   {
      foreach( $other as $v )
      {
         if($replace || !$this->contains($v) ) $this[] = $v;
      }
   }
}
