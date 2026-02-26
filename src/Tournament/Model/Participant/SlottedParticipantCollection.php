<?php

namespace Tournament\Model\Participant;

class SlottedParticipantCollection extends \Base\Model\ObjectCollection
{
   protected const DEFAULT_ELEMENTS_TYPE = Participant::class;

   public readonly ParticipantCollection $unslotted;

   function __construct(iterable $data = [])
   {
      $this->unslotted = ParticipantCollection::new();
      parent::__construct($data);
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
