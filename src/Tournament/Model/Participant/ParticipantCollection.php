<?php

namespace Tournament\Model\Participant;

class ParticipantCollection extends \Base\Model\IdObjectCollection
{
   protected const DEFAULT_ELEMENTS_TYPE = Participant::class;
}
