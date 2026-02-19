<?php

use PHPUnit\Framework\TestCase;

use Tournament\Model\Area\Area;
use Tournament\Model\Area\AreaCollection;
use Tournament\Model\Category\Category;
use Tournament\Model\Category\CategoryConfiguration;
use Tournament\Model\Category\CategoryMode;
use Tournament\Model\TournamentStructure\TournamentStructure;
use Tournament\Model\TournamentStructure\Pool\Pool;


class TournamentStructureTest extends TestCase
{
   private function areaList($num): AreaCollection
   {
      return new AreaCollection( array_map( fn($i) => new Area($i, 1, "area_".$i), range(1,$num)));
   }

   /**
    * test whether a simple knock-out tree is generated as expected
    */
   public function testBuildKnockOutTree()
   {
      $category = new Category(1, 1, "test", CategoryMode::KO, new CategoryConfiguration(3));
      $structure = new TournamentStructure($category, AreaCollection::new());
      $structure->generateStructure();
      $this->assertNotNull($structure->ko);
      $this->assertEmpty($structure->pools);

      $rounds = $structure->ko->getRounds();
      $this->assertCount(3, $rounds);    // 4 quarter, 2 half, 1 finale
      $this->assertCount(4, $rounds[0]);
      $this->assertCount(2, $rounds[1]);
      $this->assertCount(1, $rounds[2]);
   }

   /**
    * test whether a combined (pool round + KO finals) structure is
    * generated as expected.
    */
   public function testBuildCombined()
   {
      /**
       * test with 3 rounds, and default/null(=2) winners per pool --> 4 pools
       */
      $category = new Category(1, 1, "test", CategoryMode::Combined, new CategoryConfiguration(3, pool_winners: null));
      $structure = new TournamentStructure($category, AreaCollection::new());
      $structure->generateStructure();
      $this->assertNotNull($structure->ko);
      $this->assertNotEmpty($structure->pools);

      $rounds = $structure->ko->getRounds();
      $this->assertCount(3, $rounds);    // 4 quarter, 2 half, 1 finale
      $this->assertCount(4, $rounds[0]);
      $this->assertCount(2, $rounds[1]);
      $this->assertCount(1, $rounds[2]);

      // 3 rounds with 2 winners per pool result into 4 pools
      $this->assertCount(4, $structure->pools);

      /**
       * test with 4 rounds, and 3 winners per pool --> 4 pools
       */
      $category = new Category(1, 1, "test", CategoryMode::Combined, new CategoryConfiguration(4, pool_winners: 3));
      $structure = new TournamentStructure($category, AreaCollection::new());
      $structure->generateStructure();
      $this->assertNotNull($structure->ko);
      $this->assertNotEmpty($structure->pools);

      $rounds = $structure->ko->getRounds();
      $this->assertCount(4, $rounds);
      $this->assertCount(8, $rounds[0]);
      $this->assertCount(4, $rounds[1]);
      $this->assertCount(2, $rounds[2]);
      $this->assertCount(1, $rounds[3]);

      // 3 rounds with 2 winners per pool result into 4 pools
      $this->assertCount(4, $structure->pools);
   }

   /**
    * test whether areas are assigned as expected
    */
   public function testAreaAssignment()
   {
      foreach( [1,2,4] as $numAreas )
      {
         $areas = $this->areaList($numAreas);
         $areas_i = $areas->values();
         $category = new Category(1, 1, "test", CategoryMode::Combined, new CategoryConfiguration(3));
         $structure = new TournamentStructure($category, $areas);
         $structure->generateStructure();

         /* pools: are assigned alternating */
         $i = 0;
         foreach( $structure->pools as $pool )
         {
            /** @var Pool $pool */
            $area = $areas_i[$i++%$numAreas];
            $this->assertSame($area, $pool->getArea());
            foreach ($pool->getMatchList() as $node)
            {
               $this->assertSame($area, $node->area);
            }
         }

         /* KO: are assigned in sub structures */
         foreach( $structure->ko->getRounds() as $round )
         {
            $numMatches = $round->count();
            if( $numMatches > $numAreas )
            {
               $perArea = intdiv($numMatches, $numAreas);
               foreach( $round as $i => $node )
               {
                  $area_idx = intdiv($i, $perArea);
                  $this->assertSame($areas_i[$area_idx], $node->area);
               }
            }
            else
            {
               /* allocation of finals (= the rounds where there are less matches than areas)
                * only check whether all areas are distributed equally
                */
               $areasUsed = [];
               foreach( $round as $node )
               {
                  $areasUsed[$node->area->id] ??= 0;
                  $areasUsed[$node->area->id]  += 1;
               }
               $this->assertTrue( min($areasUsed)+1 >= max($areasUsed) );
            }
         }
      }
   }

   /**
    * test whether a fully set-up KO structure with allocated participants
    * can be re-generated from memory
    */
   public function testKOReproducability()
   {
      $category = new Category(1, 1, "test", CategoryMode::KO, new CategoryConfiguration(3));
      $structure = new TournamentStructure($category, $this->areaList(2));
      $structure->generateStructure();

      $structure2 = new TournamentStructure($category, $this->areaList(2));
      $structure2->generateStructure();

      $this->assertEquals($structure, $structure2);
   }

   /**
    * test whether a fully set-up combined structure with allocated participants
    * can be re-generated from memory
    */
   public function testCombinedReproducability()
   {
      $category = new Category(1, 1, "test", CategoryMode::Combined, new CategoryConfiguration(3));
      $structure = new TournamentStructure($category, $this->areaList(2));
      $structure->generateStructure();

      $structure2 = new TournamentStructure($category, $this->areaList(2));
      $structure2->generateStructure();

      $this->assertEquals($structure, $structure2);
   }
}
