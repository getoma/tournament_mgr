<?php

namespace Tests;

class CallSpy
{
   private array $calls = [];
   private array $returns = [];

   public function callback(string $method)
   {
      return function(...$args) use ($method)
      {
         return $this->record($method, $args);
      };
   }

   public function record(string $method, array $args)
   {
      $this->calls[] = [ 'method' => $method, 'args'   => $args, ];
      return array_shift($this->returns) ?? null;
   }

   public function addReturn($value)
   {
      $this->returns[] = $value;
   }

   public function calls(): array
   {
      return $this->calls;
   }

   public function callsOf(string $method): array
   {
      return array_values( array_filter( $this->calls, fn($call) => $call['method'] === $method ) );
   }

   public function count(?string $method = null): int
   {
      if ($method === null)
      {
         return count($this->calls);
      }

      return count($this->callsOf($method));
   }

   public function clear()
   {
      $this->calls = [];
   }
}
