<?php

namespace Tournament\Model\PoolRankHandler;

use Tournament\Model\MatchPointHandler\MatchPointHandler;
use Tournament\Model\TournamentStructure\MatchNode\MatchNodeCollection;

class GenericPoolRankHandler implements PoolRankHandler
{
   public function __construct(private MatchPointHandler $mpHdl)
   {
   }

   public function deriveRanking(MatchNodeCollection $matches): PoolRankCollection
   {
      /* iterate over all matches to collect wins and points */
      $ranks = [];
      /** @var \Tournament\Model\TournamentStructure\MatchNode\MatchNode $match */
      foreach ($matches as $match)
      {
         /* make sure all participants are part of $ranks */
         $redP = $match->getRedParticipant();
         $whiteP = $match->getWhiteParticipant();
         $ranks[$redP->id]   ??= new PoolRank($redP, 0, 0, 0);
         $ranks[$whiteP->id] ??= new PoolRank($whiteP, 0, 0, 0);

         /* do not evaluate any points if this match isn't done yet */
         if (!$match->isCompleted()) continue;

         /* increase the win counter for the corresponding participant */
         $ranks[$match->getWinner()->id]->wins += 1;

         /* increase point counters */
         $points = $this->mpHdl->getPoints($match->getMatchRecord()->points);
         $ranks[$redP->id]->points += $points->for($redP)->count();
         $ranks[$whiteP->id]->points += $points->for($whiteP)->count();
      }

      /* now sort into ranks according the comparision rules */
      usort($ranks, [static::class, 'rank_order']);

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
      return PoolRankCollection::new($ranks);
   }

   /* sorting callback the allows to sort an array of PoolRank entries
    * according the applied rules for ranking.
    * sort descending according relevant KPIs, so first rank will be first
    */
   static protected function rank_order(PoolRank $a, PoolRank $b): int
   {
      return  ($b->wins <=> $a->wins)
           ?: ($b->points <=> $a->points);
   }
}