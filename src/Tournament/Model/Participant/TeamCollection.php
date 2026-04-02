<?php

namespace Tournament\Model\Participant;

class TeamCollection extends \Base\Model\IdObjectCollection
{
   protected const DEFAULT_ELEMENTS_TYPE = Team::class;
}
