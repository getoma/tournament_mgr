<?php declare(strict_types=1);

namespace Tournament\Model\TournamentStructure;

use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\TournamentStructure\MatchNode\KoNode;
use Tournament\Model\TournamentStructure\MatchNode\MatchNode;
use Tournament\Model\TournamentStructure\MatchNode\SoloKoMatch;
use Tournament\Model\TournamentStructure\MatchNode\SoloMatch;
use Tournament\Model\TournamentStructure\MatchNode\TeamKoMatch;
use Tournament\Model\TournamentStructure\MatchNode\TeamMatch;
use Tournament\Model\TournamentStructure\MatchSlot\MatchSlot;

class MatchNodeFactory
{
   public function __construct(
      public readonly Category $category,
   )
   {
   }

   /**
    * create a new simple match node in accordance to category configuration
    */
   public function createNode(
      string $node_name,
      MatchSlot $slotRed,       // slot contents may be modified, but the slot itself is fixed
      MatchSlot $slotWhite,     // slot contents may be modified, but the slot itself is fixed
      ?Area $area = null,
      bool $tiesAllowed = true, // whether a tied result is allowed
   ): MatchNode
   {
      if( $this->category->team_mode )
      {
         return new TeamMatch($node_name, $this->category, $slotRed, $slotWhite, $area, tiesAllowed: $tiesAllowed);
      }
      else
      {
         return new SoloMatch($node_name, $this->category, $slotRed, $slotWhite, $area, tiesAllowed: $tiesAllowed);
      }
   }

   /**
    * create a new KO match node in accordance to category configuration
    */
   public function createKoNode(
      string $node_name,
      MatchSlot $slotRed,       // slot contents may be modified, but the slot itself is fixed
      MatchSlot $slotWhite,     // slot contents may be modified, but the slot itself is fixed
      ?Area $area = null,
   ): KoNode
   {
      if ($this->category->team_mode)
      {
         return new TeamKoMatch($node_name, $this->category, $slotRed, $slotWhite, $area);
      }
      else
      {
         return new SoloKoMatch($node_name, $this->category, $slotRed, $slotWhite, $area);
      }
   }
}