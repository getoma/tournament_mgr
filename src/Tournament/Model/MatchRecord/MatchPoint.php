<?php

namespace Tournament\Model\MatchRecord;

use \Respect\Validation\Validator as v;
use Tournament\Model\Participant\Participant;

class MatchPoint implements \Tournament\Model\Base\DbItem
{
   use \Tournament\Model\Base\DbItemTrait;

   public function __construct(
      public ?int $id,
      public readonly Participant $participant,
      public readonly string $point,
      public readonly \DateTime $given_at,
      public readonly ?self $caused_by = null
   )
   {
   }

   /**
    * clone this point with a new participant
    * use case: re-assign points after updated team match order
    */
   public function cloneFor(Participant $p): static
   {
      return new static(
         id: null,
         participant: $p,
         point: $this->point,
         given_at: $this->given_at,
         caused_by: $this->caused_by
      );
   }

   protected static function validationRules(): array
   {
      return [
         'point' => v::stringType()->length(1, 1),
      ];
   }

   public function updateFromArray(array $data): void
   {
      throw new \LogicException("MatchPoint is immutable");
   }

   public function isSolitary()
   {
      return $this->caused_by === null;
   }
}
