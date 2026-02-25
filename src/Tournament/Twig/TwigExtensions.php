<?php

namespace Tournament\Twig;

use Twig\TwigFilter;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class TwigExtensions extends \Twig\Extension\AbstractExtension
{
   public function getFilters(): array
   {
      return [
         new TwigFilter('humanize', [$this, 'humanize']),
         new TwigFilter('time_delta', [$this, 'timeDelta']),
         new TwigFilter('split_code', [$this, 'splitCode']),
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

   public function timeDelta(\DateTimeInterface $until, array $options = []): string
   {
      $carbon = \Carbon\Carbon::instance($until)->locale('de');

      return $carbon->diffForHumans($options + [
         'parts'   => 2,
         'minimumUnit' => 'min',
         'short'   => true,
         'options' => Carbon::JUST_NOW,
      ]);
   }

   public function splitCode(string $code, int $cluster = 4): string
   {
      return implode('-', str_split($code, $cluster));
   }
}
