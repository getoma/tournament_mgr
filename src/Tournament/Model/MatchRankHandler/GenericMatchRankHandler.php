<?php

namespace Tournament\Model\MatchRankHandler;

use Tournament\Model\MatchPointHandler\MatchPointHandler;
use Tournament\Model\TournamentStructure\MatchNode\SoloMatch;
use Tournament\Model\TournamentStructure\Pool\Pool;

/**
 * Default implementation of MatchRankHandler.
 * This handler allows to derive a ranking of MatchParticipants
 * based on a list of matches that have occured between them.
 */
class GenericMatchRankHandler implements MatchRankHandler
{
   public function __construct(private MatchPointHandler $mpHdl)
   {
   }

   /**
    * derive the current ranking of pool participants based on the pool matches
    */
   public function derivePoolRanking(Pool $pool): MatchRankCollection
   {
      $ranks = MatchRankCollection::new();

      /* iterate over all matches to collect wins and points */
      foreach ($pool->getMatchList() as $match)
      {
         if (!($match instanceof SoloMatch)) throw new \LogicException("pool ranking currently only supports solo matches");

         /* fetch both participants */
         $redP = $match->getRedParticipant();
         $whiteP = $match->getWhiteParticipant();

         /* make sure all participants are part of $ranks */
         $ranks[$redP->getId()]   ??= new MatchRank($redP);
         $ranks[$whiteP->getId()] ??= new MatchRank($whiteP);

         /* do not evaluate any points if this match isn't done yet */
         if (!$match->isCompleted()) continue;

         /* increase the KPI counters for the corresponding participant */
         $match_record = $match->getMatchRecord();
         if( $match_record->tie_break )
         {
            // a tie break match always has a winner if it is completed
            $ranks[$match_record->getWinner()->id]->tie_breaks += 1;
         }
         else
         {
            if( $match_record->getWinner() )
            {
               $ranks[$match_record->getWinner()->id]->wins += 1;
            }
            else
            {
               $ranks[$redP->getId()]->ties += 1;
               $ranks[$whiteP->getId()]->ties += 1;
            }

            /* increase point counters, (only count points from non-tie-break matches) */
            $points = $this->mpHdl->getPoints($match_record);
            $ranks[$redP->getId()]->points += $points->for($redP)->count();
            $ranks[$whiteP->getId()]->points += $points->for($whiteP)->count();
         }
      }

      /* now sort into ranks according the comparision rules */
      $ranks = $ranks->usort(static::rank_order(...));

      /* iterate the sorted array and finally assign ranks */
      $prev = null;
      $rank_nr = 1;
      foreach( $ranks as $rank_entry )
      {
         /* check if we have a ranking difference to the previous entry before incrementing the current rank# */
         if (isset($prev) && (0 !== static::rank_order($prev, $rank_entry))) $rank_nr++;
         /* then assign the new rank */
         $rank_entry->rank = $rank_nr;
         /* and update prev */
         $prev = $rank_entry;
      }

      /* done, return the result */
      return $ranks;
   }

   /* sorting callback the allows to sort an array of MatchRank entries
    * according the applied rules for ranking.
    * sort descending according relevant KPIs, so first rank will be first
    */
   static protected function rank_order(MatchRank $a, MatchRank $b): int
   {
      return  ($b->wins <=> $a->wins)
           ?: ($b->ties <=> $a->ties)
           ?: ($b->points <=> $a->points)
           ?: ($b->tie_breaks <=> $a->tie_breaks);
   }
}