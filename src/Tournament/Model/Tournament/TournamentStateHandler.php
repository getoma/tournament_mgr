<?php declare(strict_types=1);

namespace Tournament\Model\Tournament;

class TournamentStateHandler
{
   function __construct(private Tournament $tournament)
   {
   }

   /**
    * check whether changes should be tracked
    */
   public function trackChanges(): bool
   {
      /* do not track during "planning" state, but afterwards all trackable changes should be logged */
      return match ($this->tournament->status)
      {
         TournamentStatus::Planning => false,
         default => true,
      };
   }

   /**
    * Returns a list of possible status transitions for the given tournament.
    * @return TournamentStatus[]
    */
   public function getPossibleTransitions(): array
   {
      return match ($this->tournament->status)
      {
         TournamentStatus::Planning => [TournamentStatus::Planned],
         TournamentStatus::Planned  => [TournamentStatus::Planning, TournamentStatus::Running],
         TournamentStatus::Running  => [TournamentStatus::Planning, TournamentStatus::Completed],
         TournamentStatus::Completed => [],
      };
   }

   /**
    * Checks if a transition to a new status is allowed for the given tournament.
    * @param TournamentStatus $newStatus
    * @return bool
    */
   public function canTransition(TournamentStatus $newStatus): bool
   {
      switch ($this->tournament->status)
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
            throw new \DomainException("Unhandled tournament status: " . $this->tournament->status->value);
      }
   }
}