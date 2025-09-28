<?php

namespace Tournament\Model\TournamentStructure;

use Tournament\Model\TournamentStructure\MatchSlot;
use Tournament\Model\Data\Participant;
use Tournament\Model\Data\Area;
use Tournament\Model\Data\MatchRecord;

class MatchNode
{
   private ?MatchRecord $matchRecord = null;

   public function __construct(
      public string $name,
      public MatchSlot $slotRed,
      public MatchSlot $slotWhite,
      public ?Area $area = null,
      ?MatchRecord $matchRecord = null
   )
   {
      if( $this->slotRed === $this->slotWhite )
      {
         throw new \InvalidArgumentException("invalid match: red and white slot must be different");
      }

      $this->setMatchRecord($matchRecord);
   }

   /**
    * set the match record associated with this match node
    * verify that the match record is consistent with the participants in this match
    * @param MatchRecord|null $matchRecord
    */
   public function setMatchRecord(?MatchRecord $matchRecord): void
   {
      if( !isset($matchRecord))
      {
         $this->matchRecord = null;
         return;
      }

      if( $matchRecord->name !== $this->name )
      {
         throw new \InvalidArgumentException("inconsistent match record: name does not match");
      }

      $p_red   = $this->slotRed->getParticipant();
      $p_white = $this->slotWhite->getParticipant();

      if( !isset($p_red))   throw new \LogicException("cannot assign match record: no valid red participant");
      if( !isset($p_white)) throw new \LogicException("cannot assign match record: no valid white participant");

      if ($p_red->id !== $matchRecord->redParticipant->id)
      {
         throw new \InvalidArgumentException("inconsistent match record: red participant does not match");
      }
      if (($p_white->id !== $matchRecord->whiteParticipant->id))
      {
         throw new \InvalidArgumentException("inconsistent match record: white participant does not match");
      }

      $this->matchRecord = $matchRecord;
   }

   public function getMatchRecord(): ?MatchRecord
   {
      return $this->matchRecord;
   }

   public function isBye(): bool
   {
      return $this->slotRed->isBye() && $this->slotWhite->isBye();
   }

   /**
    * get the winner of this match, or null if not decided, yet
    * @return Participant|null
    */
   public function getWinner(): ?Participant
   {
      if ($this->slotRed->isBye())   return $this->slotWhite->getParticipant();
      if ($this->slotWhite->isBye()) return $this->slotRed->getParticipant();
      if ($this->matchRecord)        return $this->matchRecord->winner;
      return null;
   }

   /**
    * get the defeated participant of this match, or null if not decided, yet
    * @return Participant|null
    */
   public function getDefeated(): ?Participant
   {
      if ($this->slotRed->isBye())   return null;
      if ($this->slotWhite->isBye()) return null;
      if ($this->matchRecord)
      {
         if( !$this->matchRecord->winner ) return null; // winner not decided, yet
         return $this->matchRecord->winner === $this->matchRecord->redParticipant
            ? $this->matchRecord->whiteParticipant
            : $this->matchRecord->redParticipant;
      }
      return null;
   }
}
