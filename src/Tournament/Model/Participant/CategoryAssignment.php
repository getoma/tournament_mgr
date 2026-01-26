<?php

namespace Tournament\Model\Participant;

use Tournament\Model\Category\Category;

class CategoryAssignment
{
   function __construct(
      public Category $category,
      public ?string $pre_assign = null
   )
   {}
}