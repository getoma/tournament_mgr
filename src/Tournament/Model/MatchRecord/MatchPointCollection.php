<?php

namespace Tournament\Model\MatchRecord;

use Tournament\Model\Participant\Participant;

class MatchPointCollection extends \Base\Model\IdObjectCollection
{
   private array $dropped = [];

   protected const DEFAULT_ELEMENTS_TYPE = MatchPoint::class;

   public function for(Participant $p): static
   {
      return $this->filter(fn(MatchPoint $pt) => $pt->participant === $p);
   }

   /***
    * extend offsetUnset(), in order to
    * - preserve any removed points for synchronization with the repository
    * - automatically remove any dependant points (identified via "caused_by")
    */
   public function offsetUnset($offset): void
   {
      /** @var MatchPoint $value */
      $value = $this->elements[$offset] ?? false;
      if( $value )
      {
         $this->dropped[] = $value;
         parent::offsetUnset($offset);
      }
   }

   public function getDropped(): static
   {
      return static::new($this->dropped);
   }
}
