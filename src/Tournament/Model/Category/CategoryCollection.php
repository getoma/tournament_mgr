<?php

namespace Tournament\Model\Category;

class CategoryCollection extends \Base\Model\IdObjectCollection
{
   protected static function elements_type(): string
   {
      return Category::class;
   }
}
