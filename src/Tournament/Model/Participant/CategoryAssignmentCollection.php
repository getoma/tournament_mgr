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

   public function offsetSet($offset, $value): void
   {
      if( $value instanceof Category )
      {
         $value = new CategoryAssignment($value->id);
      }
      else if( is_numeric($value) )
      {
         $value = new CategoryAssignment($value);
      }
      parent::offsetSet($offset, $value);
   }

   public function search($value): mixed
   {
      $value_id = static::get_id($value) ?? spl_object_hash($value);
      $found = $this->elements[$id = $value_id] ?? $this->elements[$id = spl_object_hash($value)] ?? null;
      return $value == $found->category ? $id : false;
   }

   public function updateFromArray(array $category_id_list)
   {
      // categories: drop any no longer provided
      $this->elements = array_filter( $this->elements, fn($ca) => in_array($ca->categoryId, $category_id_list));
      // add any new category assignment
      foreach ($category_id_list as $catId)
      {
         $this->elements[$catId] ??= new CategoryAssignment($catId);
      }
   }
}