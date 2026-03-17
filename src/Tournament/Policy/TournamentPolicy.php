<?php

namespace Tournament\Policy;

use Tournament\Model\Tournament\Tournament;
use Tournament\Model\Tournament\TournamentStatus;
use Tournament\Model\User\Role;
use Tournament\Model\User\RoleCollection;
use Tournament\Model\User\User;

use Tournament\Service\RouteArgsContext;

/**
 * class to define which actions are allowed in a specific authorization context
 */
final class TournamentPolicy
{
   function __construct(
      private readonly AuthContext $auth_context,      // auth_context is always available
      private readonly RouteArgsContext $route_context // policy must be accessible/usable even if no RouteArgsContext available
   )
   {
   }

   /**
    * check if the current authorization context covers
    * access as a certain authorization type
    */
   public function hasAccessAs(AuthType $authType): bool
   {
      if( $this->auth_context->authtype !== $authType ) return false;
      if( $this->auth_context->isDevice() )
      {
         if( $this->route_context->tournament && $this->auth_context->tournament !== $this->route_context->tournament ) return false;
         if( $this->route_context->category   && !$this->auth_context->tournament->categories->contains($this->route_context->category) ) return false;
         if( $this->route_context->area       && $this->auth_context->area !== $this->route_context->area) return false;
         /**
          * we cannot easily check match name or pool here, because the whole TournamentStructure needs to be loaded for that
          * this access check has to be done on Controller level, unfortunately...
          */
      }
      return true; // all checks passed
   }

   /**
    * Checks if a specific action is allowed in the current authorization context
    * @param TournamentAction|string $action
    * @return bool
    */
   public function isActionAllowed(TournamentAction|string $action): bool
   {
      if( is_string($action) ) $action = TournamentAction::from($action);
      return $this->checkAuthorizationPolicy($action) && $this->checkStatePolicy($action);
   }

   /**
    * mapping of Actions to permissable states
    */
   private const StatePolicyMap = [
      /* edit tournament details: name, date, notes */
      TournamentAction::ManageDetails->value       => [ TournamentStatus::Planning, TournamentStatus::Planned, TournamentStatus::Running ],
      /* re-configure the entire tournament: setting up categories and areas */
      TournamentAction::ManageSetup->value         => [ TournamentStatus::Planning ],
      /* moving a match to a different area is always allowed */
      TournamentAction::ModifyMatchArea->value     => [TournamentStatus::Planning, TournamentStatus::Planned, TournamentStatus::Running],
      /* bulk import only allowed during planning time */
      TournamentAction::ImportParticipants->value  => [ TournamentStatus::Planning ],
      /* adding/editing/disabling single participants - always allowed */
      TournamentAction::ModifyParticipants->value  => [ TournamentStatus::Planning, TournamentStatus::Planned, TournamentStatus::Running ],
      /* full deleting of participants only allowed during planning time.
       * after that, participants can only be set to "withdrawn" via the modify action */
      TournamentAction::DeleteParticipants->value  => [ TournamentStatus::Planning ],
      /* re-shuffle all participants - only during planning stage */
      TournamentAction::ShuffleParticipants->value => [ TournamentStatus::Planning ],
      /* only provide area device services once the area are fixed after planning time */
      TournamentAction::ManageAreaDevices->value   => [ TournamentStatus::Planned, TournamentStatus::Running ],
      /* actually record any match results */
      TournamentAction::RecordResults->value       => [ TournamentStatus::Running ],
      /* update the state of the tournament */
      TournamentAction::TransitionState->value     => [ TournamentStatus::Planning, TournamentStatus::Planned, TournamentStatus::Running ],
      /* only allow to delete tournaments that are either freshly created, or completed */
      TournamentAction::DeleteTournament->value    => [ TournamentStatus::Planning, TournamentStatus::Completed ]
   ];

   /**
    * Check if a specific action is allowed for a specific tournament status
    */
   private function checkStatePolicy(TournamentAction $action): bool
   {
      if( !isset(self::StatePolicyMap[$action->value]) ) return true; // if no state policy defined for this action, allow it - not status dependent
      $status = $this->route_context->tournament?->status ?? $this->auth_context->tournament?->status;
      if( !isset($status) ) return false; // no valid status available - decline
      return in_array($status, self::StatePolicyMap[$action->value]);
   }

   /**
    * Check if a specific action is allowed according current authorization
    */
   private function checkAuthorizationPolicy(TournamentAction $action): bool
   {
      if ($this->auth_context->hasRole(Role::ADMIN)) return true; // no restrictions for admins

      // determine authorization status depending on the specific action
      switch( $action )
      {
         case TournamentAction::ManageDetails:
         case TournamentAction::ManageOwners:
         case TournamentAction::ManageSetup:
         case TournamentAction::ModifyMatchArea:
         case TournamentAction::ImportParticipants:
         case TournamentAction::ModifyParticipants:
         case TournamentAction::DeleteParticipants:
         case TournamentAction::ShuffleParticipants:
         case TournamentAction::ManageAreaDevices:
         case TournamentAction::TransitionState:
         case TournamentAction::DeleteTournament:
            /* tournament specific actions: allowed if actual user with tournament ownership */
            return  $this->auth_context->isUser()
                 && $this->route_context->tournament?->owners->contains($this->auth_context->user) ?? false;

         case TournamentAction::BrowseTournament:
         case TournamentAction::RecordResults:
            /* allowed if has access to the tournament and is not anonymous */
            if( !$this->auth_context->isAuthenticated() ) return false;
            /* allowed if tournament access defined via auth context */
            if( $this->auth_context->tournament )
            {
               return !$this->route_context->tournament || $this->route_context->tournament === $this->auth_context->tournament;
            }
            /* allowed if current user has access to this specific tournament */
            if( $this->route_context->tournament )
            {
               return $this->hasTournamentAccess($this->route_context->tournament);
            }
            /* tournament isn't even defined, decline access */
            return false;

         case TournamentAction::CreateTournaments:
            return $this->auth_context->hasRole(Role::ORGANIZER);

         case TournamentAction::ManageUsers:
            return $this->auth_context->hasRole(Role::USER_MANAGER);

         case TournamentAction::ManageAccount:
            return $this->auth_context->isUser(); // only actual users do have an account (unlike device accounts)

         default:
            return false;
      }
   }

   const PROTECTED_ROLES = [Role::ADMIN, Role::USER_MANAGER];

   /**
    * user management policy: check whether a specific user may be modified in the current context
    */
   public function canModifyUser(User $target, ?RoleCollection $newRoles = null)
   {
      if ($this->auth_context->hasRole(Role::ADMIN)) return true; // no restrictions for admins
      if (!$this->_canManageUser($target) ) return false;    // common checks

      if (isset($newRoles)) // check if all role modifications are allowed
      {
         foreach( $target->roles->sym_diff($newRoles) as $role )
         {
            if (in_array($role, self::PROTECTED_ROLES)) return false;
         }
      }

      return true; // all checks passed
   }

   /**
    * check whether a specific role can be assigned to a user
    */
   public function canModifyRole(User $target, Role|string $role)
   {
      if (is_string($role)) $role = Role::from($role);

      if ($this->auth_context->hasRole(Role::ADMIN)) return true; // no restrictions for admins
      if (!$this->_canManageUser($target)) return false;     // common checks
      return !(in_array($role, self::PROTECTED_ROLES));      // non-admins may not elevate or degrade other users
   }

   /**
    * common checks whether user management is allowed
    */
   private function _canManageUser(User $target): bool
   {
      if ($this->auth_context->user->id == $target->id) return false;      // no self-management
      if (!$this->auth_context->hasRole(Role::USER_MANAGER)) return false; // if not user_manager, any user management is forbidden
      if (!$target->roles->intersect(self::PROTECTED_ROLES)->empty()) return false; // may not modify other admins/user_managers
      return true;
   }

   /**
    * return whether current user has access to a specific tournament
    */
   public function hasTournamentAccess(Tournament $tournament): bool
   {
      return $this->auth_context->hasRole(Role::ADMIN) ||
             $tournament->owners->contains($this->auth_context->user) ||
             $this->auth_context->tournament === $tournament;
   }
}
