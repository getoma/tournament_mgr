<?php

namespace Tournament\Model\Data;

use DateTime;
use InvalidArgumentException;

class MatchRecord
{
   public function __construct(
      public int $id,
      public string $name,
      public Category $category,
      public Area $area,
      public Participant $whiteParticipant,
      public Participant $redParticipant,
      public ?Participant $winner,
      public bool $tie_break,
      public DateTime $created_at,
      public ?DateTime $finalized_at
   )
   {
      if(  isset($this->winner)
        && $this->winner !== $this->whiteParticipant
        && $this->winner !== $this->redParticipant
        )
      {
         throw new InvalidArgumentException("invalid winner: must be identical to either white or red");
      }

      if( $this->whiteParticipant->id == $this->redParticipant->id )
      {
         throw new InvalidArgumentException("invalid match: white and red participant must be different");
      }
   }
}