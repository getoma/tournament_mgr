<?php

namespace Tournament\Model\Participant;

class ParticipantCollection extends \Base\Model\IdObjectCollection
{
   protected static function elements_type(): string
   {
      return Participant::class;
   }
}
