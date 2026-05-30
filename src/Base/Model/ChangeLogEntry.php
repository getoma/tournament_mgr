<?php declare(strict_types=1);

namespace Base\Model;

class ChangeLogEntry
{
   public function __construct(
      public ?int $id,
      public string $entity_type,
      public int $entity_id,
      public int $group_id,
      public string $change_type,
      public \DateTime $changed_at = new \DateTime(),
      public ?int $user_id = null,
      public array $details = [],
   )
   {
   }
}