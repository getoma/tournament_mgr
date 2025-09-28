<?php

namespace Tournament\Model\Data;

class MatchRecordCollection implements \IteratorAggregate, \Countable, \ArrayAccess
{
   private array $records = [];

   public function __construct(iterable $records = [])
   {
      foreach($records as $record)
      {
         if( $record instanceof MatchRecord )
         {
            $this->records[$record->name] = $record;
         }
         else
         {
            throw new \InvalidArgumentException("invalid record: must be instance of MatchRecord");
         }
      }
   }

   public function has(string $name): bool
   {
      return isset($this->records[$name]);
   }

   public function getIterator(): \Traversable
   {
      return new \ArrayIterator($this->records);
   }

   public function count(): int
   {
      return count($this->records);
   }

   public function offsetExists($offset): bool
   {
      return isset($this->records[$offset]);
   }

   public function offsetGet($offset): ?MatchRecord
   {
      return $this->records[$offset] ?? null;
   }

   public function offsetSet($offset, $value): void
   {
      if ($value instanceof MatchRecord)
      {
         if ($offset === null)
         {
            $this->records[$value->name] = $value;
         }
         else if ($offset === $value->name)
         {
            $this->records[$offset] = $value;
         }
         else
         {
            throw new \InvalidArgumentException("invalid offset: must be identical to match record name");
         }
      }
      else
      {
         throw new \InvalidArgumentException("invalid record: must be instance of MatchRecord");
      }
   }

   public function offsetUnset($offset): void
   {
      unset($this->records[$offset]);
   }
}
