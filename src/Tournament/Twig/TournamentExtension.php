<?php

namespace Tournament\Twig;

use Tournament\Model\TournamentStructure\TournamentStructure;

class TournamentExtension extends \Twig\Extension\AbstractExtension
{
   public function getFilters(): array
   {
      return [
         new \Twig\TwigFilter('humanizeSlotName', [$this, 'humanizeSlotName']),
      ];
   }

   public function getFunctions(): array
   {
      return [
         new \Twig\TwigFunction('getKoNodeNameFromSlotName', [$this, 'getKoNodeNameFromSlotName']),
         new \Twig\TwigFunction('getPoolIdFromSlotName',     [$this, 'getPoolIdFromSlotName']),
      ];
   }

   public function getKoNodeNameFromSlotName(string $slotName): string
   {
      return TournamentStructure::getKoNodeNameFromSlotName($slotName);
   }

   public function getPoolIdFromSlotName(string $slotName): string
   {
      return TournamentStructure::getPoolIdFromSlotName($slotName);
   }

   public function humanizeSlotName(?string $slotName): string
   {
      /* accept empty input for a filter, just keep output empty as well */
      if(!$slotName) return '';

      /* slot names are only valid within their domain, but we do not know its domain easily at this point
       * we just try them one-by-one until the slot name is accepted by the corresponding resolver
       */
      $attempts = [
         'Kampf' => [TournamentStructure::class, 'getKoNodeNameFromSlotName'],
         'Pool'  => [TournamentStructure::class, 'getPoolIdFromSlotName'],
      ];

      foreach( $attempts as $prefix => $call )
      {
         $resolved = $call($slotName, false);
         if( isset($resolved) ) return "$prefix $resolved";
      }
      throw new \InvalidArgumentException("'$slotName' is not a valid slot name");
   }
}