<?php

namespace Tournament\Model\User;

use Base\Model\IdObjectCollection;

class UserCollection extends IdObjectCollection
{
   protected static function elements_type(): string
   {
      return User::class;
   }
}
