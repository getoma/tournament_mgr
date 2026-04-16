<?php

namespace Tournament\Model\MatchRecord;

use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\Participant\Participant;
use Tournament\Model\TournamentStructure\MatchNode\MatchSide;

class MatchRecord implements \Tournament\Model\Base\DbItem
{
   use \Tournament\Model\Base\DbItemTrait;

   public function __construct(
      public ?int $id,
      public readonly string $name,
      public readonly Category $category,
      public readonly Area $area,
      public readonly Participant $redParticipant,
      public readonly Participant $whiteParticipant,
      public ?MatchSide $winner = null,
      public bool $tie_break = false,
      public readonly \DateTime $created_at = new \DateTime(),
      public ?\DateTime $finalized_at = null,
      public readonly MatchPointCollection $points = new MatchPointCollection()
   )
   {
      if( $this->whiteParticipant->id == $this->redParticipant->id )
      {
         throw new \UnexpectedValueException("invalid match: white and red participant must be different");
      }
   }

   public function setWinner(?Participant $p): void
   {
      if ($p === null) $this->winner = null;
      else if ($p === $this->redParticipant) $this->winner = MatchSide::RED;
      else if ($p === $this->whiteParticipant) $this->winner = MatchSide::WHITE;
      else throw new \UnexpectedValueException("given participant is not part of this record.");
   }

   public function getWinner(): ?Participant
   {
      return match ($this->winner)
      {
         null => null,
         MatchSide::RED   => $this->redParticipant,
         MatchSide::WHITE => $this->whiteParticipant,
         default => throw new \DomainException('invalid winner value set')
      };
   }

   public function getDefeated(): ?Participant
   {
      return match ($this->winner)
      {
         null => null,
         MatchSide::RED   => $this->whiteParticipant,
         MatchSide::WHITE => $this->redParticipant,
         default => throw new \DomainException('invalid winner value set')
      };
   }

   public static function validationRules(): array
   {
      throw new \LogicException("attempt to get validation rules for a match record");
   }

   public function updateFromArray(array $data): void
   {
      throw new \LogicException("bulk update of match record not expected");
   }

   public function getOpponent(Participant $p): Participant
   {
      if( $p === $this->redParticipant   ) return $this->whiteParticipant;
      if( $p === $this->whiteParticipant ) return $this->redParticipant;
      throw new \UnexpectedValueException("given participant is not part of this record.");
   }

}
