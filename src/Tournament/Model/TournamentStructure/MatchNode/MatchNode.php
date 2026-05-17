<?php declare(strict_types=1);

namespace Tournament\Model\TournamentStructure\MatchNode;

use Tournament\Model\Area\Area;
use Tournament\Model\MatchRecord\MatchRecord;
use Tournament\Model\TournamentStructure\MatchParticipant\MatchParticipant;
use Tournament\Model\TournamentStructure\MatchSlot\MatchSlot;

/**
 * base interface to represent a match node in a tournament, regardless what kind of match it is.
 */
interface MatchNode
{
   /* whether this is composite match node (e.g. for team matches) */
   public function isComposite(): bool;

   /* return submatches for composite nodes */
   public function getSubMatches(): ?MatchNodeCollection;

   /* set a match node name */
   public function setName(string $name): void;

   /* get the current match node name */
   public function getName(): string;

   /* get the area this match node is assigned to */
   public function getArea(): ?Area;

   /* assign the node to an area */
   public function setArea(?Area $area): void;

   /**
    * extract the "local fight number" from the name
    * This is supposed to be the number at the end of the name
    */
   public function getLocalId(): ?int;

   /* get a list of all (both) in slots, identified by MatchSide */
   public function getSlots(): array;

   /* get an in-slot as identified by the parameter */
   public function getSlot(MatchSide|string $side): MatchSlot;

   /* get the red in-slot */
   public function getRedSlot(): MatchSlot;

   /* get the white in-slot */
   public function getWhiteSlot(): MatchSlot;

   /* whether a tied result is allowed */
   public function tiesAllowed(): bool;

   /* completely empty node match, no participants, ever */
   public function isObsolete(): bool;

   /* BYE match - only one participant there */
   public function isBye(): bool;

   /* Match is a real match, and not just a dummy node that will never be conducted */
   public function isReal(): bool;

   /* Participants of this match are known */
   public function isDetermined();

   /* Match is actually spawned, regardless of result */
   public function isEstablished(): bool;

   /* Participants are established, but not started, yet */
   public function isPending(): bool;

   /* Match is ongoing */
   public function isOngoing(): bool;

   /* There was an actual match, and that one is already finalized */
   public function isCompleted(): bool;

   /* whether this is a tie break match */
   public function isTieBreak(): bool;

   /* make this match a tie break */
   public function makeTieBreak(): void;

   /* "Winner" of this match is known, regardless whether there was an actual match or not */
   public function isDecided(): bool;

   /* whether match ended with a tie */
   public function isTied(): bool;

   /* Match points may not be modified anymore */
   public function isFrozen(): bool;

   /* Freeze match results */
   public function freeze(): void;

   /* Match data may be modified - if we have determined the participants, and it is not frozen, yet */
   public function isModifiable(): bool;

   /* return participant per parameter - matchRecord has precedence */
   public function getParticipant(MatchSide|string $side): ?MatchParticipant;

   /* return red-side participant */
   public function getRedParticipant(): ?MatchParticipant;

   /* return white-side participant */
   public function getWhiteParticipant(): ?MatchParticipant;

   /* get the winner of this match, or null if not decided, yet */
   public function getWinner(): ?MatchParticipant;

   /* get the defeated participant of this match, or null if not decided, yet */
   public function getDefeated(): ?MatchParticipant;

   /* set the match record associated with this match node */
   public function setMatchRecord(MatchRecord $matchRecord): void;

   /* provide the match record for this node if existing. */
   public function getMatchRecord(): ?MatchRecord;

   /* provide the match record for this node. if none available yet, initialize it. */
   public function provideMatchRecord(): MatchRecord;
}
