<?php

namespace Base\Service;

use SessionHandlerInterface;

class SessionValidationIssue extends \RuntimeException
{
   public function __construct(string $message = "", public bool $critical = true)
   {
      parent::__construct($message);
   }
}

/**
 * Service to manage user sessions.
 */
class SessionService
{
   private static bool $handler_set = false;

   protected const TOKEN_COOKIE_NAME = 'client_session_token';

   protected const DEFAULT_COOKIE_OPTIONS = [
      'secure'   => true,
      'httponly' => false,
      'samesite' => 'Lax'
   ];

   protected const KEY_EXPIRES         = '_session.EXPIRES';
   protected const KEY_LIFETIME        = '_session.LIFETIME';
   protected const KEY_IP_ADDRESS      = '_session.IP_ADDRESS';
   protected const KEY_USER_AGENT_HASH = '_session.USER_AGENT_HASH';
   protected const KEY_TOKEN_HASH      = '_session.CLIENT_TOKEN_HASH';
   protected const KEY_LAST_ROTATION   = '_session.LAST_ROTATION';

   private readonly array $cookie_options;

   public function __construct(
      ?SessionHandlerInterface $handler = null,
      array $cookie_options = [],
      private bool $strict_session_validation = false,
      private int $rotation_interval_s = 3600, // once per hour
   )
   {
      $this->cookie_options = $cookie_options + static::DEFAULT_COOKIE_OPTIONS;

      ini_set('session.use_strict_mode', 1);
      ini_set('session.use_only_cookies', 1);
      session_set_cookie_params($this->cookie_options);

      if( !self::$handler_set )
      {
         session_set_save_handler($handler, true);
         self::$handler_set = true;
      }

      /* automatically create a session whenever this service is used */
      if( !$this->sessionActive() ) session_start();
   }

   /**
    * check whether there is currently a session ongoing
    */
   public function sessionActive(): bool
   {
      return session_status() === PHP_SESSION_ACTIVE;
   }

   /**
    * regenerate and establish the current session
    * inject all meta data in this session to make this a validatable and rotatable session
    */
   public function regenerateSession(?int $lifetime_s = null, bool $set_token = false, bool $keepAlive = false, bool $reload = false): void
   {
      if( $this->sessionActive() )
      {
         /* set previous session to obsolete and set expiry, but only bother if there is any content to begin with */
         if( !$this->sessionEmpty() ) $this->set(static::KEY_EXPIRES, time()+60); // +1min

         /* take over the lifetime if needed */
         if ($lifetime_s === null && $this->has(static::KEY_LIFETIME))
         {
            $lifetime_s = $this->get(static::KEY_LIFETIME);
            $keepAlive  = true; // this value is only set if keepAlive was requested previously
         }

         /* generate a new session id, but keep the previous one */
         session_regenerate_id(false);

         $newSession = session_id();
         session_write_close();
         session_id($newSession);
      }

      session_set_cookie_params([ 'lifetime' => $lifetime_s ] + $this->cookie_options);
      session_start();

      /* store back ip address and user agent hash for validation purposes */
      if (!$this->has(static::KEY_IP_ADDRESS)      || $reload) $this->set(static::KEY_IP_ADDRESS, $_SERVER['REMOTE_ADDR']);
      if (!$this->has(static::KEY_USER_AGENT_HASH) || $reload) $this->set(static::KEY_USER_AGENT_HASH, hash('sha256', $_SERVER['HTTP_USER_AGENT']));

      /* set a client token in a separate cookie as additional session hijacking protection */
      if( $set_token && (!$this->has(static::KEY_TOKEN_HASH) || $reload) )
      {
         /* create the device token for additional security */
         $deviceToken = bin2hex(random_bytes(32));
         $tokenHash = hash('sha256', $deviceToken);

         /* set the device tooken as separate cookie on client side */
         setcookie(
            static::TOKEN_COOKIE_NAME,
            $deviceToken,
            [ 'expires'  => $lifetime_s ? time() + $lifetime_s : 0 ] + $this->cookie_options
         );

         /* store the hash in the session */
         $this->set(static::KEY_TOKEN_HASH, $tokenHash);
      }

      /* store the lifetime if keep alive is selected, to extend it on each regeneration */
      if( $keepAlive )
      {
         $this->set(static::KEY_LIFETIME, $lifetime_s);
      }

      /* set the last rotation time */
      $this->set(static::KEY_LAST_ROTATION, time());

      /* remove the expiry from the new session */
      $this->remove(static::KEY_EXPIRES);
   }

   public function validateSession(): void
   {
      if( !$this->sessionActive() ) return;

      try
      {
         if ($this->has(static::KEY_EXPIRES) && $this->get(static::KEY_EXPIRES) < time())
         {
            throw new SessionValidationIssue('attempt to use expired session');
         }

         if ($this->has(static::KEY_TOKEN_HASH))
         {
            if( !isset($_COOKIE[static::TOKEN_COOKIE_NAME]) )
            {
               throw new SessionValidationIssue('no client token provided');
            }
            $tokenHash = hash('sha256', $_COOKIE[static::TOKEN_COOKIE_NAME]);
            if( $tokenHash !== $this->get(static::KEY_TOKEN_HASH) )
            {
               throw new SessionValidationIssue('client token mismatch detected');
            }
         }

         if ($this->has(static::KEY_IP_ADDRESS) && $this->get(static::KEY_IP_ADDRESS) !== $_SERVER['REMOTE_ADDR'])
         {
            throw new SessionValidationIssue("ip-address mismatch detected - {$this->get(static::KEY_IP_ADDRESS)} vs {$_SERVER['REMOTE_ADDR']}", false);
         }

         if ($this->has(static::KEY_USER_AGENT_HASH))
         {
            $user_agent_hash = hash('sha256', $_SERVER['HTTP_USER_AGENT']);
            if ($this->get(static::KEY_USER_AGENT_HASH) !== $user_agent_hash)
            {
               throw new SessionValidationIssue('user agent mismatch detected', false);
            }
         }

         /* cyclically regenerate the session */
         if (   $this->has(static::KEY_LAST_ROTATION) // only if last rotation set at all (i.e. it is an actual, established user session)
            && !$this->has(static::KEY_EXPIRES)       // and this is not an obsolete session
            &&  $this->get(static::KEY_LAST_ROTATION) + $this->rotation_interval_s < time() // and it is about time
         )
         {
            $this->regenerateSession();
         }
      }
      catch( SessionValidationIssue $e )
      {
         error_log("session: " . $e->getMessage());
         if( $e->critical || $this->strict_session_validation )
         {
            $this->clear();
         }
         else
         {
            $this->regenerateSession(reload: true);
         }
      }
   }

   public function id(): string
   {
      return $this->sessionActive()? session_id() : '';
   }

   public function clear(): void
   {
      if( $this->sessionActive() )
      {
         $_SESSION = [];
         session_destroy();
         setcookie(session_name(), '', time() - 42000);
      }
   }

   public function get(string $key, mixed $default = null): mixed
   {
      return $_SESSION[$key] ?? $default;
   }

   public function set(string $key, mixed $value): void
   {
      $_SESSION[$key] = $value;
   }

   public function remove(string $key): void
   {
      unset($_SESSION[$key]);
   }

   public function has(string $key): bool
   {
      return isset($_SESSION[$key]);
   }

   public function sessionEmpty(): bool
   {
      return count($_SESSION) === 0;
   }
}