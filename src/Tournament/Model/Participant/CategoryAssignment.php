<?php

namespace Tournament\Model\Participant;

use Tournament\Model\Category\Category;

class CategoryAssignment
{
   public readonly int $categoryId;
   public ?int $team_id = null;

   public function __construct(
      Category|int $categoryId,
      public ?string $pre_assign = null,
      public ?string $slot_name = null,
      Team|int|null $team_id = null,
   )
   {
      $this->categoryId = is_int($categoryId)? $categoryId : $categoryId->id;
      if( $team_id !== null )
      {
         $this->team_id = is_int($team_id)? $team_id : $team_id->id;
      }
   }
}