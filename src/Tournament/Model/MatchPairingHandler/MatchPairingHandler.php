<?php

namespace Tournament\Model\MatchPairingHandler;

use Tournament\Model\Participant\ParticipantCollection;
use Tournament\Model\TournamentStructure\MatchNode\MatchNodeCollection;
use Tournament\Model\TournamentStructure\TournamentStructureFactory;

interface MatchPairingHandler
{
   function generate(ParticipantCollection $participants, TournamentStructureFactory $nodeFactory): MatchNodeCollection;
}