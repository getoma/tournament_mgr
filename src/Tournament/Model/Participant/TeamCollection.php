<?php

namespace Tournament\Model\Participant;

use Tournament\Model\TournamentStructure\MatchParticipant\MatchParticipantCollection;

class TeamCollection extends MatchParticipantCollection
{
   protected const DEFAULT_ELEMENTS_TYPE = Team::class;
}
