<?php

namespace Tournament\Model\TournamentStructure;

use Tournament\Model\Area\Area;
use Tournament\Model\MatchPointHandler\MatchPointHandler;
use Tournament\Model\MatchRecord\MatchRecord;
use Tournament\Model\PoolRankHandler\PoolRankHandler;
use Tournament\Model\TournamentStructure\MatchNode\KoNode;
use Tournament\Model\TournamentStructure\MatchNode\MatchNode;
use Tournament\Model\TournamentStructure\MatchSlot\MatchSlot;
use Tournament\Model\TournamentStructure\Pool\Pool;

final class TournamentStructureFactory
{
   public function __construct(private readonly MatchPointHandler $mpHdl,
                               private readonly PoolRankHandler $rankHdl)
   {}

   public function createMatchNode(
      string $name,
      MatchSlot $slotRed,    // slot contents may be modified, but the slot itself is fixed
      MatchSlot $slotWhite,  // slot contents may be modified, but the slot itself is fixed
      ?Area $area = null,
      bool $tie_break = false,
      ?MatchRecord $matchRecord = null,
      bool $frozen = false
   )
   {
      return new MatchNode($name, $slotRed, $slotWhite, $this->mpHdl, $area, $tie_break, $matchRecord, $frozen);
   }

   public function createKoNode(
      string $name,
      MatchSlot $slotRed,
      MatchSlot $slotWhite,
      ?Area $area = null,
      ?MatchRecord $matchRecord = null
   )
   {
      return new KoNode($name, $slotRed, $slotWhite, $this->mpHdl, $area, $matchRecord);
   }

   public function createPool(
      string $name,
      int $num_winners = 2,
      ?Area $area = null
   )
   {
      return new Pool($name, $this->rankHdl, $this, $num_winners, $area);
   }
}
