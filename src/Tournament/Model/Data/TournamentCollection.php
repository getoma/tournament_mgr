<?php

namespace Tournament\Model\Data;

class TournamentCollection extends \Base\Model\IdObjectCollection
{
   protected static function elements_type(): string
   {
      return Tournament::class;
   }
}
