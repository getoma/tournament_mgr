<?php

namespace Tournament\Model\Participant;

class CategoryAssignment
{
   function __construct(
      public int $categoryId,
      public ?string $pre_assign = null,
      public ?string $slot_name = null,
   )
   {}
}