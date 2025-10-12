<?php

namespace Tournament\Policy;

enum TournamentAction: string
{
   case ManageDetails      = "ManageDetails";      // manage tournament details (name, date, notes)
   case ManageSetup        = "ManageSetup";        // manage tournament setup (categories, areas, structure)
   case ManageParticipants = "ManageParticipants"; // manage participants (add, edit, remove)
   case RecordResults      = "RecordResults";      // record results (matches)
}
