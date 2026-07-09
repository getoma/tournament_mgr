<?php declare(strict_types=1);

namespace Tournament\Model\PrintPage;

class PrintPage
{
   public function __construct(
      public string $template,
      public array $data,

      public PrintPageSetup $setup,

      public string $headerTitle = '',
      public ?int $pageNumber = null
   )
   {
   }
}
