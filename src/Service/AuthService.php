<?php

namespace App\Service;

use App\Repository\UserRepository;
use App\Service\PasswordHasher;
use App\Model\User\User;

/**
 * This class manages user authorization and the user session
 * - handles login/logout
 * - keeps track of the currently logged in user via $_SESSION
 */
class AuthService
{
   /** buffer currently logged in user */
   private ?User $user = null;

   /**
    * Constructor for AuthService
    * @param UserRepository $repo
    * @param PasswordHasher $hasher
    */
   public function __construct(private UserRepository $repo, private PasswordHasher $hasher)
   {
   }

   /**
    * Attempts to log in a user with the provided username and password.
    * If successful, stores the user ID in the session.
    * @param string $username
    * @param string $password
    * @return bool Returns true if login is successful, false otherwise.
    */
   public function login(string $email, string $password): bool
   {
      $user = $this->repo->findByEmail($email);
      if ($user && !empty($user->password_hash) && $this->hasher->verify($password, $user->password_hash))
      {
         // login successful, store user in session
         $this->repo->setSessionUserId($user->id, session_id());
         $this->user = $user;

         // check for the need to rehash the password now that we have the unencrypted password
         // and actually CAN re-hash it.
         if ($this->hasher->needsRehash($user->password_hash))
         {
            $password_hash = $this->hasher->hash($password);
            $this->repo->updateUserPassword($user->id, $password_hash);
         }

         // done
         return true;
      }
      return false;
   }

   /**
    * Logs out the current user by destroying the session.
    */
   public function logout(): void
   {
      // destroy current session
      session_destroy();
      // also destroy all other sessions for this user
      $this->repo->destroySessionsForUser($this->user->id);
      // clear buffer
      $this->user = null;
   }

   /**
    * check whether there is currently any user authenticated
    * @return bool
    */
   public function isAuthenticated(): bool
   {
      return $this->getCurrentUser() !== null;
   }

   /**
    * Returns the currently logged-in user, or null if no user is logged in.
    * @return User|null
    */
   public function getCurrentUser(): ?User
   {
      if( $this->user === null )
      {
         $this->user = $this->repo->getSessionUser();
      }
      return $this->user;
   }
}
