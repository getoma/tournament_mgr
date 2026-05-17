<?php declare(strict_types=1);

namespace Tournament\Model\TournamentStructure\MatchNode;

use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\TournamentStructure\MatchParticipant\MatchParticipant;
use Tournament\Model\TournamentStructure\MatchSlot\MatchSlot;
use Tournament\Model\TournamentStructure\MatchSlot\ParticipantSlot;

/**
 * base class to manage a match, which might be part of a KO tree, a pool, or whatever
 * provides some basic implementations shared by all types of matches (solo/team, KO/Pool, ...)
 */
abstract class MatchNodeBase implements MatchNode
{
   private string $name;

   private array $slots;

   public function __construct(
      string $node_name,
      public readonly Category  $category,  // the category this node belongs to
      MatchSlot $slotRed,                   // slot contents may be modified, but the slot itself is fixed
      MatchSlot $slotWhite,                 // slot contents may be modified, but the slot itself is fixed
      private ?Area $area = null,
      private bool $_frozen = false,         // whether match record data is frozen for this node or not
      private bool $_tieBreak = false,       // whether this match is a tie break
      private bool $_tiesAllowed = true,     // whether a tied result is allowed
   )
   {
      if ($slotRed === $slotWhite)
      {
         throw new \DomainException("invalid match: red and white slot must be different - $node_name");
      }

      $this->slots = [
         MatchSide::RED->value   => $slotRed,
         MatchSide::WHITE->value => $slotWhite,
      ];

      $this->setName($node_name);
   }

   static private function getSlotSuffix(MatchSide|string $side)
   {
      if( !is_string($side) ) $side = $side->value;
      return strtolower($side[0]);
   }

   public function setName(string $name): void
   {
      $this->name = $name;

      /* propagate slot names if needed */
      foreach( $this->slots as $side => $slot )
      {
         if( $slot instanceof ParticipantSlot )
         {
            $suffix = static::getSlotSuffix($side);
            $slot->slotName = $name . $suffix;
         }
      }
   }

   /**
    * extract the Node name from a slot name - defined here to keep this knowledge on one place
    * (see above in setName() where slot names are constructed)
    */
   public static function getNodeNameFromSlotName(string $slotName, bool $throw_if_invalid = true): ?string
   {
      $known_suffixes = array_map( fn($s) => static::getSlotSuffix($s), MatchSide::cases() );
      if (in_array(substr($slotName, -1), $known_suffixes)) return substr($slotName, 0, -1);
      if ($throw_if_invalid) throw new \InvalidArgumentException("'$slotName' is not a valid MatchNode slot name");
      return null;
   }

   public function getName(): string
   {
      return $this->name;
   }

   public function getArea(): ?Area
   {
      return $this->area;
   }

   public function setArea(?Area $area): void
   {
      $this->area = $area;
   }

   /**
    * extract the "local fight number" from the name
    * This is supposed to be the number at the end of the name
    */
   public function getLocalId(): ?int
   {
      return preg_match('/\d+$/', $this->name, $matches)? intval($matches[0]) : null;
   }

   /* get a list of all (both) in slots, identified by MatchSide */
   public function getSlots(): array
   {
      return $this->slots;
   }

   /**
    * get an in-slot as identified by the parameter
    */
   public function getSlot(MatchSide|string $side): MatchSlot
   {
      if( $side instanceof MatchSide ) $side = $side->value;
      if( !isset($this->slots[$side]) ) throw new \OutOfRangeException("invalid match side '$side'");
      return $this->slots[$side];
   }

   /* get the red in-slot */
   public function getRedSlot(): MatchSlot
   {
      return $this->slots[MatchSide::RED->value];
   }

   /* get the white in-slot */
   public function getWhiteSlot(): MatchSlot
   {
      return $this->slots[MatchSide::WHITE->value];
   }

   /* whether a tie result is allowed */
   public function tiesAllowed(): bool
   {
      return $this->_tiesAllowed && !$this->isTieBreak();
   }

   /* completely empty node match, no participants, ever */
   public function isObsolete(): bool
   {
      return array_all( $this->slots, fn($s) => $s->isBye() );
   }

   /* BYE match - at least on bye slot, but not all of them */
   public function isBye(): bool
   {
      $byeFound = false;
      $filledFound = false;
      foreach( $this->slots as $slot )
      {
         $byeFound = $byeFound || $slot->isBye();
         $filledFound = $filledFound || !$slot->isBye();
         if( $byeFound && $filledFound ) return true;
      }
      return false;
   }

   /* Match is a real match, and not just a dummy node that will never be conducted */
   public function isReal(): bool
   {
      return !array_any($this->slots, fn($s) => $s->isBye());
   }

   /* Participants of this match are known */
   public function isDetermined()
   {
      return array_all($this->slots, fn($s) => $s->getParticipant() !== null );
   }

   /* Match is actually spawned, regardless of result */
   abstract public function isEstablished(): bool;

   /* Participants are established, but not started, yet */
   abstract public function isPending(): bool;

   /* Match is ongoing */
   abstract public function isOngoing(): bool;

   /* There was an actual match, and that one is already finalized */
   abstract public function isCompleted(): bool;

   /* whether this is a tie break match */
   public function isTieBreak(): bool
   {
      return $this->_tieBreak;
   }

   /* make this match a tie break */
   public function makeTieBreak(): void
   {
      $this->_tieBreak = true;
   }

   /* "Winner" of this match is known, regardless whether there was an actual match or not */
   public function isDecided(): bool
   {
      return $this->getWinner() !== null;
   }

   /* whether match ended with a tie */
   abstract public function isTied(): bool;

   /* Match points may not be modified anymore */
   public function isFrozen(): bool
   {
      return $this->_frozen;
   }

   /* Freeze match results */
   public function freeze(): void
   {
      $this->_frozen = true;
   }

   /* Match data may be modified - if we have determined the participants, and it is not frozen, yet */
   public function isModifiable(): bool
   {
      return $this->isDetermined() && !$this->isFrozen();
   }

   /* return participant per parameter */
   public function getParticipant(MatchSide|string $side): ?MatchParticipant
   {
      return $this->getSlot($side)->getParticipant();
   }

   /* return red-side participant */
   public function getRedParticipant(): ?MatchParticipant
   {
      return $this->getParticipant(MatchSide::RED);
   }

   /* return white-side participant */
   public function getWhiteParticipant(): ?MatchParticipant
   {
      return $this->getParticipant(MatchSide::WHITE);
   }

   /* get the winner of this match, or null if not decided, yet */
   abstract public function getWinner(): ?MatchParticipant;

   /* get the defeated participant of this match, or null if not decided, yet */
   abstract public function getDefeated(): ?MatchParticipant;
}
