<?php

namespace Tournament\Model\User;

final class AuthContext
{
   private function __construct(
      private readonly ?AuthType $authtype = null,
      public readonly ?User $user = null,
   )
   {}

   public function isAuthenticated(): bool
   {
      return isset($this->authtype);
   }

   public function isDevice(): bool
   {
      return $this->authtype === AuthType::DEVICE;
   }

   public function isUser(): bool
   {
      return $this->authtype === AuthType::USER;
   }

   public function hasRole(Role $role): bool
   {
      return $this->user?->hasRole($role) ?? false;
   }

   /**
    * Factory methods to create AuthContext for various user types
    */

   /* no authentication */
   static public function as_anonymous(): static
   {
      return new static();
   }

   /* an actual user */
   static public function as_user(User $user): static
   {
      return new static(authtype: AuthType::USER, user: $user);
   }

   /* device account */
   static public function as_device(): static
   {
      return new static(authtype: AuthType::DEVICE);
   }
}