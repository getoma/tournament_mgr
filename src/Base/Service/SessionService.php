<?php

namespace Base\Service;

use SessionHandlerInterface;

/**
 * Service to manage user sessions.
 */
class SessionService
{
   public function __construct(?SessionHandlerInterface $handler = null)
   {
      if( session_status() === PHP_SESSION_NONE )
      {
         if ($handler)
         {
            session_set_save_handler($handler, true);
         }
         session_start();
      }
   }

   public function id(): string
   {
      return session_id();
   }

   public function clear(): void
   {
      session_destroy();
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