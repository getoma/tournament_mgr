<?php

namespace Tournament\Model\MatchCreationHandler;

use Tournament\Model\Participant\ParticipantCollection;
use Tournament\Model\TournamentStructure\MatchNode\MatchNodeCollection;
use Tournament\Model\TournamentStructure\TournamentStructureFactory;

interface MatchCreationHandler
{
   function generate(ParticipantCollection $participants, TournamentStructureFactory $nodeFactory): MatchNodeCollection;
}