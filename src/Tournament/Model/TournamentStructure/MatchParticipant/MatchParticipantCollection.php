<?php declare(strict_types=1);

namespace Tournament\Model\TournamentStructure\MatchParticipant;

class MatchParticipantCollection extends \Base\Model\IdObjectCollection
{
   protected const DEFAULT_ELEMENTS_TYPE = MatchParticipant::class;

   static protected function get_id(mixed $value): mixed
   {
      return $value->getId();
   }

   static public function from(MatchParticipantCollection $other, bool $flatten = false): static
   {
      $result = static::new();
      $type = static::DEFAULT_ELEMENTS_TYPE;

      foreach( $other as $member )
      {
         if( $member instanceof $type )
         {
            $result[] = $member;
         }
         elseif( $flatten && $member->isComposite() )
         {
            $result->mergeInPlace($member->getMembers());
         }
         else
         {
            throw new \InvalidArgumentException("unsupported participant type " . get_class($member));
         }
      }

      return $result;
   }
}