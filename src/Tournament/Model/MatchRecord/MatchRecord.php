<?php

namespace Tournament\Model\MatchRecord;

use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\Participant\Participant;

class MatchRecord extends \Tournament\Model\Base\DbItem
{
   public function __construct(
      ?int $id,
      public readonly string $name,
      public readonly Category $category,
      public readonly Area $area,
      public readonly Participant $redParticipant,
      public readonly Participant $whiteParticipant,
      public ?Participant $winner = null,
      public bool $tie_break = false,
      public readonly \DateTime $created_at = new \DateTime(),
      public ?\DateTime $finalized_at = null,
      public readonly MatchPointCollection $points = new MatchPointCollection()
   )
   {
      if(  isset($this->winner)
        && $this->winner !== $this->whiteParticipant
        && $this->winner !== $this->redParticipant
        )
      {
         throw new \UnexpectedValueException("invalid winner: must be identical to either white or red");
      }

      if( $this->whiteParticipant->id == $this->redParticipant->id )
      {
         throw new \UnexpectedValueException("invalid match: white and red participant must be different");
      }

      $this->id = $id;
   }

   public static function validationRules(string $context = 'update'): array
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
