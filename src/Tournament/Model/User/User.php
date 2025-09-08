<?php

namespace Tournament\Model\User;

class User extends \Base\Model\User
{
   public function __construct(
      int $id,
      string $email,
      string $password_hash,
      public string $display_name,
      public bool $admin,
   )
   {
      parent::__construct($id, $email, $password_hash);
   }
}
