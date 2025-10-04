<?php

namespace Tournament\Twig;

class EnumOptionsExtension extends \Twig\Extension\AbstractExtension
{
   public function getFunctions(): array
   {
      return [
         new \Twig\TwigFunction('enum_options', [$this, 'enumOptions']),
      ];
   }

   public function enumOptions(array $cases): array
   {
      $result = [];
      foreach ($cases as $case)
      {
         $result[$case->value] = ucwords($case->value);
      }
      return $result;
   }
}
