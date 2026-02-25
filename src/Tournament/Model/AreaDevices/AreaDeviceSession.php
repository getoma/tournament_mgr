<?php

namespace Tournament\Model\AreaDevices;

class AreaDeviceSession
{
   public function __construct(
      public readonly int $area_id,
      public readonly \DateTimeImmutable $created_at,
      public readonly \DateTimeImmutable $expires_at,
      public ?\DateTimeInterface $invalidated_at = null,
      public \DateTimeInterface $last_activity_at,
      public string $last_php_session_id,
      public ?int $id = null,
   )
   {
   }

   public function isActive(): bool
   {
      return $this->id !== null
         && $this->invalidated_at === null
         && $this->expires_at > new \DateTime();
   }
}