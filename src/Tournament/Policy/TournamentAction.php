<?php

namespace Tournament\Policy;

enum TournamentAction: string
{
   // Tournament specific actions
   case ManageDetails      = "ManageDetails";      // manage tournament details (name, date, notes)
   case ManageSetup        = "ManageSetup";        // manage tournament setup (categories, areas, structure, shuffle participants)
   case ManageParticipants = "ManageParticipants"; // manage participants (add, edit, remove)
   case RecordResults      = "RecordResults";      // record results (matches)
   case TransitionState    = "TransitionState";    // change current state of the tournament

   // General actions, not tournament-specific
   case CreateTournaments  = "CreateTournaments";  // allowed to create new tournaments
   case BrowseTournaments  = "BrowseTournaments";   // see/browse a tournament at all
   case ManageUsers        = "ManageUsers";        // allow to create/manage user accounts
   case ManageAccount      = "ManageAccount";      // allow to manage own account

   /* return whether this action is specific for a tournament */
   public function isTournamentSpecificAction()
   {
      return !$this->isGeneralAction();
   }

   /* return whether this action is unrelated to a specific tournament */
   public function isGeneralAction()
   {
      return match($this)
      {
         TournamentAction::CreateTournaments,
         TournamentAction::BrowseTournaments,
         TournamentAction::ManageUsers,
         TournamentAction::ManageAccount
            => true,

         default => false
      };
   }
}
