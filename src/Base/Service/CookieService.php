<?php

namespace Base\Service;

/**
 * service to centralize the default configuration for cookies
 */
class CookieService
{
   protected const DEFAULT_COOKIE_OPTIONS = [
      'secure'   => true,
      'httponly' => true,
      'samesite' => 'Strict'
   ];

   public readonly array $cookie_options;

   public function __construct( array $cookie_options = [] )
   {
      $this->cookie_options = $cookie_options + static::DEFAULT_COOKIE_OPTIONS;
   }

   public function setCookie(string $name, string $value, array $options = []): void
   {
      $options += $this->cookie_options;
      setcookie($name, $value, $options);
   }

   public function deleteCookie(string $name, array $options = []): void
   {
      if(isset($_COOKIE[$name]) )
      {
         $options += $this->cookie_options;
         setcookie($name, '', array_merge($options, ['expires' => time() - 3600]));
         unset($_COOKIE[$name]);
      }
   }

   public function hasCookie(string $name): bool
   {
      return isset($_COOKIE[$name]);
   }

   public function getCookie(string $name, mixed $default = null): mixed
   {
      return $_COOKIE[$name] ?? $default;
   }
}