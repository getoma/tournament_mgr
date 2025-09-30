<?php

namespace Tournament\Model\Data;

enum TournamentStatus: string
{
   case Planning  = 'planning';  // new tournament in planning phase
   case Planned   = 'planned';   // planning completed, but tournament not yet started
   case Running   = 'running';   // tournament is currently running
   case Completed = 'completed'; // tournament has completed

   public static function load(string $value): self
   {
      return match ($value)
      {
         'planning'  => self::Planning,
         'planned'   => self::Planned,
         'running'   => self::Running,
         'completed' => self::Completed,
         default     => throw new \UnexpectedValueException("Invalid tournament status value: " . var_export($value, true))
      };
   }
}
