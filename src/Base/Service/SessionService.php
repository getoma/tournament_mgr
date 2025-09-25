<?php

namespace Base\Service;

use SessionHandlerInterface;

/**
 * Service to manage user sessions.
 */
class SessionService
{
   private bool $ok = false;

   public function __construct(?SessionHandlerInterface $handler = null)
   {
      if( session_status() === PHP_SESSION_NONE )
      {
         try
         {
            if ($handler)
            {
               session_set_save_handler($handler, true);
            }
            session_start();
         }
         catch( \Exception $e )
         {
            // session could not be started, log an error
            error_log("Session could not be started: " . $e->getMessage());
            return;
         }
      }
      $this->ok = true;
   }

   public function id(): string
   {
      return $this->ok? session_id() : '';
   }

   public function clear(): void
   {
      if( $this->ok ) session_destroy();
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
}