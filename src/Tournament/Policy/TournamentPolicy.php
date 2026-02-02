<?php

namespace Tournament\Policy;

use Tournament\Model\Tournament\TournamentStatus;
use Tournament\Model\User\AuthContext;
use Tournament\Model\User\Role;
use Tournament\Model\User\RoleCollection;
use Tournament\Model\User\User;

/**
 * class to define which actions are allowed in a specific authorization context
 */
final class TournamentPolicy
{
   function __construct(public readonly AuthContext $context)
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
      return $this->checkAuthorizationPolicy($action) &&
             ($action->isGeneralAction() || $this->checkStatePolicy($action));
   }

   /**
    * Check if a specific action is allowed for a specific tournament status
    */
   private function checkStatePolicy(TournamentAction $action): bool
   {
      if( !$this->context->tournament ) return false;
      return match ($this->context->tournament->status)
      {
         TournamentStatus::Planning => match ($action)
         {
            TournamentAction::ManageDetails      => true,
            TournamentAction::ManageSetup        => true,
            TournamentAction::ManageParticipants => true,
            TournamentAction::TransitionState    => true,
            default => false,
         },
         TournamentStatus::Planned => match ($action)
         {
            TournamentAction::ManageDetails      => true,
            TournamentAction::ManageParticipants => true,
            TournamentAction::TransitionState    => true,
            default => false,
         },
         TournamentStatus::Running => match ($action)
         {
            TournamentAction::ManageDetails      => true,
            TournamentAction::ManageParticipants => true,
            TournamentAction::RecordResults      => true,
            TournamentAction::TransitionState    => true,
            default => false,
         },
         default => false,
      };
   }

   /**
    * Check if a specific action is allowed according current authorization
    */
   private function checkAuthorizationPolicy(TournamentAction $action): bool
   {
      if ($this->context->hasRole(Role::ADMIN)) return true; // no restrictions for admins

      // determine authorization status depending on the specific action
      switch( $action )
      {
         case TournamentAction::ManageDetails:
         case TournamentAction::ManageSetup:
         case TournamentAction::ManageParticipants:
         case TournamentAction::TransitionState:
            /* tournament specific actions: allowed if any tournament ownership, TODO */
            return true;

         case TournamentAction::RecordResults:
            /* result recording allowed if tournament ownership OR area device account, TODO */
            return true;

         case TournamentAction::BrowseTournaments:
            /* only users mapped to a specific tournament should be able to see it (at least for now), TODO */
            return true;

         case TournamentAction::CreateTournaments:
            return $this->context->hasRole(Role::ORGANIZER);

         case TournamentAction::ManageUsers:
            return $this->context->hasRole(Role::USER_MANAGER);

         case TournamentAction::ManageAccount:
            return $this->context->isUser(); // only actual users do have an account (unlike device accounts)

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
      if ($this->context->hasRole(Role::ADMIN)) return true; // no restrictions for admins
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

      if ($this->context->hasRole(Role::ADMIN)) return true; // no restrictions for admins
      if (!$this->_canManageUser($target)) return false;     // common checks
      return !(in_array($role, self::PROTECTED_ROLES));      // non-admins may not elevate or degrade other users
   }

   /**
    * common checks whether user management is allowed
    */
   private function _canManageUser(User $target): bool
   {
      if ($this->context->user->id == $target->id) return false;      // no self-management
      if (!$this->context->hasRole(Role::USER_MANAGER)) return false; // if not user_manager, any user management is forbidden
      if (!$target->roles->intersect(self::PROTECTED_ROLES)->empty()) return false; // may not modify other admins/user_managers
      return true;
   }
}
