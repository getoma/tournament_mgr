<?php

namespace Tournament\Model\User;

class RoleCollection extends \Base\Model\BackedEnumCollection
{
   protected const ELEMENTS_TYPE = Role::class;
}