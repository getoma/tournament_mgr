<?php declare(strict_types=1);

namespace Tests\Tournament\Model\TestStubs;

use Tournament\Model\Area\Area;
use Tournament\Model\MatchRecord\MatchRecord;
use Tournament\Model\TournamentStructure\MatchNode\MatchNode;
use Tournament\Model\TournamentStructure\MatchNode\MatchNodeCollection;
use Tournament\Model\TournamentStructure\MatchNode\MatchSide;
use Tournament\Model\TournamentStructure\MatchParticipant\MatchParticipant;
use Tournament\Model\TournamentStructure\MatchSlot\MatchSlot;

/**
 * a simple test stub for a match node, which can be used in tests without the need to set up a full tournament structure.
 * It provides some basic implementations, but is not fully functional, and may throw exceptions if you try to use it
 * in a way that is not supported by the stub.
 */
class TestMatchNode implements MatchNode
{
   private ?MatchRecord $matchRecord = null;
   private bool $frozen = false;

   public function __construct(
      private string $name,
      private MatchSlot $red,
      private MatchSlot $white,
      private ?Area $area = null,
      private bool $composite = false,
      private bool $tiesAllowed = true,
      private bool $tieBreak = false,
   )
   {
   }

   /* whether this is composite match node (e.g. for team matches) */
   public function isComposite(): bool
   {
      return $this->composite;
   }

   public function getSubMatches(): ?MatchNodeCollection
   {
      return null;
   }

   /* set a match node name */
   public function setName(string $name): void
   {
      $this->name = $name;
   }

   /* get the current match node name */
   public function getName(): string
   {
      return $this->name;
   }

   /* get the area this match node is assigned to */
   public function getArea(): ?Area
   {
      return $this->area;
   }

   /* assign the node to an area */
   public function setArea(?Area $area): void
   {
      $this->area = $area;
   }

   /* extract the "local fight number" from the name
    */
   public function getLocalId(): ?int
   {
      return 0;
   }

   /* get a list of all (both) in slots, identified by MatchSide */
   public function getSlots(): array
   {
      return [
         MatchSide::RED->value => $this->red,
         MatchSide::WHITE->value => $this->white,
      ];
   }

   /* get an in-slot as identified by the parameter */
   public function getSlot(MatchSide|string $side): MatchSlot
   {
      if( $side instanceof MatchSide ) $side = $side->value;
      if( !isset($this->getSlots()[$side]) ) throw new \OutOfRangeException("invalid match side '$side'");
      return $this->getSlots()[$side];
   }

   /* get the red in-slot */
   public function getRedSlot(): MatchSlot
   {
      return $this->red;
   }

   /* get the white in-slot */
   public function getWhiteSlot(): MatchSlot
   {
      return $this->white;
   }

   /* whether a tied result is allowed */
   public function tiesAllowed(): bool
   {
      return $this->tiesAllowed;
   }

   /* completely empty node match, no participants, ever */
   public function isObsolete(): bool
   {
      return array_all([$this->red, $this->white], fn($s) => $s->isBye());
   }

   /* BYE match - only one participant there */
   public function isBye(): bool
   {
      return !$this->isObsolete() && !$this->isReal();
   }

   /* Match is a real match, and not just a dummy node that will never be conducted */
   public function isReal(): bool
   {
      return !array_any([$this->red, $this->white], fn($s) => $s->isBye());
   }

   /* Participants of this match are known */
   public function isDetermined(): bool
   {
      return array_all([$this->red, $this->white], fn($s) => $s->getParticipant() !== null);
   }

   /* Match is actually spawned, regardless of result */
   public function isEstablished(): bool
   {
      return $this->matchRecord !== null;
   }

   /* Participants are established, but not started, yet */
   public function isPending(): bool
   {
      return $this->isDetermined() && !$this->matchRecord;
   }

   /* Match is ongoing */
   public function isOngoing(): bool
   {
      return $this->matchRecord && !$this->matchRecord->isFinalized();
   }

   /* There was an actual match, and that one is already finalized */
   public function isCompleted(): bool
   {
      return $this->matchRecord?->isFinalized() ?? false;
   }

   /* whether this is a tie break match */
   public function isTieBreak(): bool
   {
      return $this->tieBreak;
   }

   /* make this match a tie break */
   public function makeTieBreak(): void
   {
      $this->tieBreak = true;
   }

   /* "Winner" of this match is known, regardless whether there was an actual match or not */
   public function isDecided(): bool
   {
      return $this->getWinner() !== null;
   }

   /* whether match ended with a tie */
   public function isTied(): bool
   {
      return $this->isCompleted() && !$this->matchRecord->getWinner();
   }

   /* Match points may not be modified anymore */
   public function isFrozen(): bool
   {
      return $this->frozen;
   }

   /* Freeze match results */
   public function freeze(): void
   {
      $this->frozen = true;
   }

   /* Match data may be modified - if we have determined the participants, and it is not frozen, yet */
   public function isModifiable(): bool
   {
      return !$this->isFrozen() && $this->isDetermined();
   }

   /* return participant per parameter - matchRecord has precedence */
   public function getParticipant(MatchSide|string $side): ?MatchParticipant
   {
      return $this->getSlot($side)->getParticipant();
   }

   /* return red-side participant */
   public function getRedParticipant(): ?MatchParticipant
   {
      return $this->getRedSlot()->getParticipant();
   }

   /* return white-side participant */
   public function getWhiteParticipant(): ?MatchParticipant
   {
      return $this->getWhiteSlot()->getParticipant();
   }

   /* get the winner of this match, or null if not decided, yet */
   public function getWinner(): ?MatchParticipant
   {
      return $this->getMatchRecord()?->getWinner();
   }

   /* get the defeated participant of this match, or null if not decided, yet */
   public function getDefeated(): ?MatchParticipant
   {
      return $this->getMatchRecord()?->getDefeated();
   }

   /* set the match record associated with this match node */
   public function setMatchRecord(?MatchRecord $matchRecord): void
   {
      $this->matchRecord = $matchRecord;
   }

   /* provide the match record for this node if existing. */
   public function getMatchRecord(): ?MatchRecord
   {
      return $this->matchRecord;
   }

   /* provide the match record for this node. if none available yet, initialize it. */
   public function provideMatchRecord(): MatchRecord
   {
      throw new \LogicException("not implemented");
   }
}