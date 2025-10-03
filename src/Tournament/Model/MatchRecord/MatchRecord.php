<?php

namespace Tournament\Model\MatchRecord;

use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\Participant\Participant;

class MatchRecord
{
   public function __construct(
      public ?int $id,
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

      $this->created_at = $created_at ?? new \DateTime();
   }

   public readonly \DateTime $created_at;
}