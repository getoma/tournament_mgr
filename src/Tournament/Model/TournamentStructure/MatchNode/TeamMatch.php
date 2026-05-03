<?php declare(strict_types=1);

namespace Tournament\Model\TournamentStructure\MatchNode;

use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\MatchRankHandler\MatchRank;
use Tournament\Model\MatchRankHandler\MatchRankCollection;
use Tournament\Model\MatchRecord\MatchRecord;
use Tournament\Model\MatchRecord\MatchRecordCollection;
use Tournament\Model\MatchRecord\SoloMatchRecord;
use Tournament\Model\MatchRecord\TeamMatchRecord;
use Tournament\Model\Participant\Team;
use Tournament\Model\TournamentStructure\MatchSlot\MatchSlot;
use Tournament\Model\TournamentStructure\MatchSlot\ParticipantSlot;

/**
 * class of a match between two teams, which might be part of a KO tree, a pool, or whatever
 * a team match consists of a series of solo matches between the members of each team.
 */
class TeamMatch extends MatchNodeBase
{
   /* list of solo nodes that make up this team match */
   private MatchNodeCollection $soloNodes;

   /* store the match record for the results of this match */
   private ?TeamMatchRecord $matchRecord = null;

   public function __construct(
      string $node_name,
      Category  $category,  // the category this node belongs to
      MatchSlot $slotRed,   // slot contents may be modified, but the slot itself is fixed
      MatchSlot $slotWhite, // slot contents may be modified, but the slot itself is fixed
      ?Area $area = null,
      bool $frozen = false,     // whether match record data is frozen for this node or not
      bool $tiesAllowed = true, // whether a tied result is allowed
   )
   {
      /* team matches cannot be tie breaks */
      parent::__construct($node_name, $category, $slotRed, $slotWhite, $area, $frozen, false, $tiesAllowed);
   }

   /* whether this is composite match node (e.g. for team matches) */
   public function isComposite(): bool
   {
      return true;
   }

   /* return submatches for composite nodes */
   public function getSubMatches(): ?MatchNodeCollection
   {
      /* as long as participants are not known, return empty list */
      if( !$this->isDetermined() ) return MatchNodeCollection::new();

      /* on first valid call, create the list of matches and store them */
      if( !isset($this->soloNodes) )
      {
         $this->soloNodes = MatchNodeCollection::new();
         /* create a matchnode for each pairing in each team: 1st vs 1st, 2nd vs 2nd, etc. */
         list($redTeam, $whiteTeam) = [$this->getRedParticipant(), $this->getWhiteParticipant()];
         if (!$redTeam->isComposite() || !$whiteTeam->isComposite())
         {
            throw new \LogicException("participants are no teams");
         }
         /** @var Team $redTeam */
         /** @var Team $whiteTeam */
         list($redParticipants, $whiteParticipants) = [$redTeam->members->values(), $whiteTeam->members->values()];
         $numMatches = $this->category->config->team_size;
         for ($i = 0; $i < $numMatches; $i++)
         {
            $this->soloNodes[] = new TeamSoloMatch(
               node_name: $this->nameFor($i),
               parent: $this,
               slotRed: new ParticipantSlot($redParticipants[$i] ?? null),
               slotWhite: new ParticipantSlot($whiteParticipants[$i] ?? null),
            );
         }
      }

      /* done */
      return $this->soloNodes;
   }

   /**
    * name constructor
    */
   private function nameFor(int $index)
   {
      return $this->getName() . "|" . ($index+1);
   }

   /**
    * service to derive the composite node name from a sub node name
    */
   public static function getTeamMatchName(string $name): ?string
   {
      return (preg_match('/(.+)\|\d+$/', $name, $matches) && isset($matches[1])) ? $matches[1] : null;
   }

   /**
    * set the match record associated with this match node
    * verify that the match record is consistent with this node
    * @param TeamMatchRecord $matchRecord
    */
   public function setMatchRecord(MatchRecord $matchRecord): void
   {
      if (!$matchRecord instanceof TeamMatchRecord)
      {
         throw new \DomainException('TeamMatchRecord expected!');
      }

      if (!$this->isReal())
      {
         throw new \LogicException("attempt to assign a match record to non-real match: " . $this->getName());
      }

      if ($matchRecord->name !== $this->getName())
      {
         throw new \OutOfRangeException("inconsistent match record: name does not match: " . $this->getName());
      }

      /* verify and assign the sub match records */
      $nodes = $this->getSubMatches();
      /** @var MatchRecord $mr */
      foreach( $matchRecord->matches as $mr )
      {
         /** @var SoloMatchRecord $mr */
         $node = $nodes->findNode($mr->name);
         if( !$node && $mr->tie_break )
         {
            /* special case: additional record for a tie break match */
            $node = $this->provideTieBreakMatch();
            if( $mr->name !== $node->getName() ) $node = null; // fallback to error handling if record does not match
         }
         if( !$node ) throw new \OutOfRangeException("sub match not found: " . $mr->name);
         /* as team members typically may modify their order in ev      ery match, we now explicitly assign the participants
          * to the corresponding match slots based on the recorded info in $mr */
         foreach( MatchSide::cases() as $side )
         {
            $team = $this->getParticipant($side);
            $p = $mr->getParticipant($side);
            if( !$team->members->contains($p) ) throw new \OutOfRangeException("match record contains member not in relevant team: " . $p->id);
            /** @var ParticipantSlot $slot */
            $slot = $node->getSlot($side);
            $slot->participant = $p;
         }
         $node->setMatchRecord($mr);
      }

      /* take over all relevant data from the match record */
      $this->matchRecord = $matchRecord;
      $this->setArea($nodes->first()->getArea());

      /* freeze the previous nodes */
      $this->getRedSlot()->freezeResult();
      $this->getWhiteSlot()->freezeResult();
   }

   /**
    * provide the match record for this node if existing.
    */
   public function getMatchRecord(): ?TeamMatchRecord
   {
      return $this->matchRecord;
   }

   /**
    * provide the match record for this node.
    * if none available yet, initialize it.
    */
   public function provideMatchRecord(): TeamMatchRecord
   {
      if( !$this->matchRecord )
      {
         /* fetch any already existing sub node records, but do not create new ones here */
         $subrecords = array_filter($this->getSubMatches()->map(fn($m) => $m->getMatchRecord()));
         $this->matchRecord = new TeamMatchRecord(
            id: null,
            name: $this->getName(),
            category: $this->category,
            redTeam: $this->getRedParticipant(),
            whiteTeam: $this->getWhiteParticipant(),
            matches: MatchRecordCollection::new($subrecords),
         );
      }
      return $this->matchRecord;
   }

   /* Freeze match results */
   public function freeze(): void
   {
      parent::freeze();
      $this->getSubMatches()->walk(fn($m) => $m->freeze());
   }

   /**
    * Whether all currently defined sub matches are completed
    */
   public function isConducted(): bool
   {
      return $this->isDetermined() && $this->getSubMatches()->all(fn($m) => $m->isCompleted());
   }

   /**
    * Whether match result is tied
    */
   public function isTied(): bool
   {
      return parent::isTied()                                  // default check via TeamMatchRecord
          || ($this->isConducted() && !$this->deriveWinner()); // fallback by deriving from sub node results
   }

   /**
    * return match ranking data
    */
   public function getRanking(): ?MatchRankCollection
   {
      if ($this->matchRecord) return $this->category->getMatchRankHandler()->deriveTeamMatchResults($this->matchRecord);
      if(!$this->isDetermined()) return null;
      return MatchRankCollection::new( [new MatchRank($this->getRedParticipant()), new MatchRank($this->getWhiteParticipant())] );
   }

   /**
    * get the winner of this match, or null if not decided, yet
    */
   public function getWinner(): ?Team
   {
      /* first use the default handling - check the TeamMatchRecord */
      $winner = parent::getWinner();
      if( $winner ) return $winner;
      if( $this->matchRecord?->isFinalized() ) return null; // TeamMatchRecord is set to tied result
      /* if TeamMatchRecord does not provide a winner (yet), try to derive from the solo nodes */
      if( !$this->isConducted() ) return null;
      return $this->deriveWinner();
   }

   /**
    * derive a winner from the sub nodes (instead of the overall record)
    */
   protected function deriveWinner(): ?Team
   {
      /* if rank handler derives the same rank for both teams, we have a tied result, otherwise the "first" rank is the winner */
      list($first, $second) = $this->getRanking()->values();
      return $first->rank === $second->rank ? null : $first->participant;
   }

   /**
    * provide tie break match if existing
    */
   public function getTieBreakMatch(): ?TeamSoloMatch
   {
      /* tie break match is always the last solo node, and has the corresponding property set */
      $current_last = $this->getSubMatches()->last();
      return $current_last->isTieBreak()? $current_last : null;
   }

   /**
    * create and provide a tie break match
    */
   public function provideTieBreakMatch(): TeamSoloMatch
   {
      $tieBreak = $this->getTieBreakMatch();
      if( !$tieBreak )
      {
         /* create a tie break match, default to the last participant of each team */
         list($redTeam, $whiteTeam) = [$this->getRedParticipant(), $this->getWhiteParticipant()];
         /** @var Team $redTeam   */
         /** @var Team $whiteTeam */
         $tieBreak = new TeamSoloMatch(
            node_name: $this->nameFor($this->soloNodes->count()+1),
            parent:    $this,
            slotRed:   new ParticipantSlot($redTeam->members->last()),
            slotWhite: new ParticipantSlot($whiteTeam->members->last()),
            tieBreak:  true,
         );
         /* freeze all previous matches */
         $this->soloNodes->walk(fn($n) => $n->freeze());
         /* store the tie break match */
         $this->soloNodes[] = $tieBreak;
      }
      return $tieBreak;
   }
}