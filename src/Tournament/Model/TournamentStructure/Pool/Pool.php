<?php

namespace Tournament\Model\TournamentStructure\Pool;

use Tournament\Model\TournamentStructure\MatchSlot\ParticipantSlot;
use Tournament\Model\TournamentStructure\MatchNode\MatchNode;
use Tournament\Model\Participant\Participant;
use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\MatchRecord\MatchRecord;
use Tournament\Model\MatchRecord\MatchRecordCollection;
use Tournament\Model\Participant\ParticipantCollection;
use Tournament\Model\PoolRankHandler\PoolRank;
use Tournament\Model\PoolRankHandler\PoolRankCollection;
use Tournament\Model\TournamentStructure\MatchNode\MatchNodeCollection;

class Pool
{
   private MatchNodeCollection $matches;
   /** @var Participant[] */
   private array $slots = [];
   private PoolRankCollection $ranking;

   public const DEFAULT_NUM_WINNERS = 2;

   private int $current_extension = 0;

   public function __construct(
      private string $name,
      public  Category $category,
      private ?Area $area = null,
   )
   {
      $this->matches = MatchNodeCollection::new();
   }

   /**
    * return a unique name for this pool.
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
         $extId = $this->getExtensionId($node->getName());
         $node->setName($this->nameFor($local_match_idx++, $extId));
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
      return ParticipantCollection::new($this->slots);
   }

   public function loadParticipants(ParticipantCollection $participants): void
   {
      $this->slots = [];
      foreach( $participants as $p )
      {
         if( $slot = $p->categories[$this->category->id]?->slot_name )
         {
            list($poolId, $slotId) = static::splitSlotName($slot);
            if( $poolId !== $this->name ) throw new \UnexpectedValueException('participant is assigned to a different pool');
            $this->slots[$slotId] = $p;
         }
         else
         {
            throw new \UnexpectedValueException('participant is not assigned to a pool');
         }
      }

      /* store participants in slot order */
      ksort($this->slots);

      /* regenerate the matches */
      $this->recreateMatchList();
   }

   /**
    * add a new participant and return their slot
    */
   public function addParticipants(ParticipantCollection $participants): void
   {
      $slotId = 0;
      $current = $this->getParticipants();
      foreach( $participants as $p )
      {
         /* silently skip for already assigned participants */
         if ($current->contains($p)) continue;

         /* plausibility check */
         if( !$p->categories->keyExists($this->category->id) )
         {
            throw new \OutOfRangeException('participant not assigned to current category');
         }

         /* find a free slot and add them there */
         while( isset($this->slots[$slotId]) ) $slotId += 1;
         $this->slots[$slotId] = $p;

         /* add the slot assignment into the participant */
         $slotName = $this->name . '.' . $slotId;
         $p->categories[$this->category->id]->slot_name = $slotName;
      }

      /* store participants in slot order */
      ksort($this->slots);

      /* regenerate the matches */
      $this->recreateMatchList();
   }

   private static function splitSlotName(string $slotName, bool $throw_if_invalid = true): ?array
   {
      if (preg_match('/^\w+\.\d+$/', $slotName)) // as defined above in addParticipant()
      {
         return explode('.', $slotName);
      }
      if ($throw_if_invalid) throw new \DomainException("'$slotName' is not a valid pool slot name");
      return null;
   }

   /**
    * (re-)generate the matches for the current list of participants
    */
   private function recreateMatchList(): void
   {
      /* consider the fact that there might be gaps in the slots, due to withdrawn participants
       * for the match generation, fill those up with dummy participants, whose matches are
       * removed at the end of the generation in addNewMatchesFor() */
      $this->matches = MatchNodeCollection::new();
      $plist = ParticipantCollection::new();
      foreach ($this->slots as $slotId => $p)
      {
         while ($plist->count() < $slotId)
         {
            $plist[] = Participant::dummy();
         }
         $plist[] = $p;
      }

      $this->addNewMatchesFor($plist);
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
      return $this->ranking ??= $this->category->getPoolRankHandler()->deriveRanking($this->matches);
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
      return $ranked->count() === 1? $ranked->first()->participant : null;
   }

   /**
    * check whether all assigned matches are done
    */
   public function isConducted(): bool
   {
      return !$this->matches->empty() && $this->matches->all(fn($m, $k) => $m->isCompleted() );
   }

   /**
    * check if current relevant ranks are in order
    */
   private function ranksValid(): bool
   {
      $num_winners = $this->category->config->pool_winners ?: self::DEFAULT_NUM_WINNERS;
      return $this->getRanking()->slice(0, $num_winners)->all(fn($r, $i) => $r->rank === $i + 1);
   }

   /**
    * check whether the relevant ranks of this pool are decided
    * it is decided if, and only if
    * - all matches are completed
    * - we have exactly $num_winners winners
    */
   public function isDecided(): bool
   {
      return $this->isConducted() && $this->ranksValid();
   }

   /**
    * check whether additional tie break matches are needed
    */
   public function needsDecisionRound(): bool
   {
      return $this->isConducted() && !$this->ranksValid();
   }

   /**
    * add tie break matches if needed
    */
   public function createDecisionRound(): MatchNodeCollection
   {
      if( !$this->needsDecisionRound() ) throw new \RuntimeException("no tie break matches needed right now, refusing");

      /** @var ParticipantCollection[] $per_rank - all participants sorted by rank into collections */
      $per_rank = [];
      /** @var PoolRank $rank_entry */
      foreach( $this->getRanking() as $rank_entry )
      {
         $per_rank[$rank_entry->rank] ??= ParticipantCollection::new();
         $per_rank[$rank_entry->rank][] = $rank_entry->participant;
      }

      /* find which rank currently has more than one participant (array_find available from php8.4, only) */
      $col = null;
      foreach( $per_rank as $c )
      {
         if( $c->count() > 1 )
         {
            $col = $c;
            break;
         }
      }

      /* because of the above "needsTieBreakMatch" check, we should always have identified a list here
       * if due to some implementation bug this is not the case, just throw an exception. */
      if( !$col ) throw new \LogicException("could not identifiy any needed matches...");

      $this->current_extension += 1;
      return $this->addNewMatchesFor($col);
   }

   /**
    * get current active decision round id
    */
   public function getCurrentDecisionRound(): ?int
   {
      return $this->current_extension ?: null;
   }

   /**
    * get the list of decision matches for a specific round
    */
   public function getDecisionMatches(?int $roundId = null): MatchNodeCollection
   {
      $roundId ??= $this->getCurrentDecisionRound();
      if(!$roundId) return MatchNodeCollection::new();
      return $this->matches->filter(fn($node) => ($this->getExtensionId($node->getName()) === $roundId ));
   }

   /**
    * Assign match records to the matches in this Pool structure.
    */
   public function setMatchRecords(MatchRecordCollection $matchRecords): void
   {
      foreach ($this->matches as $match)
      {
         if ($matchRecords->keyExists($match->getName()))
         {
            $match->setMatchRecord($matchRecords[$match->getName()]);
         }
      }

      /* check if there are further records for this pool for additional matches (-> decision matches) */
      $matchId = $this->getNextMatchId();
      $extId = 0;
      $participants = $this->getParticipants();
      while ( $matchRecords->keyExists($matchName = $this->nameFor($matchId, $extId))
            ||$matchRecords->keyExists($matchName = $this->nameFor($matchId, ++$extId)))
      {
         /** @var MatchRecord $record - fetch this additional record from the provided ones */
         $record = $matchRecords[$matchName];

         $p_red   = $record->redParticipant;
         $p_white = $record->whiteParticipant;
         if( $participants->contains($p_red) && $participants->contains($p_white) )
         {
            $red     = new ParticipantSlot($p_red);
            $white   = new ParticipantSlot($p_white);
            $newNode = new MatchNode($matchName, $this->category, $red, $white, $this->area);
            $newNode->setMatchRecord($record);
            $this->matches[] = $newNode;
         }
         else
         {
            throw new \DomainException("Invalid Match record for Pool " . $this->name . ": participants do not match");
         }

         $matchId += 1; // go check for the next match
      }
      $this->current_extension = $extId - 1; # $extId is one beyond the last found

      /* If there is any extension / tie break active: freeze all previous results */
      if( $this->current_extension  > 0 )
      {
         $fixedMatches = $this->matches->filter( fn($node) => $this->getExtensionId($node->getName()) !== $this->current_extension );
         foreach( $fixedMatches as $node )
         {
            $node->frozen = true;
         }
      }
   }

   /**
    * freeze all results, to not allow any further modifications of points or winners
    */
   public function freezeResults(): void
   {
      foreach ($this->matches as $match)
      {
         $match->frozen = true;
      }
   }

   /**
    * generate the matches in this Pool.
    */
   private function addNewMatchesFor(ParticipantCollection $p): MatchNodeCollection
   {
      $report  = $this->category->getMatchCreationHandler()->generate($p);
      $matchId = $this->getNextMatchId();
      foreach ($report as $match)
      {
         $match->setName($this->nameFor($matchId++, $this->current_extension));
         $match->area = $this->area;
         // only actually store matches without dummy participants
         // we still calculate a name for them above to have fixed match ids regardless
         if( $match->isReal() ) $this->matches[] = $match;
      }
      return $report;
   }

   /**
    * match name constructor
    */
   private function nameFor(int $matchId, ?int $extension_id = 0): string
   {
      return $this->name . ($extension_id? ".e".$extension_id : '') . "." . ($matchId);
   }

   /**
    * extract the extension id from a match name
    */
   static private function getExtensionId(string $name): ?int
   {
      return (preg_match('/^.+(?:\.e(\d+))\.\d+$/', $name, $matches) && isset($matches[1]))? (int)$matches[1] : null;
   }

   /**
    * extract the plain match id from a match name
    */
   static private function getMatchId(string $name): ?int
   {
      return (preg_match('/\.(\d+)$/', $name, $matches) && isset($matches[1])) ? (int)$matches[1] : null;
   }

   /**
    * get the latest match id
    */
   private function getNextMatchId(): int
   {
      return $this->matches->empty() ? 1 : $this->getMatchId($this->matches->last()->getName())+1;
   }

   /**
    * extract the pool id/name from a slot name including a plausibility check whether this is even a valid slot name
    * pool slot ids are of the pattern <pool id>.<pool start position>
    */
   public static function getPoolIdFromSlotName(string $slotName, bool $throw_if_invalid = true): ?string
   {
      $split = static::splitSlotName($slotName, $throw_if_invalid);
      if($split) return $split[0];
      else return null;
   }
}
