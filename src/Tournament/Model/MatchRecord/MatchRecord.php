<?php

namespace Tournament\Model\MatchRecord;

use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\Participant\Participant;

class MatchRecord extends \Tournament\Model\Base\DbItem
{
   public readonly \DateTime $created_at;

   public function __construct(
      ?int $id = null,
      public readonly string $name,
      public readonly Category $category,
      public readonly Area $area,
      public readonly Participant $redParticipant,
      public readonly Participant $whiteParticipant,
      public ?Participant $winner = null,
      public bool $tie_break = false,
      ?\DateTime $created_at = null,
      public ?\DateTime $finalized_at = null
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
      $this->created_at = $created_at ?? new \DateTime();
   }

   public static function validationRules(string $context = 'update'): array
   {
      throw new \LogicException("attempt to get validation rules for a match record");
   }

   public function updateFromArray(array $data): void
   {
      throw new \LogicException("bulk update of match record not expected");
   }


}