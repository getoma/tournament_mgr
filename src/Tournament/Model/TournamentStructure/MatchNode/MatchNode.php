<?php

namespace Tournament\Model\TournamentStructure\MatchNode;

use Tournament\Model\Participant\Participant;
use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\MatchRecord\MatchRecord;

use Tournament\Model\TournamentStructure\MatchSlot\MatchSlot;

/**
 * base class to manage an atomar match, which might be part of a KO tree, a pool, or whatever
 * only provides methods to query/handle its own state, and doesn't care about what exactly
 * is behind its input slots.
 */
class MatchNode
{
   public function __construct(
      public string $name,
      public readonly MatchSlot $slotRed,    // slot contents may be modified, but the slot itself is fixed
      public readonly MatchSlot $slotWhite,  // slot contents may be modified, but the slot itself is fixed
      public  ?Area $area = null,
      private bool $tie_break = false,
      private ?MatchRecord $matchRecord = null
   )
   {
      if( $this->slotRed === $this->slotWhite )
      {
         throw new \DomainException("invalid match: red and white slot must be different");
      }

      $this->setMatchRecord($matchRecord);
   }

   /**
    * set the match record associated with this match node
    * verify that the match record is consistent with this node
    * @param MatchRecord|null $matchRecord
    */
   public function setMatchRecord(?MatchRecord $matchRecord): void
   {
      if( !isset($matchRecord))
      {
         $this->matchRecord = null;
         return;
      }

      if( !$this->isReal() )
      {
         throw new \LogicException("attempt to assign a match record to non-real match");
      }

      if( $matchRecord->name !== $this->name )
      {
         throw new \DomainException("inconsistent match record: name does not match");
      }

      /* make sure the contained participants match with the participants according the tree */
      $p_red   = $this->slotRed->getParticipant();
      $p_white = $this->slotWhite->getParticipant();

      if( !isset($p_red))   throw new \DomainException("cannot assign match record: no valid red participant");
      if( !isset($p_white)) throw new \DomainException("cannot assign match record: no valid white participant");

      if ($p_red->id !== $matchRecord->redParticipant->id)
      {
         throw new \DomainException("inconsistent match record: red participant does not match");
      }
      if (($p_white->id !== $matchRecord->whiteParticipant->id))
      {
         throw new \DomainException("inconsistent match record: white participant does not match");
      }

      $this->matchRecord = $matchRecord;
      $this->area = $matchRecord->area;
      $this->tie_break = $matchRecord->tie_break;
   }

   public function provideMatchRecord(Category $category): MatchRecord
   {
      $this->matchRecord ??= new MatchRecord(
         id: null,
         name: $this->name,
         category: $category,
         area: $this->area,
         tie_break: $this->tie_break,
         redParticipant: $this->getRedParticipant(),
         whiteParticipant: $this->getWhiteParticipant(),
      );
      return $this->matchRecord;
   }

   public function getMatchRecord(): ?MatchRecord
   {
      return $this->matchRecord;
   }

   public function tiesAllowed(): bool
   {
      return !$this->tie_break;
   }

   /* completely empty node match, no participants, ever */
   public function isObsolete(): bool
   {
      return $this->slotRed->isBye() && $this->slotWhite->isBye();
   }

   /* BYE match - only one participant there */
   public function isBye(): bool
   {
      return $this->slotRed->isBye() !== $this->slotWhite->isBye();
   }

   /* Match is a real match, and not just a dummy node that will never be conducted */
   public function isReal(): bool
   {
      return !$this->slotRed->isBye() && !$this->slotWhite->isBye();
   }

   /* Participants of this match are known */
   public function isDetermined()
   {
      return $this->slotRed->getParticipant() && $this->slotWhite->getParticipant();
   }

   /* Participants are established, but not started, yet */
   public function isPending(): bool
   {
      return $this->isDetermined() && !$this->matchRecord;
   }

   /* Match is actually spawned, regardless of result */
   public function isEstablished(): bool
   {
      return isset($this->matchRecord);
   }

   /* Match is ongoing, but no winner, yet */
   public function isOngoing(): bool
   {
      return $this->matchRecord && !isset($this->matchRecord->finalized_at);
   }

   /* There was an actual match, and that one is decided */
   public function isCompleted(): bool
   {
      return $this->matchRecord && isset($this->matchRecord->finalized_at);
   }

   public function isTieBreak(): bool
   {
      return $this->tie_break;
   }

   /* "Winner" of this match is known, regardless whether there was an actual match or not */
   public function isDecided(): bool
   {
      return $this->getWinner() !== null;
   }

   public function isTied(): bool
   {
      return $this->isCompleted() && !isset($this->matchRecord->winner);
   }

   /* Match result may not be modified anymore */
   public function isResultFixed(): bool
   {
      return false; // for a plain match node, currently no condition
   }

   /* Match results may be modified - if we have determined the participants, and it is not fixed, yet */
   public function isModifiable(): bool
   {
      return $this->isDetermined() && !$this->isResultFixed();
   }

   /**
    * return participants - matchRecord has precedence
    */
   public function getRedParticipant(): ?Participant
   {
      if ($this->matchRecord) return $this->matchRecord->redParticipant;
      return $this->slotRed->getParticipant();
   }

   /**
    * return participants - matchRecord has precedence
    */
   public function getWhiteParticipant(): ?Participant
   {
      if ($this->matchRecord) return $this->matchRecord->whiteParticipant;
      return $this->slotWhite->getParticipant();
   }


   /**
    * get the winner of this match, or null if not decided, yet
    * @return Participant|null
    */
   public function getWinner(): ?Participant
   {
      if ($this->matchRecord)        return $this->matchRecord->winner;
      if ($this->slotRed->isBye())   return $this->slotWhite->getParticipant();
      if ($this->slotWhite->isBye()) return $this->slotRed->getParticipant();
      return null;
   }

   /**
    * get the defeated participant of this match, or null if not decided, yet
    * @return Participant|null
    */
   public function getDefeated(): ?Participant
   {
      if ($this->matchRecord && $this->matchRecord->winner)
      {
         return $this->matchRecord->winner === $this->matchRecord->redParticipant
            ? $this->matchRecord->whiteParticipant
            : $this->matchRecord->redParticipant;
      }
      return null;
   }
}
