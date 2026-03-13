<?php

namespace Tournament\Twig;

use Twig\TwigFilter;
use Carbon\Carbon;

class TwigExtensions extends \Twig\Extension\AbstractExtension
{
   public function getFilters(): array
   {
      return [
         new TwigFilter('humanize', [$this, 'humanize']),
         new TwigFilter('time_delta', [$this, 'timeDelta']),
         new TwigFilter('split_code', [$this, 'splitCode']),
         new TwigFilter('wrap_if', [$this, 'wrapIf'], ['is_safe' => ['html']]),
      ];
   }

   public function getFunctions(): array
   {
      return [
         new \Twig\TwigFunction('enum_options', [$this, 'enumOptions']),
      ];
   }

   public function getTests(): array
   {
      return [
         new \Twig\TwigTest('instanceof', [$this, 'hasClassName'])
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

   public function hasClassName($obj, $className): bool
   {
      return (new \ReflectionClass($obj))->getShortName() === $className;
   }

   public function wrapIf(string $value, bool $condition, string $tag, array $attr = []): string
   {
      if (!$condition) return $value;
      $attrString = '';
      if( $attr )
      {
         $attrString = ' ' . implode(' ', array_map(fn($k) => sprintf('%s="%s"', $k, htmlspecialchars($attr[$k], ENT_QUOTES)), array_keys($attr)));
      }
      return new \Twig\Markup( sprintf('<%s%s>%s</%s>', $tag, $attrString, $value, $tag), 'UTF-8');
   }
}
