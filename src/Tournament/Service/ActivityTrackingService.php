<?php

namespace Tournament\Service;

use Tournament\Policy\AuthContext;
use Tournament\Repository\AreaDeviceAccountRepository;
use Tournament\Repository\UserRepository;

class ActivityTrackingService
{
   public function __construct(
      private readonly UserRepository $userRepo,
      private readonly AreaDeviceAccountRepository $deviceRepo,
   )
   {
   }

   public function updateActivity(AuthContext $auth)
   {
      if( $auth->isUser() )
      {
         if( $auth->user->last_activity_at < new \DateTime('-60 seconds') ) // limit rate of DB writes
         {
            $this->userRepo->updateLastActivity($auth->user);
         }
      }
      elseif( $auth->isDevice() )
      {
         if( $auth->device->last_activity_at < new \DateTime('-60 seconds') ) // limit rate of DB writes
         {
            $this->deviceRepo->updateSessionActivity($auth->device->id);
         }
      }
      else
      {
         /* nothing to track */
      }
   }
}