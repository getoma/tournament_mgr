<?php

namespace Tournament\Model\Data;

class ParticipantCollection extends \Base\Model\IdObjectCollection
{
   protected static function elements_type(): string
   {
      return Participant::class;
   }
}
