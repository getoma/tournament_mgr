<?php

namespace Tournament\Model\MatchRecord;

use Tournament\Model\Category\Category;
use Tournament\Model\Participant\Team;
use Tournament\Model\TournamentStructure\MatchNode\MatchSide;
use Tournament\Model\TournamentStructure\MatchParticipant\MatchParticipant;

class TeamMatchRecord implements \Tournament\Model\Base\DbItem, MatchRecord
{
   use \Tournament\Model\Base\DbItemTrait;

   public function __construct(
      public ?int $id,
      public readonly string $name,
      public readonly Category $category,
      public readonly Team $redTeam,
      public readonly Team $whiteTeam,
      public ?MatchSide $winner = null,
      public readonly \DateTime $created_at = new \DateTime(),
      public ?\DateTime $finalized_at = null,
      public readonly MatchRecordCollection $matches = new MatchRecordCollection()
   )
   {
      if ($this->redTeam->id == $this->whiteTeam->id)
      {
         throw new \UnexpectedValueException("invalid match: white and red Team must be different");
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
      return true;
   }

   public function isFinalized(): bool
   {
      return $this->finalized_at !== null;
   }

   public function setWinner(?MatchParticipant $p): void
   {
      if ($p === null) $this->winner = null;
      else if ($p === $this->redTeam)   $this->winner = MatchSide::RED;
      else if ($p === $this->whiteTeam) $this->winner = MatchSide::WHITE;
      else throw new \UnexpectedValueException("given Team is not part of this record.");
   }

   public function getWinner(): ?Team
   {
      return match ($this->winner)
      {
         null => null,
         MatchSide::RED   => $this->redTeam,
         MatchSide::WHITE => $this->whiteTeam,
         default => throw new \DomainException('invalid winner value set')
      };
   }

   public function getDefeated(): ?Team
   {
      return match ($this->winner)
      {
         null => null,
         MatchSide::RED   => $this->whiteTeam,
         MatchSide::WHITE => $this->redTeam,
         default => throw new \DomainException('invalid winner value set')
      };
   }

   public function getParticipant(MatchSide $side): ?Team
   {
      return match ($side)
      {
         MatchSide::RED => $this->redTeam,
         MatchSide::WHITE => $this->whiteTeam,
         default => throw new \DomainException('invalid match side value')
      };
   }

   public function getOpponent(MatchParticipant $p): Team
   {
      if ($p === $this->redTeam)   return $this->whiteTeam;
      if ($p === $this->whiteTeam) return $this->redTeam;
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