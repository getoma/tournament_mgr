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

   /* turn code-like strings into "nicer" text */
   public function humanize(string $value): string
   {
      return ucwords(str_replace('_', ' ', strtolower($value)));
   }
}
