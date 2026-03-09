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

   /** login issue feedback */
   private int $login_issue = 0;
   public const NO_ISSUE          =  0;
   public const USER_NOT_FOUND    = -1;
   public const PASSWORD_MISMATCH = -2;
   public const NO_PASSWORD_SET   = -3;
   public const USER_DISABLED     = -5;

   /** session keys used by this service */
   protected const KEY_SESSION_VERSION = '_auth.SESSION_VERSION';
   protected const KEY_USER_ID         = '_auth.USER_ID';

   /** remember-me cookie */
   public const TOKEN_COOKIE_NAME = 'remember_me_token';

   /**
    * Constructor for AuthService
    * @param UserRepository $repo
    * @param PasswordHasher $hasher
    */
   public function __construct(
      private UserRepository $repo,
      private PasswordHasher $hasher,
      private SessionService $session,
      private CookieService  $cookieService,
      private int $remember_me_time_hrs = 7*24 // 1wk
   )
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
   public function login(string $email, string $password, bool $keepLoggedIn = false): bool
   {
      $user = $this->repo->findUser(['email' => $email]);
      if( !$user )
      {
         $this->login_issue = self::USER_NOT_FOUND;
      }
      else
      {
         $password_hash = $this->repo->getUserPassword($user->id);
         if( empty($password_hash) )
         {
            $this->login_issue = self::NO_PASSWORD_SET;
         }
         else if( !$this->hasher->verify($password, $password_hash) )
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

            $this->loginUser($user);

            // check for the need to rehash the password now that we have the unencrypted password
            // and actually CAN re-hash it.
            if ($this->hasher->needsRehash($password_hash))
            {
               $password_hash = $this->hasher->hash($password);
               $this->repo->updateUserPassword($user->id, $password_hash);
            }

            if( $keepLoggedIn )
            {
               $this->setRememberMeToken();
            }
            else
            {
               $this->repo->clearUserRememberMeToken($user->id);
            }

            // done
            return true;
         }
      }
      return false;
   }

   /**
    * Logs out the current user by destroying the session.
    */
   public function logout(): void
   {
      // invalidate all user sessions
      $this->repo->rotateUserSession($this->user->id);
      // destroy current session
      $this->destroySessionData();
   }

   /**
    * destroy this specific session, without invalidating other (valid) sessions
    */
   private function destroySessionData(): void
   {
      // invalidate remember me
      if( $this->user ) $this->repo->clearUserRememberMeToken($this->user->id);
      $this->cookieService->deleteCookie(static::TOKEN_COOKIE_NAME);
      // clear buffer
      $this->user = null;
      // destroy current session data
      $this->session->clear();
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
         $this->validateSession() || $this->validateRememberMe();
      }
      return $this->user;
   }

   /**
    * validate whether the current session is logged in for a user
    */
   private function validateSession(): bool
   {
      if ($this->session->sessionActive() && $this->session->has(static::KEY_USER_ID))
      {
         try
         {
            $this->user = $this->repo->findUser(['id' => $this->session->get(static::KEY_USER_ID), 'is_active' => true]);
         }
         catch (\Exception $e)
         {
            // session user could not be retrieved, log an error
            error_log("Session user could not be retrieved: " . $e->getMessage());
         }

         /* validate the session version */
         if ($this->user && $this->user->session_version === $this->session->get(static::KEY_SESSION_VERSION))
         {
            return true;
         }
         else
         {
            /* it does not fit - destroy this session and throw session validation issue */
            $this->destroySessionData();
            throw new SessionValidationIssue('session expired');
         }
      }
      return false;
   }

   /**
    * rotate the session version - this will invalidate any active logins in other sessions
    * keep the current session active, though
    */
   public function rotateSessionVersion(): void
   {
      $user = $this->getCurrentUser();
      $new_session_version = $this->repo->rotateUserSession($user->id);
      $user->session_version = $new_session_version;
      $this->session->set(static::KEY_SESSION_VERSION, $new_session_version);
      if( $this->cookieService->hasCookie(static::TOKEN_COOKIE_NAME) )
      {
         $this->setRememberMeToken();
      }
   }

   /**
    * set up a session with a logged in user
    */
   private function loginUser(User $user)
   {
      // regenerate the session
      $this->session->regenerateSession(set_token: true);

      // store user in session
      $this->session->set(static::KEY_USER_ID, $user->id);
      $this->session->set(static::KEY_SESSION_VERSION, $user->session_version);
      $this->user = $user;
   }

   /**
    * (re)set any "remember me" token
    */
   private function setRememberMeToken(): void
   {
      /* create the device token for additional security */
      $token = bin2hex(random_bytes(32));
      $tokenHash = hash('sha256', $token);

      /* set it in the instance */
      if( $this->repo->updateUserRememberMeToken($this->getCurrentUser()->id, $tokenHash) )
      {
         /* set the device tooken as separate cookie on client side */
         $this->cookieService->setCookie(
            static::TOKEN_COOKIE_NAME, $token,
            ['expires'  => time() + $this->remember_me_time_hrs*3600]
         );
      }
   }

   /**
    * validate any remember me token
    */
   private function validateRememberMe(): bool
   {
      if( $this->cookieService->hasCookie(static::TOKEN_COOKIE_NAME) )
      {
         $token = $this->cookieService->getCookie(static::TOKEN_COOKIE_NAME);
         $tokenHash = hash('sha256', $token);
         $user = $this->repo->findUser(['remember_me_token' => $tokenHash, 'is_active' => true]);
         if( $user )
         {
            // perform login
            $this->loginUser($user);
            // token is valid, refresh it
            $this->setRememberMeToken();
            // done
            return true;
         }
         else
         {
            $this->cookieService->deleteCookie(static::TOKEN_COOKIE_NAME);
         }
      }
      return false;
   }
}
