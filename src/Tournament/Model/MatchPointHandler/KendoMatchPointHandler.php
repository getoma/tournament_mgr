<?php

namespace Tournament\Model\MatchPointHandler;

use Tournament\Model\MatchRecord\MatchRecord;
use Tournament\Model\MatchRecord\MatchPoint;
use Tournament\Model\MatchRecord\MatchPointCollection;
use Tournament\Model\Participant\Participant;

final class KendoMatchPointHandler implements MatchPointHandler
{
   private const POINTS = [
      'Men'     => 'M',
      'Kote'    => 'K',
      'Do'      => 'D',
      'Tsuki'   => 'T',
      'Ippon'   => 'I',
   ];

   private const PENALTY = [
      'Hansoku' => 'H',
   ];

   /* different representations of above constants for more efficient evaluations */
   static private $_ACCEPTED = null;
   static private $_POINTS = null;

   /**
    * Set up and configure a Kendo MatchPointHandler
    * @param max_points - after how many points for one participant the match is decided (default 2)
    * @param hansokus_for_ippon - how many hansoku will imply an ippon for the opponent? (0=disabled)
    */
   public function __construct(private int $max_points = 2, private int $hansokus_for_ippon = 2 )
   {
      if( !isset(self::$_ACCEPTED) )
      {
         self::$_ACCEPTED = array_flip(self::POINTS + self::PENALTY);
         self::$_POINTS = array_flip(self::POINTS);
      }
   }

   /**
    * Try to register any match point event to a single match
    * Check if this point can be added according the registered rules and add it.
    * Also add any follow up points that might result from it
    * (e.g. points for the opponent on any penalty)
    *
    * @param MatchRecord $match - the match to add points to
    * @param MatchPoint $pt - the point to add
    * @return true if this point was applied, false if it is invalid and ignored
    */
   public function addPoint(MatchRecord $match, MatchPoint $pt): bool
   {
      /* validity checks */
      if( $this->isDecided($match) ) return false;
      if( !isset(self::$_ACCEPTED[$pt->point]) ) return false;

      /* add the point */
      $match->points[] = $pt;

      /* apply special rules - ippon for hansokus */
      if ($this->hansokus_for_ippon && $pt->point === self::PENALTY['Hansoku'])
      {
         $hansokus = $match->points->filter(fn(MatchPoint $p) => $p->point === self::PENALTY['Hansoku'])->for($pt->participant);
         if( 0 === ($hansokus->count() % $this->hansokus_for_ippon) )
         {
            /* enough hansokus for an ippon, add it for the other participant */
            $match->points[] = new MatchPoint(
               id: null,
               participant: $match->getOpponent($pt->participant),
               point: self::POINTS['Ippon'],
               given_at: $pt->given_at,
               caused_by: $pt
            );
         }
      }

      return true;
   }

   /**
    * Remove point, and do any needed other rollback action
    */
   function removePoint(MatchRecord $match, MatchPoint|int $pt): bool
   {
      $pt_id = ($pt instanceof MatchPoint)? $pt->id : (int)$pt;
      /* removing of follow-up points is implemented within
       * MatchPointCollection already, which is evaluating the
       * caused_by-relation for that
       * so, at this place we can simply drop it from the points list
       * and be done with it.
       */
      $match->points->offsetUnset($pt_id);
      return true;
   }

   /**
    * return the winner according current points, if any
    * @param MatchRecord $match - the match to check
    * @return null if no winner can be decided
    * @return Participant winner according points if the match was over
    */
   public function getWinner(MatchRecord $match): ?Participant
   {
      /* reduce the match point list to actual points */
      $fullPts = $match->points->filter(fn(MatchPoint $pt) => isset(self::$_POINTS[$pt->point]));

      /* get the points for the red participant */
      $redPts = $fullPts->for($match->redParticipant);

      /* check if red has enough points */
      if( $redPts->count() >= $this->max_points ) return $match->redParticipant;
      /* otherwise, check if white has enough points */
      if( ($fullPts->count() - $redPts->count()) >= $this->max_points ) return $match->whiteParticipant;
      /* no winner, yet */
      return null;
   }

   /**
    * return whether the match is finished according current point
    * distribution
    * @param MatchRecord $match - the match to check
    * @return true if the match is decided according given points
    */
   public function isDecided(MatchRecord $match): bool
   {
      return $this->getWinner($match) !== null;
   }

   /**
    * return a list of all point representations (valid contents of MatchPoint->point)
    * @return string[]
    */
   function getPointList(): array
   {
      return self::POINTS + self::PENALTY;
   }

   /**
    * extract active penalties from a given MatchPointCollection
    * returns a list of active penalties that did not yet result
    * in any further consequences according the specific rules applied
    */
   public function getActivePenalties(MatchPointCollection $col): MatchPointCollection
   {
      $penalty_list = [];

      /**
       * Go through the list, extract all Hansokus for each participant,
       * if the number of found hansokus reaches the number of hansokus
       * needed for an ippon clear the list again.
       * --> result will be list of all hansokus that did not result in an ippon, yet
       * (so, in normal rules, the resulting list will contain one or no hansoku)
       */

      /** @var MatchPoint $p */
      foreach( $col as $p )
      {
         if( $p->point === self::PENALTY['Hansoku'] )
         {
            $penalty_list[$p->participant->id] ??= [];
            $penalty_list[$p->participant->id][] = $p;

            /* by comparing on equality, this check will never be true if
             * hansoku-caused ippons are disabled by setting this configuration to zero
             */
            if( $this->hansokus_for_ippon === count($penalty_list[$p->participant->id]) )
            {
               $penalty_list[$p->participant->id] = [];
            }
         }
      }

      /* done, now merge and return the collection of hansokus */
      return MatchPointCollection::new(array_merge(...$penalty_list));
   }

   /**
    * extract real, actual points
    */
   public function getPoints(MatchPointCollection $col): MatchPointCollection
   {
      return $col->filter(fn(MatchPoint $p) => isset(self::$_POINTS[$p->point]));
   }

}