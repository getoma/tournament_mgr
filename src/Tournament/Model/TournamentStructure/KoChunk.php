<?php

namespace Tournament\Model\TournamentStructure;

use Tournament\Model\TournamentStructure\KoNode;
use Tournament\Model\Data\Area;

class KoChunk
{
   public  ?KoNode $root = null;
   private ?string $name = null;

   function __construct(KoNode $root, ?string $name = null, ?Area $area = null)
   {
      $this->root = $root;
      if( $name ) $this->setName($name);
      if( $area ) $this->setArea($area);
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

      $local_match_idx = 1;
      /** @var MatchNode $node */
      foreach (array_merge(...$this->root->getRounds()) as $node)
      {
         $node->name = $name . '-' . $local_match_idx++;
      }
   }

   public function getArea(): ?Area
   {
      return $this->root->area;
   }

   public function setArea(?Area $area): void
   {
      /** @var MatchNode $node */
      foreach (array_merge(...$this->root->getRounds()) as $node)
      {
         $node->area = $area;
      }
   }
}
