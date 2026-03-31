<?php declare(strict_types=1);

namespace Tournament\Model\TournamentStructure\MatchNode;

use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\MatchRecord\MatchRecord;
use Tournament\Model\TournamentStructure\MatchSlot\MatchSlot;

class SoloKoMatch extends SoloMatch implements KoNode
{
   // use constructor to forward parentNode links to child nodes
   public function __construct(
      string $name,
      Category $category,
      MatchSlot $slotRed,
      MatchSlot $slotWhite,
      ?Area $area = null,
      bool $frozen = false,
      bool $tieBreak = false,
      ?MatchRecord $matchRecord = null
   )
   {
      // for a KoNode, ties are never allowed - hard code this parameter of MatchNodeBase
      parent::__construct($name, $category, $slotRed, $slotWhite, $area,
                          matchRecord: $matchRecord,
                          frozen: $frozen, tieBreak: $tieBreak, tiesAllowed: false);
   }
}