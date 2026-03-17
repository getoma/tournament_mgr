<?php declare(strict_types=1);

namespace Tournament\Model\TournamentStructure;

use OutOfBoundsException;

class AreaMapping
{
   public const POOL = 'pool';
   public const NODE = 'node';

   public function __construct(
      public array $pool_mappings = [],
      public array $node_mappings = []
   )
   {
   }

   public function store($type, $name, $area_id)
   {
      switch($type)
      {
         case static::NODE:
            $this->node_mappings[$name] = $area_id;
            break;

         case static::POOL:
            $this->pool_mappings[$name] = $area_id;
            break;

         default:
            throw new OutOfBoundsException("invalid mapping type $type for $name");
      }
   }
}