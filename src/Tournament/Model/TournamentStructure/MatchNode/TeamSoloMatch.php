<?php

declare(strict_types=1);

namespace Tournament\Model\TournamentStructure\MatchNode;

use Tournament\Model\MatchRecord\SoloMatchRecord;
use Tournament\Model\TournamentStructure\MatchSlot\ParticipantSlot;

/**
 * an atomar match within a TeamMatch node
 * extends SoloMatch with a link to the composite node.
 */
class TeamSoloMatch extends SoloMatch
{
   public function __construct(
      string $node_name,
      public readonly TeamMatch $parent,
      ParticipantSlot $slotRed,
      ParticipantSlot $slotWhite,
      bool $tieBreak = false,
   )
   {
      return parent::__construct($node_name, $parent->category, $slotRed, $slotWhite, $parent->getArea(), $parent->isFrozen(), $tieBreak, true);
   }

   /**
    * provide the match record for this node.
    * if none available yet, initialize it.
    */
   public function provideMatchRecord(): SoloMatchRecord
   {
      if( !isset($this->matchRecord) )
      {
         $precord = $this->parent->provideMatchRecord();
         $this->matchRecord = new SoloMatchRecord(
            id: null,
            name: $this->getName(),
            category: $this->category,
            area: $this->getArea(),
            tie_break: $this->isTieBreak(),
            redParticipant: $this->getRedParticipant(),
            whiteParticipant: $this->getWhiteParticipant(),
            team_match: $precord,
         );
         $precord->matches[] = $this->matchRecord;
      }
      return $this->matchRecord;
   }
}