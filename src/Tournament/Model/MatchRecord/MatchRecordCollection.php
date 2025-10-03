<?php

namespace Tournament\Model\MatchRecord;

/**
 * MatchRecord collection is organized by match name
 */
class MatchRecordCollection extends \Base\Model\ObjectCollection
{
   protected static function elements_type(): string
   {
      return MatchRecord::class;
   }

   public function offsetSet($offset, $value): void
   {
      if ($offset === null || $offset === $value->name)
      {
         parent::offsetSet($value->name, $value);
      }
      else
      {
         throw new \UnexpectedValueException("invalid offset, must use Match name " . $offset . " vs " . $value->name);
      }
   }
}
