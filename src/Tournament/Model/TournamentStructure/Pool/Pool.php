<?php

namespace Tournament\Model\TournamentStructure\Pool;

use LogicException;
use RuntimeException;
use Tournament\Model\TournamentStructure\MatchSlot\ParticipantSlot;
use Tournament\Model\TournamentStructure\MatchNode\MatchNode;
use Tournament\Model\Participant\Participant;
use Tournament\Model\Area\Area;
use Tournament\Model\MatchPairingHandler\MatchPairingHandler;
use Tournament\Model\MatchRecord\MatchRecord;
use Tournament\Model\MatchRecord\MatchRecordCollection;
use Tournament\Model\Participant\ParticipantCollection;
use Tournament\Model\PoolRankHandler\PoolRank;
use Tournament\Model\PoolRankHandler\PoolRankCollection;
use Tournament\Model\PoolRankHandler\PoolRankHandler;
use Tournament\Model\TournamentStructure\MatchNode\MatchNodeCollection;
use Tournament\Model\TournamentStructure\TournamentStructureFactory;

class Pool
{
   private MatchNodeCollection $matches;
   private ParticipantCollection $participants;
   private PoolRankCollection $ranking;

   public function __construct(
      private string $name,
      private PoolRankHandler $rankHandler,
      private TournamentStructureFactory $nodeFactory,
      private MatchPairingHandler $pairingHandler,
      private int $num_winners = 2,
      private ?Area $area = null,
   )
   {
      $this->matches = MatchNodeCollection::new();
      $this->participants = ParticipantCollection::new();
   }

   /**
    * return a unique name for this pool, derived from the chunk id.
    */
   public function getName(): string
   {
      return $this->name;
   }

   /**
    * set the name of the pool
    */
   public function setName(string $name): void
   {
      $this->name = $name;

      $local_match_idx = 0;
      /** @var MatchNode $node */
      foreach ($this->matches as $node)
      {
         $node->name = $this->nameFor($local_match_idx++);
      }
   }

   public function getArea(): ?Area
   {
      return $this->area;
   }

   public function setArea(?Area $area): void
   {
      $this->area = $area;
      /** @var MatchNode $node */
      foreach ($this->matches as $node)
      {
         $node->area = $area;
      }
   }

   /**
    * recursively collect all participants in this match tree
    * @return ParticipantCollection of Participant objects
    */
   public function getParticipants(): ParticipantCollection
   {
      return $this->participants;
   }

   public function setParticipants(ParticipantCollection $p): void
   {
      $this->participants = $p;
      $this->matches = MatchNodeCollection::new();
      $this->addNewMatchesFor($p);
   }

   public function getMatchList(): MatchNodeCollection
   {
      return $this->matches;
   }

   /**
    * get the current ranking (via lazy calculation)
    */
   public function getRanking(): PoolRankCollection
   {
      return $this->ranking ??= $this->rankHandler->deriveRanking($this->matches);
   }

   /**
    * get the participant of rank $rank
    * do not return a result until this pool was fully conducted and decided
    * to not confuse with invalid intermediate results
    * if intermediate results are needed, use getRanking()
    */
   public function getRanked(int $rank): ?Participant
   {
      if( !$this->isDecided() ) return null;
      $ranked = $this->getRanking()->filter(fn($r) => $r->rank === $rank);
      return $ranked->count() === 1? $ranked->front()->participant : null;
   }

   /**
    * check whether all assigned matches are done
    */
   public function isConducted(): bool
   {
      return $this->matches->all(fn($m, $k) => $m->isCompleted() );
   }

   /**
    * check whether the relevant ranks of this pool are decided
    * it is decided if, and only if
    * - all matches are completed
    * - we have exactly $num_winners winners
    */
   public function isDecided(): bool
   {
      if( !$this->isConducted() ) return false;
      return $this->num_winners === $this->getRanking()->filter(fn($r) => $r->rank <= $this->num_winners)->count();
   }

   /**
    * check whether an additional tie break match is needed
    */
   public function needsTieBreakMatch(): bool
   {
      if (!$this->isConducted()) return false;
      return $this->num_winners !== $this->getRanking()->filter(fn($r) => $r->rank <= $this->num_winners)->count();
   }

   /**
    * add a tie break match
    */
   public function addDecisionMatches(): MatchNodeCollection
   {
      if( !$this->needsTieBreakMatch() ) throw new RuntimeException("no tie break matches needed right now, refusing");

      /** @var ParticipantCollection[] $per_rank - all participants sorted by rank into collections */
      $per_rank = [];
      /** @var PoolRank $rank_entry */
      foreach( $this->getRanking() as $rank_entry )
      {
         $per_rank[$rank_entry->rank] ??= ParticipantCollection::new();
         $per_rank[$rank_entry->rank][] = $rank_entry->participant;
      }

      /* find which rank currently has more than one participant */
      $col = array_find($per_rank, fn($c) => $c->count() > 1 );

      /* because of the above "needsTieBreakMatch" check, we should always have identified a list here
       * if due to some implementation bug this is not the case, just throw an exception. */
      if( !$col ) throw new LogicException("could not identifiy any needed matches...");

      return $this->addNewMatchesFor($col);
   }


   /**
    * generate the matches in this Pool.
    */
   private function addNewMatchesFor(ParticipantCollection $p): MatchNodeCollection
   {
      $report  = $this->pairingHandler->generate($p, $this->nodeFactory);
      $matchId = $this->matches->count();
      foreach( $report as $match )
      {
         $match->name = $this->nameFor($matchId++);
         $match->area = $this->area;
         $this->matches[] = $match;
      }
      return $report;
   }

   /**
    * Assign match records to the matches in this Pool structure.
    */
   public function setMatchRecords(MatchRecordCollection $matchRecords): void
   {
      foreach ($this->matches as $match)
      {
         if ($matchRecords->keyExists($match->name))
         {
            $match->setMatchRecord($matchRecords[$match->name]);
         }
      }

      /* check if there are further records for this pool for additional matches (-> decision matches) */
      $matchId = $this->matches->count()-1;
      while ($matchRecords->keyExists($matchName = $this->nameFor(++$matchId)))
      {
         /** @var MatchRecord $record - fetch this additional record from the provided ones */
         $record = $matchRecords[$matchName];

         $p_red   = $record->redParticipant;
         $p_white = $record->whiteParticipant;
         if( $this->participants->contains($p_red) && $this->participants->contains($p_white) )
         {
            $red     = new ParticipantSlot($p_red);
            $white   = new ParticipantSlot($p_white);
            $newNode = $this->nodeFactory->createMatchNode($matchName, $red, $white, $this->area);
            $newNode->setMatchRecord($record);
            $this->matches[] = $newNode;
         }
         else
         {
            throw new \DomainException("Invalid Match record for Pool " . $this->name . ": participants do not match");
         }
      }
   }

   /**
    * freeze all results, to not allow any further modifications of points or winners
    */
   public function freezeResults()
   {
      foreach ($this->matches as $match)
      {
         $match->frozen = true;
      }
   }

   /**
    * match name constructor
    */
   private function nameFor(int $matchId): string
   {
      return $this->name . "." . ($matchId+1);
   }
}
