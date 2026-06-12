<?php

namespace Tournament\Model\MatchRankHandler;

use Tournament\Model\MatchPointHandler\MatchPointHandler;
use Tournament\Model\MatchRecord\SoloMatchRecord;
use Tournament\Model\MatchRecord\TeamMatchRecord;
use Tournament\Model\TournamentStructure\MatchNode\MatchSide;
use Tournament\Model\TournamentStructure\MatchNode\SoloMatch;
use Tournament\Model\TournamentStructure\Pool\Pool;

/**
 * Default implementation of MatchRankHandler.
 * This handler allows to derive a ranking of MatchParticipants
 * based on a list of matches that have occured between them.
 */
class GenericMatchRankHandler implements MatchRankHandler
{
   /* short-cut MatchSide strings to use them as array keys */
   private const RED = MatchSide::RED->value;
   private const WHITE = MatchSide::WHITE->value;

   public function __construct(private MatchPointHandler $mpHdl)
   {
   }

   /**
    * derive the current ranking of pool participants based on the pool matches
    */
   public function derivePoolRanking(Pool $pool): MatchRankCollection
   {
      $ranks = [];

      /* iterate over all matches to collect wins and points */
      foreach ($pool->getMatchList() as $match)
      {
         if (!($match instanceof SoloMatch)) throw new \LogicException("pool ranking currently only supports solo matches");

         /* fetch both participants */
         $redP = $match->getRedParticipant();
         $whiteP = $match->getWhiteParticipant();

         /* make sure there is a MatchRank entry for each participant */
         $ranks[$redP->getId()]   ??= new MatchRank($redP);
         $ranks[$whiteP->getId()] ??= new MatchRank($whiteP);

         /* skip unfinished matches */
         if ($match->getMatchRecord())
         {
            $this->collect_score($match->getMatchRecord(), $ranks[$redP->getId()], $ranks[$whiteP->getId()]);
         }
      }

      /* finalize and return */
      return self::finalize_ranking($ranks);
   }

   /**
    * Evaluate a team match record to derive the winning team
    */
   public function deriveTeamMatchResults(TeamMatchRecord $record): MatchRankCollection
   {
      $ranks = [
         self::RED   => new MatchRank($record->redTeam),
         self::WHITE => new MatchRank($record->whiteTeam),
      ];

      /** @var SoloMatchRecord $mr */
      foreach( $record->matches as $mr )
      {
         $this->collect_score($mr, $ranks[self::RED], $ranks[self::WHITE]);
      }

      /* turn into a properly ordered MatchRankCollection, and be done */
      return self::finalize_ranking($ranks);
   }

   /**
    * collect scoring from a SoloMatchRecord into provided red and white MatchRank
    */
   private function collect_score(SoloMatchRecord $mr, MatchRank $red, MatchRank $white): void
   {
      /* index MatchRanks by color */
      $ranks = [ self::RED => $red, self::WHITE => $white ];

      if ($mr->tie_break)
      {
         /* for tie break matches, only count wins and ignore points */
         if( $mr->winner ) $ranks[$mr->winner->value]->tie_breaks += 1;
      }
      else
      {
         if( $mr->isFinalized() )
         {
            if ($mr->winner)
            {
               $ranks[$mr->winner->value]->wins += 1;
            }
            else
            {
               $ranks[self::RED]->ties += 1;
               $ranks[self::WHITE]->ties += 1;
            }
         }

         /* increase point counters */
         $points = $this->mpHdl->getPoints($mr);
         $ranks[self::RED]->points += $points->for($mr->getParticipant(MatchSide::RED))->count();
         $ranks[self::WHITE]->points += $points->for($mr->getParticipant(MatchSide::WHITE))->count();
      }
   }

   /**
    * sort and rank-assign a prepared MatchRankCollection (all attributes set)
    * @param MatchRank[] $in
    */
   private static function finalize_ranking(array $in): MatchRankCollection
   {
      /* turn into a sorted MatchRankCollection */
      $ranks = MatchRankCollection::new($in)->usort(static::rank_order(...));

      /* assign rank values based on the derived order */
      $prev = null;
      $rank_nr = 1;
      foreach ($ranks as $rank_entry)
      {
         /* check if we have a ranking difference to the previous entry before incrementing the current rank */
         if (isset($prev) && (0 !== static::rank_order($prev, $rank_entry))) $rank_nr++;
         /* then assign the new rank */
         $rank_entry->rank = $rank_nr;
         /* and update prev */
         $prev = $rank_entry;
      }
      return $ranks;
   }

   /* sorting callback that allows to sort an array of MatchRank entries
    * according the applied rules for ranking.
    * sort descending according relevant KPIs, so first rank will be first
    */
   protected static function rank_order(MatchRank $a, MatchRank $b): int
   {
      return  ($b->wins <=> $a->wins)
           ?: ($b->ties <=> $a->ties)
           ?: ($b->points <=> $a->points)
           ?: ($b->tie_breaks <=> $a->tie_breaks);
   }
}