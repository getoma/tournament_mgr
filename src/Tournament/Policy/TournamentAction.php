<?php

namespace Tournament\Policy;

enum TournamentAction
{
   case ManageSetup;        // manage tournament setup (categories, areas, structure)
   case ManageParticipants; // manage participants (add, edit, remove)
   case RecordResults;      // record results (matches)
}
