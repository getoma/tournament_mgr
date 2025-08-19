<?php

namespace App\Service;

/**
 * dedicated class to handle password hashing
 * in a centralized location to allow easy re-configurations
 */
class PasswordHasher
{
   public function hash(string $plain): string
   {
      return password_hash($plain, PASSWORD_DEFAULT);
   }

   public function verify(string $plain, string $hash): bool
   {
      return password_verify($plain, $hash);
   }

   public function needsRehash(string $hash): bool
   {
      return password_needs_rehash($hash, PASSWORD_DEFAULT);
   }
}
