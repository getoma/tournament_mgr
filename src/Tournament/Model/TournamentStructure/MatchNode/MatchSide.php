<?php declare(strict_types=1);

namespace Tournament\Model\TournamentStructure\MatchNode;

enum MatchSide: string
{
   case RED   = 'red';
   case WHITE = 'white';
}