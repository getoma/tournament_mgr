<?php

namespace Tournament\Model\Area;

class AreaCollection extends \Base\Model\IdObjectCollection
{
   protected static function elements_type(): string
   {
      return Area::class;
   }
}
