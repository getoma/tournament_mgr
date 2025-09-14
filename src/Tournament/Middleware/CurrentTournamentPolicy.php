<?php

namespace Tournament\Middleware;

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
   public function __construct(private ?int $tournamentId, private TournamentPolicy $repo)
   {
   }

   public function isActionAllowed(TournamentAction $action): bool
   {
      return $this->tournamentId ? $this->repo->isActionAllowed($this->tournamentId, $action) : false;
   }

   public function getPossibleTransitions(): array
   {
      return $this->tournamentId ? $this->repo->getPossibleTransitions($this->tournamentId) : [];
   }

   public function canTransitionToStatus(TournamentStatus $newStatus): bool
   {
      return $this->tournamentId ? $this->repo->canTransition($this->tournamentId, $newStatus) : false;
   }
}
