<?php

use Tournament\Model\Category\Category;
use Tournament\Model\Participant\Participant;
use Tournament\Model\Category\CategoryConfiguration;
use Tournament\Model\TournamentStructure\TournamentStructure;

use PHPUnit\Framework\TestCase;
use Tournament\Model\Area\AreaCollection;
use Tournament\Model\Category\CategoryMode;
use Tournament\Model\Participant\ParticipantCollection;

class TournamentStructureTest extends TestCase
{
   private function koCategory($rounds): Category
   {
      return new Category(
         id: null,
         tournament_id: 0,
         name: "ko_test",
         mode: 'ko',
         config: new CategoryConfiguration(num_rounds: $rounds)
      );
   }

   private function combinedCategory($rounds, $winners): Category
   {
      return new Category(
         id: null,
         tournament_id: 0,
         name: "combined_test",
         mode: 'combined',
         config: new CategoryConfiguration(num_rounds: $rounds, pool_winners: $winners)
      );
   }

   private function participantList($num): ParticipantCollection
   {
      return new ParticipantCollection( array_map( fn($i) => new Participant($i, 0, "firstname_".$i, "lastname_".$i), range(1,$num) ) );
   }

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

   public function testReproducability()
   {
      $structure = new TournamentStructure();
      $structure->generateStructure(CategoryMode::Combined, 3, AreaCollection::new(), 2);
      $participants = $structure->shuffleParticipants($this->participantList(20));

      $structure2 = new TournamentStructure();
      $structure2->generateStructure(CategoryMode::Combined, 3, AreaCollection::new(), 2);
      $structure2->loadParticipants($participants);

      $this->assertEquals($structure, $structure2);
   }
}
