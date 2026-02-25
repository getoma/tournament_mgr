<?php

namespace Tournament\Model\AreaDevices;

class AreaDeviceLoginCode
{
   public function __construct(
      public string $code,
      public readonly int $area_id,
      public readonly \DateTimeImmutable $created_at,
      public readonly \DateTimeImmutable $expires_at,
      public ?\DateTimeInterface $used_at = null,
      public ?\DateTimeInterface $invalidated_at = null,
      public ?int $id = null,
   )
   {
   }

   public function isActive(): bool
   {
      return $this->id !== null
      && $this->used_at === null
      && $this->invalidated_at === null
      && $this->expires_at > new \DateTime();
   }
}