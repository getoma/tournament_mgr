<?php

namespace Tournament\Policy;

use Tournament\Model\Data\TournamentStatus;

/**
 * Defines the actions that can be performed on a tournament based on its current state.
 */
final class TournamentPolicy
{
   /** @return bool */
   public function isActionAllowed(TournamentStatus $status, TournamentAction $action): bool
   {
      return match ($status)
      {
         TournamentStatus::Planning => match ($action)
         {
            TournamentAction::ManageSetup     => true,
            TournamentAction::ManageParticipants => true,
            default => false,
         },
         TournamentStatus::Planned => match ($action)
         {
            TournamentAction::ManageParticipants => true,
            default => false,
         },
         TournamentStatus::Running => match ($action)
         {
            TournamentAction::ManageParticipants => true,
            TournamentAction::RecordResults   => true,
            default => false,
         },
         TournamentStatus::Completed => match ($action)
         {
            default => false,
         },
      };
   }
}
