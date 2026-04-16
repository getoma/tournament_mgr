<?php declare(strict_types=1);

namespace Tournament\Model\TournamentStructure\MatchNode;

use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\MatchRecord\MatchPoint;
use Tournament\Model\MatchRecord\MatchPointCollection;
use Tournament\Model\MatchRecord\MatchRecord;
use Tournament\Model\TournamentStructure\MatchParticipant\MatchParticipant;
use Tournament\Model\TournamentStructure\MatchSlot\MatchSlot;

/**
 * class of an atomar single match between two persons, which might be part of a KO tree, a pool, or whatever
 * adds handling of actual match points to the MatchNode base.
 */
class SoloMatch extends MatchNodeBase
{
   public function __construct(
      string $node_name,
      Category  $category,      // the category this node belongs to
      MatchSlot $slotRed,       // slot contents may be modified, but the slot itself is fixed
      MatchSlot $slotWhite,     // slot contents may be modified, but the slot itself is fixed
      ?Area $area = null,
      bool $frozen = false,         // whether match record data is frozen for this node or not
      bool $tieBreak = false,       // whether this match is a tie break
      bool $tiesAllowed = true,     // whether a tied result is allowed
      private ?MatchRecord $matchRecord = null,
   )
   {
      parent::__construct($node_name, $category, $slotRed, $slotWhite, $area, _frozen: $frozen, _tieBreak: $tieBreak, _tiesAllowed: $tiesAllowed);
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
         throw new \LogicException("attempt to assign a match record to non-real match: " . $this->getName());
      }

      if( $matchRecord->name !== $this->getName() )
      {
         throw new \DomainException("inconsistent match record: name does not match: " . $this->getName());
      }

      /* get the slots and the assigned participants according tree model */
      list($redSlot, $whiteSlot) = [$this->getRedSlot(), $this->getWhiteSlot()];
      list($p_red, $p_white) = [$redSlot->getParticipant(), $whiteSlot->getParticipant()];

      /* verify that match record and tree model are consistent */
      if ($p_red?->id !== $matchRecord->redParticipant->id || $p_white?->id !== $matchRecord->whiteParticipant->id)
      {
         $rid = $p_red?->id ?? 0;
         $rid2 = $matchRecord->redParticipant->id;
         $ridw = $p_white?->id ?? 0;
         $ridw2 = $matchRecord->whiteParticipant->id;
         throw new \DomainException("inconsistent match record: participants do not match: $rid vs $rid2 | $ridw vs $ridw2" . $this->getName());
      }

      /* take over all relevant data from the match record */
      $this->matchRecord = $matchRecord;
      $this->setArea($matchRecord->area);
      if( $matchRecord->tie_break ) $this->makeTieBreak();

      /* freeze the previous nodes */
      $redSlot->freezeResult();
      $whiteSlot->freezeResult();
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
   public function provideMatchRecord(): MatchRecord
   {
      $this->matchRecord ??= new MatchRecord(
         id: null,
         name: $this->getName(),
         category: $this->category,
         area: $this->getArea(),
         tie_break: $this->isTieBreak(),
         redParticipant: $this->getRedParticipant(),
         whiteParticipant: $this->getWhiteParticipant(),
      );
      return $this->matchRecord;
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

   /* whether match ended with a tie */
   public function isTied(): bool
   {
      return $this->isCompleted() && !$this->matchRecord->getWinner();
   }

   /* return participant per parameter - take from match record if available */
   public function getParticipant(MatchSide|string $side): ?MatchParticipant
   {
      if (is_string($side)) $side = MatchSide::from($side);
      return match ($side)
      {
         MatchSide::RED   => $this->matchRecord?->redParticipant   ?: $this->getRedSlot()->getParticipant(),
         MatchSide::WHITE => $this->matchRecord?->whiteParticipant ?: $this->getWhiteSlot()->getParticipant(),
         default => throw new \OutOfRangeException("invalid match side '$side'")
      };
   }

   /**
    * get the winner of this match, or null if not decided, yet
    * @return Participant|null
    */
   public function getWinner(): ?MatchParticipant
   {
      if ($this->matchRecord)  return $this->matchRecord->getWinner();
      list($redSlot, $whiteSlot) = [$this->getRedSlot(), $this->getWhiteSlot()];
      if ($redSlot->isBye())   return $this->getWhiteSlot()->getParticipant();
      if ($whiteSlot->isBye()) return $this->getRedSlot()->getParticipant();
      return null;
   }

   /**
    * get the defeated participant of this match, or null if not decided, yet
    * @return Participant|null
    */
   public function getDefeated(): ?MatchParticipant
   {
      return $this->matchRecord?->getDefeated();
   }

   /**
    * get list of points for the red participant
    * @return null if match not started, yet
    * @return MatchPointCollection points this participant has if match was started already
    */
   public function getRedPoints(): ?MatchPointCollection
   {
      if( !$this->matchRecord ) return null;
      return $this->category->getMatchPointHandler()->getPoints($this->matchRecord)->for($this->matchRecord->redParticipant);
   }

   /**
    * get list of points for the white participant
    * @return null if match not started, yet
    * @return MatchPointCollection points this participant has if match was started already
    */
   public function getWhitePoints(): ?MatchPointCollection
   {
      if (!$this->matchRecord) return null;
      return $this->category->getMatchPointHandler()->getPoints($this->matchRecord)->for($this->matchRecord->whiteParticipant);
   }

   /**
    * get list of points for a participant identified by parameter
    * @return null if match not started, yet
    * @return MatchPointCollection points this participant has if match was started already
    */
   public function getPoints(MatchSide|string $side): ?MatchPointCollection
   {
      if (is_string($side)) $side = MatchSide::from($side);
      return match ($side)
      {
         MatchSide::RED   => $this->getRedPoints(),
         MatchSide::WHITE => $this->getWhitePoints(),
         default => throw new \OutOfRangeException("invalid match side '$side'")
      };
   }

   /**
    * get list of currently active penalties for the red participant
    * @return null if match not started, yet
    * @return MatchPointCollection active penalties this participant has if match was started already
    */
   public function getRedPenalties(): ?MatchPointCollection
   {
      if (!$this->matchRecord) return null;
      return $this->category->getMatchPointHandler()->getActivePenalties($this->matchRecord)->for($this->matchRecord->redParticipant);
   }

   /**
    * get list of currently active penalties for the white participant
    * @return null if match not started, yet
    * @return MatchPointCollection active penalties this participant has if match was started already
    */
   public function getWhitePenalties(): ?MatchPointCollection
   {
      if (!$this->matchRecord) return null;
      return $this->category->getMatchPointHandler()->getActivePenalties($this->matchRecord)->for($this->matchRecord->whiteParticipant);
   }

   /**
    * get list of currently active penalties for a participant identified by parameter
    * @return null if match not started, yet
    * @return MatchPointCollection active penalties this participant has if match was started already
    */
   public function getPenalties(MatchSide|string $side): ?MatchPointCollection
   {
      if (is_string($side)) $side = MatchSide::from($side);
      return match ($side)
      {
         MatchSide::RED   => $this->getRedPenalties(),
         MatchSide::WHITE => $this->getWhitePenalties(),
         default => throw new \OutOfRangeException("invalid match side '$side'")
      };
   }

   /**
    * get the most current point or penalty of the red participant (e.g. for undo selection)
    * @return null if no such point set, yet
    * @return MatchPoint - the last point set for the red participant
    */
   public function getLastRedPoint(): ?MatchPoint
   {
      return $this->matchRecord?->points->for($this->matchRecord->redParticipant)->filter(fn($p) => $p->isSolitary())->last();
   }

   /**
    * get the most current point or penalty of the white participant (e.g. for undo selection)
    * @return null if no such point set, yet
    * @return MatchPoint - the last point set for the white participant
    */
   public function getLastWhitePoint(): ?MatchPoint
   {
      return $this->matchRecord?->points->for($this->matchRecord->whiteParticipant)->filter(fn($p) => $p->isSolitary())->last();
   }
   /**
    * get the most current point or penalty of a participant identified by parameter (e.g. for undo selection)
    * @return null if no such point set, yet
    * @return MatchPoint - the last point set for the participant
    */
   public function getLastPoint(MatchSide|string $side): ?MatchPoint
   {
      if (is_string($side)) $side = MatchSide::from($side);
      return match ($side)
      {
         MatchSide::RED   => $this->getLastRedPoint(),
         MatchSide::WHITE => $this->getLastWhitePoint(),
         default => throw new \OutOfRangeException("invalid match side '$side'")
      };
   }

   /**
    * get the list of possible points that can be set
    */
   public function getPossiblePoints(): array
   {
      return $this->category->getMatchPointHandler()->getPointList();
   }
}
