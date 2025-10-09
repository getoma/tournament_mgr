<?php

namespace Tournament\Model\MatchPointHandler;

use Tournament\Model\MatchRecord\MatchRecord;
use Tournament\Model\MatchRecord\MatchPoint;
use Tournament\Model\MatchRecord\MatchPointCollection;
use Tournament\Model\Participant\Participant;

interface MatchPointHandler
{
   /**
    * Try to register any match point event to a single match
    * Check if this point can be added according the registered rules and add it.
    * Also add any follow up points that might result from it
    * (e.g. points for the opponent on any penalty)
    *
    * @param MatchRecord $match - the match to add points to
    * @param MatchPoint $pt - the point to add
    * @return true if this point can be applied according the rules
    */
   function addPoint(MatchRecord $match, MatchPoint $pt): bool;

   /**
    * Remove point, and do any needed other rollback action
    * @param MatchRecord $match - the match to remove the point from
    * @param MatchPoint|int $pt - the point to remove (or its db id)
    * @return true if this point was removed from the match record
    */
   function removePoint(MatchRecord $match, MatchPoint|int $pt): bool;

   /**
    * return the winner according current points, if any
    * @param MatchRecord $match - the match to check
    * @return null if no winner can be decided
    * @return Participant winner according points if the match was over
    */
   function getWinner(MatchRecord $match): ?Participant;

   /**
    * return whether the match is finished according current point
    * distribution
    * @param MatchRecord $match - the match to check
    * @return true if the match is decided according given points
    */
   function isDecided(MatchRecord $match): bool;

   /**
    * return a list of all point representations (valid contents of MatchPoint->point)
    * @return string[]
    */
   function getPointList(): array;

   /**
    * extract active penalties from a given MatchPointCollection
    * returns a list of active penalties that did not yet result
    * in any further consequences according the specific rules applied
    */
   function getActivePenalties(MatchPointCollection $col): MatchPointCollection;

   /**
    * extract real, actual points
    */
   function getPoints(MatchPointCollection $col): MatchPointCollection;

}
