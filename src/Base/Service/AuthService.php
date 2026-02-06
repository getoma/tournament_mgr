<?php

namespace Base\Service;

use Base\Repository\UserRepository;
use Base\Service\PasswordHasher;
use Base\Model\User;

/**
 * This class manages user authorization and the user session
 * - handles login/logout
 * - keeps track of the currently logged in user via the session
 */
class AuthService
{
   /** buffer currently logged in user */
   private ?User $user = null;

   private int $login_issue = 0;
   public const NO_ISSUE          =  0;
   public const USER_NOT_FOUND    = -1;
   public const PASSWORD_MISMATCH = -2;
   public const NO_PASSWORD_SET   = -3;
   public const USER_DISABLED     = -5;

   /**
    * Constructor for AuthService
    * @param UserRepository $repo
    * @param PasswordHasher $hasher
    */
   public function __construct(private UserRepository $repo, private PasswordHasher $hasher, private SessionService $session)
   {
   }

   /** AuthService provides detailled feedback why login failed, for debugging issues
    *  Of course, in a productive environment no specific information should be provided
    *  on whether username or password is the issue.
    *  (only exception: "user disabled" can be forwarded, and will only be given if the still password matched)
    */
   public function getLoginIssue(): int
   {
      return $this->login_issue;
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
      $user = $this->repo->findUser(['email' => $email]);
      if( !$user )
      {
         $this->login_issue = self::USER_NOT_FOUND;
      }
      else if( empty($user->password_hash) )
      {
         $this->login_issue = self::NO_PASSWORD_SET;
      }
      else if( !$this->hasher->verify($password, $user->password_hash) )
      {
         $this->login_issue = self::PASSWORD_MISMATCH;
      }
      else if( !$user->is_active )
      {
         $this->login_issue = self::USER_DISABLED;
      }
      else
      {
         $this->login_issue = self::NO_ISSUE;

         // login successful, store user in session
         $this->repo->setSessionUserId($user->id);
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
      $this->session->clear();
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
         try
         {
            $this->user = $this->repo->getSessionUser();
         }
         catch( \Exception $e )
         {
            // session user could not be retrieved, log an error
            error_log("Session user could not be retrieved: " . $e->getMessage());
         }
      }
      return $this->user;
   }
}
