<?php

namespace Tournament\Model\TournamentStructure\MatchNode;

use Tournament\Model\Participant\Participant;
use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\MatchRecord\MatchRecord;
use Tournament\Model\MatchPointHandler\MatchPointHandler;
use Tournament\Model\TournamentStructure\MatchSlot\MatchSlot;

/**
 * base class to manage an atomar match, which might be part of a KO tree, a pool, or whatever
 * only provides methods to query/handle its own state, and doesn't care about what exactly
 * is behind its input slots.
 */
class MatchNode
{
   private string $name;

   public function __construct(
      string $node_name,
      public readonly MatchSlot $slotRed,    // slot contents may be modified, but the slot itself is fixed
      public readonly MatchSlot $slotWhite,  // slot contents may be modified, but the slot itself is fixed
      protected readonly MatchPointHandler $mpHdl, // MatchPoint Handler to parse match points
      public  ?Area $area = null,
      private bool $tie_break = false,
      private ?MatchRecord $matchRecord = null,
      public bool $frozen = false            // whether match record data is frozen for this node or not
   )
   {
      if( $this->slotRed === $this->slotWhite )
      {
         throw new \DomainException("invalid match: red and white slot must be different");
      }

      $this->setName($node_name);
      $this->setMatchRecord($matchRecord);
   }

   public function setName(string $name): void
   {
      $this->name = $name;
   }

   public function getName(): string
   {
      return $this->name;
   }

   /**
    * extract the "local fight number" from the name
    * This is supposed to be the number at the end of the name
    */
   public function getLocalId(): ?int
   {
      return preg_match('/\d+$/', $this->name, $matches)? $matches[0] : null;
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
      if ($p_white->id !== $matchRecord->whiteParticipant->id)
      {
         throw new \DomainException("inconsistent match record: white participant does not match");
      }

      /* update this note with those inputs */
      $this->matchRecord = $matchRecord;
      $this->area = $matchRecord->area;
      $this->tie_break = $matchRecord->tie_break;

      /* freeze the previous nodes */
      $this->slotRed->freezeResult();
      $this->slotWhite->freezeResult();
   }

   /**
    * provide the match record for this node if existing.
    */
   public function getMatchRecord(): ?MatchRecord
   {
      return $this->matchRecord;
   }

   /**
    * provide the match record for this node.
    * if none available yet, initialize it.
    */
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

   /* whether a tie result is allowed */
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

   /* Match is ongoing */
   public function isOngoing(): bool
   {
      return $this->matchRecord && !isset($this->matchRecord->finalized_at);
   }

   /* There was an actual match, and that one is already finalized */
   public function isCompleted(): bool
   {
      return $this->matchRecord && isset($this->matchRecord->finalized_at);
   }

   /* whether this is a tie break match */
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

   /* Match points may not be modified anymore
    */
   public function isFrozen(): bool
   {
      return $this->frozen;
   }

   /* Match data may be modified - if we have determined the participants, and it is not frozen, yet */
   public function isModifiable(): bool
   {
      return $this->isDetermined() && !$this->isFrozen();
   }

   /**
    * return participants - matchRecord has precedence
    */
   public function getRedParticipant(): ?Participant
   {
      return $this->matchRecord?->redParticipant ?: $this->slotRed->getParticipant();
   }

   /**
    * return participants - matchRecord has precedence
    */
   public function getWhiteParticipant(): ?Participant
   {
      return $this->matchRecord?->whiteParticipant ?: $this->slotWhite->getParticipant();
   }

   /**
    * get number of points for the red participant
    * @return null if match not started, yet
    * @return int  number of points this participant has if match was started already
    */
   public function getRedPoints(): ?int
   {
      if( !$this->matchRecord ) return null;
      return $this->mpHdl->getPoints($this->matchRecord)->for($this->matchRecord->redParticipant)->count();
   }

   /**
    * get number of points for the white participant
    * @return null if match not started, yet
    * @return int  number of points this participant has if match was started already
    */
   public function getWhitePoints(): ?int
   {
      if (!$this->matchRecord) return null;
      return $this->mpHdl->getPoints($this->matchRecord)->for($this->matchRecord->whiteParticipant)->count();
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
      return  $this->matchRecord?->winner? $this->matchRecord->getOpponent($this->matchRecord->winner) : null;
   }
}
