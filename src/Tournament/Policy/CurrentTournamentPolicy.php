<?php

namespace Tournament\Policy;

use Tournament\Model\Tournament\Tournament;
use Tournament\Model\Tournament\TournamentStatus;

use Tournament\Policy\TournamentAction;
use Tournament\Policy\TournamentPolicy;


/**
 * Policy handler for a specific tournament.
 * This class wraps around TournamentPolicy and provides methods
 * that do not require passing the tournament each time.
 */
class CurrentTournamentPolicy
{
   public function __construct(private ?Tournament $tournament, private TournamentPolicy $policy)
   {
   }

   public function isActionAllowed(TournamentAction|string $action): bool
   {
      if( is_string($action) ) $action = TournamentAction::from($action);
      return $this->tournament ? $this->policy->isActionAllowed($this->tournament, $action) : false;
   }

   public function getPossibleTransitions(): array
   {
      return $this->tournament ? $this->policy->getPossibleTransitions($this->tournament) : [];
   }

   public function canTransitionToStatus(TournamentStatus|string $newStatus): bool
   {
      if (is_string($newStatus)) $newStatus = TournamentAction::from($newStatus);
      return $this->tournament ? $this->policy->canTransition($this->tournament, $newStatus) : false;
   }
}
