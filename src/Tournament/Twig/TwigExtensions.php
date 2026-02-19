<?php

namespace Tournament\Twig;

use Twig\TwigFilter;

class TwigExtensions extends \Twig\Extension\AbstractExtension
{
   public function getFilters(): array
   {
      return [
         new TwigFilter('humanize', [$this, 'humanize']),
      ];
   }

   public function getFunctions(): array
   {
      return [
         new \Twig\TwigFunction('enum_options', [$this, 'enumOptions']),
      ];
   }

   /* turn code-like strings into "nicer" text */
   public function humanize(string $value): string
   {
      return ucwords(str_replace('_', ' ', strtolower($value)));
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
