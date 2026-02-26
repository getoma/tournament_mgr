<?php

namespace Base\Model;

class User
{
   public function __construct(
      public ?int $id,
      public string $email,
      public string $password_hash = '',
      public bool $is_active = true,
      public int $session_version = 1
   )
   {}

}
