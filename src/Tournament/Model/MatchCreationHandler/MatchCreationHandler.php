<?php

namespace Tournament\Model\MatchCreationHandler;

use Tournament\Model\TournamentStructure\MatchNode\MatchNodeCollection;
use Tournament\Model\TournamentStructure\MatchParticipant\MatchParticipantCollection;

interface MatchCreationHandler
{
   function generate(MatchParticipantCollection $participants): MatchNodeCollection;
}