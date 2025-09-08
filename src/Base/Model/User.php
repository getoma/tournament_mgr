<?php

namespace Base\Model;

class User
{
   public function __construct(
      public int $id,
      public string $email,
      public string $display_name,
      public string $password_hash,
      public bool $admin,
   )
   {}

}
