<?php

namespace Tournament\Model\TournamentStructure\Pool;

use Tournament\Model\TournamentStructure\MatchSlot\ParticipantSlot;
use Tournament\Model\TournamentStructure\MatchNode\MatchNode;
use Tournament\Model\Participant\Participant;
use Tournament\Model\Area\Area;
use Tournament\Model\MatchRecord\MatchRecord;
use Tournament\Model\MatchRecord\MatchRecordCollection;
use Tournament\Model\Participant\ParticipantCollection;
use Tournament\Model\PoolRankHandler\PoolRankCollection;
use Tournament\Model\PoolRankHandler\PoolRankHandler;
use Tournament\Model\TournamentStructure\MatchNode\MatchNodeCollection;

class Pool
{
   private MatchNodeCollection $matches;
   private ParticipantCollection $participants;
   private PoolRankCollection $ranking;

   public function __construct(
      private string $name,
      private PoolRankHandler $rankHandler,
      private int $num_winners = 2,
      private ?Area $area = null
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
      $this->generateMatches();
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
   public function addTieBreakMatch(): MatchNode
   {
      /* deduct the participants that will need a tie break - the first two participants with the same current rank */
      list($red, $white) = [null,null];
      foreach( $this->getRanking() as $rank_entry )
      {
         if( !isset($red) || $red->rank !== $rank_entry->rank )
         {
            $red = $rank_entry;
         }
         else
         {
            $white = $rank_entry;
            break;
         }
      }

      if( !isset($white) )
      {
         throw new \RuntimeException("no tie break participants could be identified.");
      }

      /* create a new MatchNode for them */
      $red     = new ParticipantSlot($red->participant);
      $white   = new ParticipantSlot($white->participant);
      $matchId = $this->matches->count();
      $node = new MatchNode($this->nameFor($matchId), $red, $white, $this->area, true);
      $this->matches[] = $node;
      return $node;
   }


   /**
    * generate the matches in this Pool.
    */
   private function generateMatches(): void
   {
      /* https://de.wikipedia.org/wiki/Jeder-gegen-jeden-Turnier#Rutschsystem */
      $this->matches = MatchNodeCollection::new();

      $players = $this->participants->values();
      if( count($players) % 2 ) $players[] = null; // fill up to an even number of participants with one BYE slot if needed

      $numPlayers = count($players);
      $rounds = $numPlayers - 1;

      for ($r = 0; $r < $rounds; $r++)
      {
         // generate matches for each participant
         for ($i = 0; $i < $numPlayers / 2; $i++)
         {
            $p_red   = $players[$i];
            $p_white = $players[$numPlayers - 1 - $i];
            if ($p_red && $p_white) // no BYE
            {
               $red     = new ParticipantSlot($p_red);
               $white   = new ParticipantSlot($p_white);
               $matchId = $this->matches->count();
               $this->matches[] = new MatchNode($this->nameFor($matchId), $red, $white, $this->area);
            }
         }

         // rotate participant pool
         $players = array_merge(
            [$players[0]],                // fix position of first participant
            [$players[$numPlayers-1]],    // move last participant to first position
            array_slice($players,  1, -1) // keep rest of the list
         );
      }
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
            $newNode = new MatchNode($matchName, $red, $white, $this->area);
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
    * match name constructor
    */
   private function nameFor(int $matchId): string
   {
      return $this->name . "." . $matchId;
   }
}
