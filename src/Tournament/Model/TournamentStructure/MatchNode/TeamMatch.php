<?php declare(strict_types=1);

namespace Tournament\Model\TournamentStructure\MatchNode;

use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\MatchRecord\MatchRecord;
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

   /* match record */
   private ?MatchRecord $matchRecord;

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
            $this->soloNodes[] = new SoloMatch(
               node_name: $this->getName() . "|" . ($i + 1),
               category: $this->category,
               slotRed: new ParticipantSlot($redParticipants[$i] ?? null),
               slotWhite: new ParticipantSlot($whiteParticipants[$i] ?? null),
               area: $this->getArea(),
               frozen: $this->isFrozen(),
               tiesAllowed: true,
            );
         }
      }

      /* done */
      return $this->soloNodes;
   }

   /**
    * set the match record associated with this match node
    * verify that the match record is consistent with this node
    */
   public function setMatchRecord(MatchRecord $matchRecord): void
   {
      $this->matchRecord = $matchRecord;
   }

   /**
    * provide the match record for this node if existing.
    */
   public function getMatchRecord(): ?MatchRecord
   {
      return $this->matchRecord;
   }

   /**
    * provide the match record for this node.
    * if none available yet, initialize it.
    */
   public function provideMatchRecord(): MatchRecord
   {
      throw new \LogicException("not implemented");
   }

   /* Participants are established, but not started, yet */
   public function isPending(): bool
   {
      return $this->isDetermined() && !$this->matchRecord;
   }

   /* Match is actually spawned, regardless of result */
   public function isEstablished(): bool
   {
      return isset($this->matchRecord);
   }

   /* Match is ongoing */
   public function isOngoing(): bool
   {
      return $this->matchRecord && !isset($this->matchRecord->finalized_at);
   }

   /* There was an actual match, and that one is already finalized */
   public function isCompleted(): bool
   {
      return $this->matchRecord && isset($this->matchRecord->finalized_at);
   }

   /* whether match ended with a tie */
   public function isTied(): bool
   {
      return $this->isCompleted() && !$this->matchRecord->getWinner();
   }

   /**
    * get the winner of this match, or null if not decided, yet
    */
   public function getWinner(): ?Team
   {
      if ($this->matchRecord)  return $this->matchRecord->getWinner();
      list($redSlot, $whiteSlot) = [$this->getRedSlot(), $this->getWhiteSlot()];
      if ($redSlot->isBye())   return $this->getWhiteSlot()->getParticipant();
      if ($whiteSlot->isBye()) return $this->getRedSlot()->getParticipant();
      return null;
   }

   /**
    * get the defeated participant of this match, or null if not decided, yet
    */
   public function getDefeated(): ?Team
   {
      return $this->matchRecord?->getDefeated();
   }
}