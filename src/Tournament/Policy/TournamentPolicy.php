<?php

namespace Tournament\Policy;

use Tournament\Model\Data\TournamentStatus;
use Tournament\Repository\TournamentRepository;

/**
 * Tournament Policy: defines what actions are allowed in which tournament status,
 *                    and if a transition to a new status is allowed.
 */
final class TournamentPolicy
{

   public function __construct(private TournamentRepository $repo)
   {
   }

   /**
    * Checks if a specific action is allowed in the current status of the tournament.
    * @param int $tournamentId
    * @param TournamentAction $action
    * @return bool
    */
   public function isActionAllowed(int $tournamentId, TournamentAction $action): bool
   {
      $tournament = $this->repo->getTournamentById($tournamentId);

      if (!$tournament)
      {
         throw new \InvalidArgumentException("Tournament with ID $tournamentId does not exist.");
      }

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
    * @param int $tournamentId
    * @return TournamentStatus[]
    */
   public function getPossibleTransitions(int $tournamentId): array
   {
      $tournament = $this->repo->getTournamentById($tournamentId);

      if (!$tournament)
      {
         throw new \InvalidArgumentException("Tournament with ID $tournamentId does not exist.");
      }

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
    * @param int $tournamentId
    * @param TournamentStatus $newStatus
    * @return bool
    */
   public function canTransition(int $tournamentId, TournamentStatus $newStatus): bool
   {
      $tournament = $this->repo->getTournamentById($tournamentId);

      if (!$tournament)
      {
         throw new \InvalidArgumentException("Tournament with ID $tournamentId does not exist.");
      }

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
            throw new \InvalidArgumentException("Unhandled tournament status: " . var_export($tournament->status, true));
      }
   }
}
