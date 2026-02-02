<?php

namespace Tournament\Policy;

use Tournament\Model\Tournament\TournamentStatus;
use Tournament\Model\User\AuthContext;
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
      private readonly AuthContext $auth_context,       // auth_context is always available
      private readonly ?RouteArgsContext $route_context // policy must be accessible/usable even if no RouteArgsContext available
   )
   {
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
    * Check if a specific action is allowed for a specific tournament status
    */
   private function checkStatePolicy(TournamentAction $action): bool
   {
      $status = $this->route_context?->tournament?->status ?? null;
      return match ($action)
      {
         TournamentAction::ManageDetails => match($status)
         {
            /* edit tournament details: name, date, notes */
            TournamentStatus::Planning, TournamentStatus::Planned, TournamentStatus::Running => true,
            default => false
         },

         TournamentAction::ManageSetup => match($status)
         {
            /* re-configure the entire tournament: setting up categories and areas, shuffling participants */
            TournamentStatus::Planning => true,
            default => false
         },

         TournamentAction::ManageParticipants => match($status)
         {
            /* adding/removing/editing single participants - always allowed           */
            /* completely reshuffling participants is part of the ManageSetup action! */
            TournamentStatus::Planning, TournamentStatus::Planned, TournamentStatus::Running => true,
            default => false
         },

         TournamentAction::RecordResults => match ($status)
         {
            /* actually record any match results */
            TournamentStatus::Running => true,
            default => false
         },

         TournamentAction::TransitionState => match ($status)
         {
            /* update the state of the tournament */
            TournamentStatus::Completed, null => false,
            default => true
         },

         default => true // action is not dependend on tournament state, allow
      };
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
         case TournamentAction::ManageSetup:
         case TournamentAction::ManageParticipants:
         case TournamentAction::TransitionState:
            /* tournament specific actions: allowed if tournament ownership, TODO */
            return true;

         case TournamentAction::BrowseTournament:
         case TournamentAction::RecordResults:
            /* allowed if tournament ownership OR assigned area device account, TODO */
            return true;

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
}
