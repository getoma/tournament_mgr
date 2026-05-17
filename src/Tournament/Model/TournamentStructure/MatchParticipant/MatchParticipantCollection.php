<?php declare(strict_types=1);

namespace Tournament\Model\TournamentStructure\MatchParticipant;

class MatchParticipantCollection extends \Base\Model\IdObjectCollection
{
   protected const DEFAULT_ELEMENTS_TYPE = MatchParticipant::class;

   static protected function get_id($value): mixed
   {
      return $value->getId();
   }

}