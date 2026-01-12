<?php

namespace Tournament\Model\Participant;

use Tournament\Model\Category\Category;

class CategoryAssignmentCollection extends \Base\Model\IdObjectCollection
{
   static protected function elements_type(): string
   {
      return CategoryAssignment::class;
   }

   static protected function get_id($value): mixed
   {
      return $value->category->id;
   }

   public function offsetSet($offset, $value): void
   {
      if( $value instanceof Category )
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
}