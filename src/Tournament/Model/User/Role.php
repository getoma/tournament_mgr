<?php

namespace Tournament\Model\User;

enum Role: string
{
   case ADMIN        = 'admin';        // no restrictions
   case USER_MANAGER = 'user_manager'; // may create/manager application users
   case ORGANIZER    = 'organizer';    // may create tournaments
}