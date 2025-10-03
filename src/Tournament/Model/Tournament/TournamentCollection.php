<?php

namespace Tournament\Model\Tournament;

class TournamentCollection extends \Base\Model\IdObjectCollection
{
   protected static function elements_type(): string
   {
      return Tournament::class;
   }
}
