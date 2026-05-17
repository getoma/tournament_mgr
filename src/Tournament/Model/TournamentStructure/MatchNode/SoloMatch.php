<?php declare(strict_types=1);

namespace Tournament\Model\TournamentStructure\MatchNode;

use Tournament\Model\MatchRecord\MatchPoint;
use Tournament\Model\MatchRecord\MatchPointCollection;
use Tournament\Model\MatchRecord\MatchRecord;
use Tournament\Model\MatchRecord\SoloMatchRecord;

/**
 * class of an atomar single match between two persons, which might be part of a KO tree, a pool, or whatever
 * adds handling of actual match points to the MatchNode base.
 */
class SoloMatch extends MatchNodeBase
{
   protected ?SoloMatchRecord $matchRecord = null;

   public function isComposite(): bool
   {
      return false;
   }

   /* return submatches for composite nodes */
   public function getSubMatches(): ?MatchNodeCollection
   {
      return null;
   }

   /**
    * set the match record associated with this match node
    * verify that the match record is consistent with this node
    * @param SoloMatchRecord|null $matchRecord
    */
   public function setMatchRecord(MatchRecord $matchRecord): void
   {
      if( !$matchRecord instanceof SoloMatchRecord )
      {
         throw new \DomainException('SoloMatchRecord expected!');
      }

      if( !$this->isReal() )
      {
         throw new \LogicException("attempt to assign a match record to non-real match: " . $this->getName());
      }

      if( $matchRecord->name !== $this->getName() )
      {
         throw new \OutOfRangeException("inconsistent match record: name does not match: " . $this->getName());
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
         throw new \OutOfRangeException("inconsistent match record: participants do not match: $rid vs $rid2 | $ridw vs $ridw2" . $this->getName());
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
   public function getMatchRecord(): ?SoloMatchRecord
   {
      return $this->matchRecord;
   }

   /**
    * provide the match record for this node.
    * if none available yet, initialize it.
    */
   public function provideMatchRecord(): SoloMatchRecord
   {
      $this->matchRecord ??= new SoloMatchRecord(
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
