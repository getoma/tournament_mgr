<?php

namespace Tournament\Model\MatchRecord;

/**
 * MatchRecord collection is organized by match name
 */
class MatchRecordCollection extends \Base\Model\IdObjectCollection
{
   protected const DEFAULT_ELEMENTS_TYPE = MatchRecord::class;

   static protected function get_id($value): mixed
   {
      return $value->name;
   }
}
