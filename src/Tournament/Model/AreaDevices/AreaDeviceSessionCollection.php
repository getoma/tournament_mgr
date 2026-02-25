<?php

namespace Tournament\Model\AreaDevices;

class AreaDeviceSessionCollection extends \Base\Model\IdObjectCollection
{
	static protected function elements_type(): string
	{
		return \Tournament\Model\AreaDevices\AreaDeviceSession::class;
	}

   public function latestForArea(int $area_id): AreaDeviceLoginCode
   {
      $filtered = $this->filter(fn($e) => $e->area_id == $area_id)->values();
      usort($filtered, fn($a, $b) => $b->id <=> $a->id);
      return array_first($filtered);
   }
}