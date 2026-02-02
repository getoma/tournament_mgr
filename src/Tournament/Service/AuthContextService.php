<?php

namespace Tournament\Service;

use Psr\Http\Message\ServerRequestInterface;

use Tournament\Model\User\AuthContext;

use Base\Service\AuthService;

/**
 * Service to create an authorization context for a request
 */
class AuthContextService
{
   public function __construct(private AuthService $authService)
   {
   }

   public function createContext(ServerRequestInterface $request): AuthContext
   {
      $tournament = $request->getAttribute('tournament', null);
      $area = $request->getAttribute('area', null);

      if ($this->authService->isAuthenticated())
      {
         $currentUser = $this->authService->getCurrentUser();
         $ctx = AuthContext::user($currentUser, $tournament, $area);
      }
      else
      {
         $ctx = AuthContext::anonymous($tournament, $area);
      }

      return $ctx;
   }
}