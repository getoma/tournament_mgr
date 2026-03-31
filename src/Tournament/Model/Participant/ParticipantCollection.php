<?php

namespace Tournament\Model\Participant;

use Tournament\Model\TournamentStructure\MatchParticipant\MatchParticipantCollection;

class ParticipantCollection extends MatchParticipantCollection
{
   protected const DEFAULT_ELEMENTS_TYPE = Participant::class;
}
