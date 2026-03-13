<?php

namespace Tournament\Model\Participant;

use Tournament\Model\Category\Category;

class CategoryAssignment
{
   public int $categoryId;

   public function __construct(
      Category|int $categoryId,
      public ?string $pre_assign = null,
      public ?string $slot_name = null,
   )
   {
      $this->categoryId = is_int($categoryId)? $categoryId : $categoryId->id;
   }
}