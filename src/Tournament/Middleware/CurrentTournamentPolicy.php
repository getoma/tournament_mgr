<?php

namespace Tournament\Middleware;

use Tournament\Model\Data\Tournament;
use Tournament\Policy\TournamentAction;
use Tournament\Policy\TournamentPolicy;
use Tournament\Model\Data\TournamentStatus;

/**
 * Policy handler for a specific tournament, identified by its ID.
 * This class wraps around TournamentPolicy and provides methods
 * that do not require passing the tournament ID each time.
 */
class CurrentTournamentPolicy
{
   public function __construct(private ?Tournament $tournament, private TournamentPolicy $policy)
   {
   }

   public function isActionAllowed(TournamentAction $action): bool
   {
      return $this->tournament ? $this->policy->isActionAllowed($this->tournament, $action) : false;
   }

   public function getPossibleTransitions(): array
   {
      return $this->tournament ? $this->policy->getPossibleTransitions($this->tournament) : [];
   }

   public function canTransitionToStatus(TournamentStatus $newStatus): bool
   {
      return $this->tournament ? $this->policy->canTransition($this->tournament, $newStatus) : false;
   }
}
