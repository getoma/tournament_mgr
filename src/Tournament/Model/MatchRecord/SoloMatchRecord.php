<?php

namespace Tournament\Model\MatchRecord;

use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\Participant\Participant;
use Tournament\Model\TournamentStructure\MatchNode\MatchSide;
use Tournament\Model\TournamentStructure\MatchParticipant\MatchParticipant;

class SoloMatchRecord implements \Tournament\Model\Base\DbItem, MatchRecord
{
   use \Tournament\Model\Base\DbItemTrait;

   public function __construct(
      public ?int $id,
      public readonly string $name,
      public readonly Category $category,
      public Area $area,
      public Participant $redParticipant,   // participants inside a team match must be modifyable also after creation
      public Participant $whiteParticipant,
      public ?MatchSide $winner = null,
      public bool $tie_break = false,
      public readonly \DateTime $created_at = new \DateTime(),
      public ?\DateTime $finalized_at = null,
      public readonly MatchPointCollection $points = new MatchPointCollection(),
      public readonly ?TeamMatchRecord $team_match = null,
   )
   {
      if( $this->whiteParticipant->id == $this->redParticipant->id )
      {
         throw new \UnexpectedValueException("invalid match: white and red participant must be different");
      }
   }

   public function getId(): int
   {
      return $this->id;
   }

   public function getMatchName(): string
   {
      return $this->name;
   }

   public function isComposite(): bool
   {
      return false;
   }

   public function isFinalized(): bool
   {
      return $this->finalized_at !== null;
   }

   public function setWinner(?MatchParticipant $p): void
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

   public function getParticipant(MatchSide $side): ?Participant
   {
      return match ($side)
      {
         MatchSide::RED => $this->redParticipant,
         MatchSide::WHITE => $this->whiteParticipant,
         default => throw new \DomainException('invalid match side value')
      };
   }

   public function setParticipant(MatchSide $side, Participant $p): void
   {
      if( $side === MatchSide::RED ) $slot = &$this->redParticipant;
      else if( $side === MatchSide::WHITE) $slot = &$this->whiteParticipant;
      else throw new \DomainException('invalid match side value');
      if( $slot !== $p )
      {
         $this->points->updateParticipant($slot, $p);
         $slot = $p;
      }
   }

   public function getOpponent(MatchParticipant $p): Participant
   {
      if ($p === $this->redParticipant) return $this->whiteParticipant;
      if ($p === $this->whiteParticipant) return $this->redParticipant;
      throw new \UnexpectedValueException("given participant is not part of this record.");
   }

   public static function validationRules(): array
   {
      throw new \LogicException("attempt to get validation rules for a match record");
   }

   public function updateFromArray(array $data): void
   {
      throw new \LogicException("bulk update of match record not expected");
   }
}
