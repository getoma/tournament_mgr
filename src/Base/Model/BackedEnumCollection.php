<?php

namespace Base\Model;

class BackedEnumCollection implements \IteratorAggregate, \Countable, \ArrayAccess
{
   /**
    * internal content
    */
   protected array $elements = [];

   /**
    * enforced elements type
    * can be set by derived classes to form a specific collection
    */
   protected const ELEMENTS_TYPE = null;

   /**
    * construction
    */
   public static function new(iterable $data = [] ): static
   {
      return new static($data);
   }

   public function __construct(iterable $data = [])
   {
      foreach ($data as $value)
      {
         $this->add($value);
      }
   }

   /*******************************
    * native interface
    ******************************/

   public function empty(): bool
   {
      return count($this->elements) === 0;
   }

   public function values(): array
   {
      return array_values($this->elements);
   }

   public function drop(\BackedEnum|string $value): void
   {
      $value = $this->loadEnum($value);
      unset($this->elements[$value->value]);
   }

   public function add(\BackedEnum|string $value): void
   {
      $value = $this->loadEnum($value);
      if( get_class($value) !== static::ELEMENTS_TYPE && static::ELEMENTS_TYPE !== null )
      {
         throw new \InvalidArgumentException("invalid value: must be instance of ". static::ELEMENTS_TYPE . ", found " . get_class($value));
      }
      $this->elements[$value->value] = $value;
   }

   public function contains(\BackedEnum|string $value): bool
   {
      $value = $this->loadEnum($value);
      return isset($this->elements[$value->value]);
   }

   public function diff(BackedEnumCollection|array $other): BackedEnumCollection
   {
      $cmp = fn($a, $b) => $a->value !== $b->value;
      if( $other instanceof self ) $other = $other->values();
      return static::new( array_udiff($this->elements, $other, $cmp) );
   }

   public function sym_diff(BackedEnumCollection|array $other): BackedEnumCollection
   {
      $cmp = fn($a,$b) => $a->value !== $b->value;
      if ($other instanceof self) $other = $other->values();

      return static::new(
         array_merge( array_udiff($this->elements, $other, $cmp),
                      array_udiff($other, $this->elements, $cmp))
      );
   }

   public function intersect(BackedEnumCollection|array $other): BackedEnumCollection
   {
      $cmp = fn($a,$b) => $a->value <=> $b->value;
      if ($other instanceof self) $other = $other->values();
      return static::new( array_uintersect($this->elements, $other, $cmp));
   }

   /*******************************
    * IteratorAggregate interface
    ******************************/

   public function getIterator(): \Traversable
   {
      return new \ArrayIterator($this->elements);
   }

   /*************************
    * Countable interface
    *************************/

   public function count(): int
   {
      return count($this->elements);
   }

   /*************************
    * ArrayAccess interface
    *************************/

   /**
    * check if a value is contained
    */
   public function offsetExists($offset): bool
   {
      return $this->contains($offset);
   }

   /**
    * return the value object if it is contained, null otherwise
    */
   public function offsetGet($offset): mixed
   {
      $obj = $this->loadEnum($offset);
      return $this->contains($obj)? $obj : null;
   }

   /**
    * add/drop an enum value
    */
   public function offsetSet($offset, $value): void
   {
      if( $value ) $this->add($offset);
      else $this->drop($offset);
   }

   /**
    * drop an enum value
    */
   public function offsetUnset($offset): void
   {
      $this->drop($offset);
   }


   /*********************
    * internal methods
    *********************/

   /***
    * normalize an offset value to be of the right enum type
    */
   private function loadEnum(\BackedEnum|string $value): \BackedEnum
   {
      if ($value instanceof \BackedEnum) return $value;

      $class = static::ELEMENTS_TYPE;
      if (isset($class))
      {
         return $class::from($value);
      }
      else
      {
         throw new \LogicException("cannot load anonymous enumeration object");
      }
   }
}