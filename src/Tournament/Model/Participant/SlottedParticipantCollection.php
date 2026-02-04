<?php

namespace Tournament\Model\Participant;

class SlottedParticipantCollection extends \Base\Model\ObjectCollection
{
   public readonly ParticipantCollection $unslotted;

   function __construct(iterable $data = [])
   {
      parent::__construct($data);
      $this->unslotted = ParticipantCollection::new();
   }

   protected static function elements_type(): string
   {
      return Participant::class;
   }

   public function offsetSet($offset, $value): void
   {
      if ($offset === null)
      {
         $this->unslotted[] = $value;
      }
      else
      {
         parent::offsetSet($offset, $value);
      }
   }

   public function getAllParticipants(): ParticipantCollection
   {
      return new ParticipantCollection( array_merge( $this->elements, $this->unslotted->values() ) );
   }
}
