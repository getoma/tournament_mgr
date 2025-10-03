<?php

namespace Tournament\Model\Participant;

class SlottedParticipantCollection extends \Base\Model\ObjectCollection
{
   private array $unslotted_ = [];

   protected static function elements_type(): string
   {
      return Participant::class;
   }

   public function offsetSet($offset, $value): void
   {
      if ($offset === null)
      {
         $this->unslotted_[] = $value;
      }
      else
      {
         parent::offsetSet($offset, $value);
      }
   }

   public function offsetUnset($offset): void
   {
      unset($this->data[$offset]);
   }

   public function addUnslotted(Participant $p): void
   {
      $this->unslotted_[] = $p;
   }

   public function unslotted(): ParticipantCollection
   {
      return new ParticipantCollection($this->unslotted_);
   }

   public function all(): ParticipantCollection
   {
      return new ParticipantCollection( array_merge( $this->values(), $this->unslotted() ) );
   }
}
