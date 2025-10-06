<?php

namespace Tournament\Model\TournamentStructure;

use Tournament\Model\TournamentStructure\MatchNode\KoNode;
use Tournament\Model\Area\Area;

class KoChunk
{
   private ?Area   $area;
   private ?string $name;

   function __construct(public readonly KoNode $root, ?string $name = null, ?Area $area = null )
   {
      $this->setName($name);
      $this->setArea($area);
   }

   /**
    * Return a unique name for this match, derived from the cluster id and local id.
    * If no cluster id is set, the local id is returned.
    * @return string
    */
   public function getName(): string
   {
      return $this->name;
   }

   public function setName(string $name): void
   {
      $this->name = $name;

      if( isset($name) )
      {
         $local_match_idx = 1;
         /** @var MatchNode $node */
         foreach ($this->root->getMatchList() as $node)
         {
            $node->name = $name . '-' . $local_match_idx++;
         }
      }
   }

   public function getArea(): ?Area
   {
      return $this->area;
   }

   public function setArea(?Area $area): void
   {
      $this->area = $area;

      if( isset($area) )
      {
         /** @var MatchNode $node */
         foreach ($this->root->getMatchList() as $node)
         {
            $node->area = $area;
         }
      }
   }
}
