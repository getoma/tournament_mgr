<?php

namespace Tournament\Model\MatchCreationHandler;

use Tournament\Model\Participant\ParticipantCollection;
use Tournament\Model\TournamentStructure\MatchNode\MatchNodeCollection;

interface MatchCreationHandler
{
   function generate(ParticipantCollection $participants): MatchNodeCollection;
}