<?php declare(strict_types=1);

namespace Tournament\Model\TournamentStructure\MatchNode;

use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\TournamentStructure\MatchSlot\MatchSlot;

class TeamKoMatch extends TeamMatch implements KoNode
{
   // use constructor to forward parentNode links to child nodes
   public function __construct(
      string $name,
      Category $category,
      MatchSlot $slotRed,
      MatchSlot $slotWhite,
      ?Area $area = null,
      bool $frozen = false,
   )
   {
      // for a KoNode, ties are never allowed - hard code this parameter of MatchNodeBase
      parent::__construct($name, $category, $slotRed, $slotWhite, $area,
                          frozen: $frozen, tiesAllowed: false);
   }
}