<?php declare(strict_types=1);

namespace Tournament\Model\PrintPage;

class PrintPageSetup
{
   public function __construct(
      public string $paperSize,
      public string $orientation
   )
   {
   }
}
