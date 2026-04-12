<?php

namespace Tournament\Model\Participant;

use Tournament\Model\Category\Category;

class CategoryAssignmentCollection extends \Base\Model\IdObjectCollection
{
   protected const DEFAULT_ELEMENTS_TYPE = CategoryAssignment::class;

   static protected function get_id($value): mixed
   {
      return $value->categoryId;
   }

   /**
    * add a new category assignment if not existing, yet.
    * @return CategoryAssignment - the created assignment if newly added, or the pre-existing assignment for this category
    */
   public function emplace(Category|int $category): CategoryAssignment
   {
      $id = is_int($category) ? $category : $category->id;
      return $this[$id] ??= new CategoryAssignment($id);
   }

   public function search($value): mixed
   {
      $value_id = static::get_id($value) ?? spl_object_hash($value);
      $found = $this->elements[$id = $value_id] ?? $this->elements[$id = spl_object_hash($value)] ?? null;
      return $value == $found->category ? $id : false;
   }
}