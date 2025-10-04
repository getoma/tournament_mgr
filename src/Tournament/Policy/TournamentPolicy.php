<?php

namespace Tournament\Policy;

use Tournament\Model\Tournament\Tournament;
use Tournament\Model\Tournament\TournamentStatus;

/**
 * Tournament Policy: defines what actions are allowed in which tournament status,
 *                    and if a transition to a new status is allowed.
 */
final class TournamentPolicy
{
   /**
    * Checks if a specific action is allowed in the current status of the tournament.
    * @param Tournament $tournamentId
    * @param TournamentAction $action
    * @return bool
    */
   public static function isActionAllowed(Tournament $tournament, TournamentAction $action): bool
   {
      return match ($tournament->status)
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

   /**
    * Returns a list of possible status transitions for the given tournament.
    * @param Tournament $tournamentId
    * @return TournamentStatus[]
    */
   public static function getPossibleTransitions(Tournament $tournament): array
   {
      return match ($tournament->status)
      {
         TournamentStatus::Planning => [TournamentStatus::Planned],
         TournamentStatus::Planned  => [TournamentStatus::Planning, TournamentStatus::Running],
         TournamentStatus::Running  => [TournamentStatus::Planning, TournamentStatus::Completed],
         TournamentStatus::Completed => [],
      };
   }

   /**
    * Checks if a transition to a new status is allowed for the given tournament.
    * @param Tournament $tournamentId
    * @param TournamentStatus $newStatus
    * @return bool
    */
   public static function canTransition(Tournament $tournament, TournamentStatus $newStatus): bool
   {
      switch ($tournament->status)
      {
         case TournamentStatus::Planning:
            switch ($newStatus)
            {
               case TournamentStatus::Planned:
                  /* TODO: validty check if tournament structure and participant count match each other,
                           and all data set up (e.g. combat areas) */
                  return true;

               default:
                  return false;
            }

         case TournamentStatus::Planned:
            switch ($newStatus)
            {
               case TournamentStatus::Running:
                  /* TODO: repeat planning->planned validity checks? */
                  return true;

               case TournamentStatus::Planning:
                  /* returning into planning stage from planned is always allowed */
                  return true;

               default:
                  return false;
            }

         case TournamentStatus::Running:
            switch ($newStatus)
            {
               case TournamentStatus::Completed:
                  /* TODO: check if tournament is really completed - all expected matches happened?
                   *       allow force complete
                   */
                  return true;

               case TournamentStatus::Planning:
                  /* TODO: check for no recorded results, yet - OR request user confirmation to delete any records */
                  return true;

               default:
                  return false;
            }

         case TournamentStatus::Completed:
            return false;

         default:
            throw new \DomainException("Unhandled tournament status: " . var_export($tournament->status, true));
      }
   }
}
