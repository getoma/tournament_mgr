<?php

use Tournament\Model\Participant\Participant;
use Tournament\Model\TournamentStructure\TournamentStructure;
use Tournament\Model\TournamentStructure\Pool\Pool;

use PHPUnit\Framework\TestCase;
use Tournament\Model\Area\Area;
use Tournament\Model\Area\AreaCollection;
use Tournament\Model\Category\CategoryMode;
use Tournament\Model\Participant\ParticipantCollection;

class TournamentStructureTest extends TestCase
{
   private function participantList($num): ParticipantCollection
   {
      return new ParticipantCollection( array_map( fn($i) => new Participant($i, 0, "firstname_".$i, "lastname_".$i), range(1,$num) ) );
   }

   private function areaList($num): AreaCollection
   {
      return new AreaCollection( array_map( fn($i) => new Area($i, 1, "area_".$i), range(1,$num)));
   }

   /**
    * test whether a simple knock-out tree is generated as expected
    */
   public function testBuildKnockOutTree()
   {
      $structure = new TournamentStructure();
      $structure->generateStructure(CategoryMode::KO, 3, AreaCollection::new());
      $this->assertNotNull($structure->ko);
      $this->assertEmpty($structure->pools);

      $rounds = $structure->ko->getRounds();
      $this->assertCount(3, $rounds);    // 4 quarter, 2 half, 1 finale
      $this->assertCount(4, $rounds[0]);
      $this->assertCount(2, $rounds[1]);
      $this->assertCount(1, $rounds[2]);
   }

   /**
    * test whether BYEs are correctly placed in a pure KO tree
    */
   public function testBYEDistributionKO()
   {
      $structure = new TournamentStructure();
      $structure->generateStructure(CategoryMode::KO, 3, AreaCollection::new());
      $structure->shuffleParticipants($this->participantList(4));
      $rounds = $structure->ko->getRounds();
      $this->assertCount(4, $rounds[0]); // 4 matches in the first round

      // All BYEs should have ended up in the white slots
      foreach ($rounds[0] as $match)
      {
         $this->assertFalse($match->slotRed->isBye());
         $this->assertTrue($match->slotWhite->isBye());
      }
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
      $structure = new TournamentStructure();
      $structure->generateStructure(CategoryMode::Combined, 3, AreaCollection::new(), null);
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
      $structure = new TournamentStructure();
      $structure->generateStructure(CategoryMode::Combined, 4, AreaCollection::new(), 3);
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
    * test whether participants are allocated into pools as expected
    * for a combined structure
    */
   public function testCombinedParticipantDistribution()
   {
      $participants = $this->participantList(18);
      $structure = new TournamentStructure();
      $structure->generateStructure(CategoryMode::Combined, 3, AreaCollection::new(), 2);
      $assignment = $structure->shuffleParticipants($participants);

      $assigned = array_map( fn($p) => $p->id, $assignment->values() );
      $given = array_map( fn($p) => $p->id, $participants->values() );
      $this->assertEqualsCanonicalizing($assigned, $given);

      // 4 pools
      $this->assertCount(4, $structure->pools);
      $this->assertCount(5, $structure->pools[0]->getParticipantList());
      $this->assertCount(5, $structure->pools[1]->getParticipantList());
      $this->assertCount(4, $structure->pools[2]->getParticipantList());
      $this->assertCount(4, $structure->pools[3]->getParticipantList());
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
         $structure = new TournamentStructure();
         $structure->generateStructure(CategoryMode::Combined, 3, $areas);
         $structure->shuffleParticipants($this->participantList(20));

         /* pools: are assigned alternating */
         foreach( $structure->pools as $i => $pool )
         {
            /** @var Pool $pool */
            $area = $areas_i[$i%$numAreas];
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
      $structure = new TournamentStructure();
      $structure->generateStructure(CategoryMode::KO, 3, $this->areaList(2), 2);
      $participants = $structure->shuffleParticipants($this->participantList(14));

      $structure2 = new TournamentStructure();
      $structure2->generateStructure(CategoryMode::KO, 3, $this->areaList(2), 2);
      $structure2->loadParticipants($participants);

      $this->assertEquals($structure, $structure2);
   }

   /**
    * test whether a fully set-up combined structure with allocated participants
    * can be re-generated from memory
    */
   public function testCombinedReproducability()
   {
      $structure = new TournamentStructure();
      $structure->generateStructure(CategoryMode::Combined, 3, $this->areaList(2), 2);
      $participants = $structure->shuffleParticipants($this->participantList(20));

      $structure2 = new TournamentStructure();
      $structure2->generateStructure(CategoryMode::Combined, 3, $this->areaList(2), 2);
      $structure2->loadParticipants($participants);

      $this->assertEquals($structure, $structure2);
   }
}
