<?php

namespace Tournament\Twig;

use Twig\TwigFilter;

class DebugExtension extends \Twig\Extension\AbstractExtension
{
   public function getFilters(): array
   {
      return [
         new TwigFilter('print_type', [$this, 'printType']),
      ];
   }

   public function printType($value): string
   {
      $result = is_object($value) ? get_class($value) : gettype($value);
      if( is_countable($value) ) $result .= " (" . count($value) . " entries)";
      return $result;
   }
}
