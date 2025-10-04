<?php

namespace Tournament\Model\Tournament;

enum TournamentStatus: string
{
   case Planning  = 'planning';  // new tournament in planning phase
   case Planned   = 'planned';   // planning completed, but tournament not yet started
   case Running   = 'running';   // tournament is currently running
   case Completed = 'completed'; // tournament has completed
}
