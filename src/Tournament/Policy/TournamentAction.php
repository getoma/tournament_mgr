<?php

namespace Tournament\Policy;

enum TournamentAction: string
{
   case CreateTournaments  = "CreateTournaments";  // allowed to create new tournaments

   case BrowseTournament   = "BrowseTournament";   // see/browse a specific tournament at all
   case ManageDetails      = "ManageDetails";      // manage tournament details (name, date, notes)
   case ManageOwners       = "ManageOwners";       // allow to assign owners to a tournament
   case ManageSetup        = "ManageSetup";        // manage tournament setup (categories, areas, structure, shuffle participants)
   case ManageParticipants = "ManageParticipants"; // manage participants (add, edit, remove)
   case RecordResults      = "RecordResults";      // record results (matches)
   case TransitionState    = "TransitionState";    // change current state of the tournament
   case DeleteTournament   = "DeleteTournament";   // delete a tournament

   case ManageUsers        = "ManageUsers";        // allow to create/manage user accounts
   case ManageAccount      = "ManageAccount";      // allow to manage own account
}
