<?php

namespace Tournament\Twig;

use Tournament\Service\NavigationStructureService;

final class NavigationExtension extends \Twig\Extension\AbstractExtension
{
   public function __construct(private NavigationStructureService $service)
   {
   }

   public function getFunctions(): array
   {
      return [
         new \Twig\TwigFunction('buildNavigation', [$this->service, 'build']),
      ];
   }
}
