<?php

namespace Tournament\Model\Data;

class CategoryCollection extends \Base\Model\IdObjectCollection
{
   protected static function elements_type(): string
   {
      return Category::class;
   }
}
