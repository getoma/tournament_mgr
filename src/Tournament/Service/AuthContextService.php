<?php

namespace Tournament\Service;

use Base\Service\AuthService;
use Tournament\Repository\TournamentRepository;

/**
 * Service to create an authorization context for a request
 */
class AuthContextService
{
   public function __construct(
      private AuthService $authService,
      private AreaDeviceService $deviceService,
      private TournamentRepository $tournamentRepo
   )
   {
   }

   public function createContext(): AuthContext
   {
      if ($this->authService->isAuthenticated())
      {
         $currentUser = $this->authService->getCurrentUser();
         $ctx = AuthContext::as_user($currentUser);
      }
      else if ($this->deviceService->isDeviceAccount() )
      {
         $area = $this->deviceService->getArea();
         $tournament = $this->tournamentRepo->getTournamentByAreaId($area->id);
         $ctx = AuthContext::as_device($tournament, $area);
      }
      else
      {
         $ctx = AuthContext::as_anonymous();
      }

      return $ctx;
   }
}