<?php

namespace Tournament\Service;

use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\Participant\Participant;
use Tournament\Model\Tournament\Tournament;
use Tournament\Model\TournamentStructure\MatchNode\MatchNode;
use Tournament\Model\TournamentStructure\Pool\Pool;

class RouteArgsContext
{
   public function __construct(
      public array $args = [],
      public ?Tournament  $tournament  = null,
      public ?Category    $category    = null,
      public ?Area        $area        = null,
      public ?Participant $participant = null,
      public ?Pool        $pool        = null,
      public ?MatchNode   $match       = null,
   )
   {}
}