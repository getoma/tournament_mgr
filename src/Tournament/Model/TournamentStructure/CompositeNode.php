<?php declare(strict_types=1);

namespace Tournament\Model\TournamentStructure;

use Tournament\Model\TournamentStructure\MatchNode\MatchNodeCollection;

interface CompositeNode
{
   /* return the list of matches of this composite node */
   public function getMatchList(): MatchNodeCollection;

   /* whether there are dedicated red/white side teams */
   public function hasTeams(): bool;
}