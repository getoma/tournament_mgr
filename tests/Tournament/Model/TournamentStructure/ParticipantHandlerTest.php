<?php

use PHPUnit\Framework\TestCase;

use Tournament\Model\Area\AreaCollection;
use Tournament\Model\Category\Category;
use Tournament\Model\Category\CategoryConfiguration;
use Tournament\Model\Category\CategoryMode;
use Tournament\Model\Participant\Participant;
use Tournament\Model\Participant\ParticipantCollection;
use Tournament\Model\TournamentStructure\TournamentStructure;


class ParticipantHandlerTest extends TestCase
{
   private function participantList($num): ParticipantCollection
   {
      return new ParticipantCollection( array_map( fn($i) => new Participant($i, 0, "firstname_".$i, "lastname_".$i), range(1,$num) ) );
   }

   /**
    * test whether BYEs are correctly placed in a pure KO tree
    */
   public function testBYEDistributionKO()
   {
      $category = new Category(1, 1, "test", CategoryMode::KO, new CategoryConfiguration(3));
      $structure = new TournamentStructure($category, AreaCollection::new());
      $structure->generateStructure();
      $structure->getParticipantHandler()->populate($this->participantList(4));
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
    * test whether participants are allocated into pools as expected
    * for a combined structure
    */
   public function testCombinedParticipantDistribution()
   {
      $participants = $this->participantList(18);
      $category = new Category(1, 1, "test", CategoryMode::Combined, new CategoryConfiguration(3, pool_winners: 2));
      $structure = new TournamentStructure($category, AreaCollection::new());
      $structure->generateStructure();
      $assignment = $structure->getParticipantHandler()->populate($participants);

      $assigned = array_map(fn($p) => $p->id, $assignment->values());
      $given = array_map(fn($p) => $p->id, $participants->values());
      $this->assertEqualsCanonicalizing($assigned, $given);

      // 4 pools
      $this->assertCount(4, $structure->pools);
      $this->assertCount(5, $structure->pools['1']->getParticipants());
      $this->assertCount(5, $structure->pools['2']->getParticipants());
      $this->assertCount(4, $structure->pools['3']->getParticipants());
      $this->assertCount(4, $structure->pools['4']->getParticipants());
   }

   /**
    * test whether a fully set-up KO structure with allocated participants
    * can be re-generated from memory
    */
   public function testKOReproducability()
   {
      $category = new Category(1, 1, "test", CategoryMode::KO, new CategoryConfiguration(3));
      $structure = new TournamentStructure($category, AreaCollection::new());
      $structure->generateStructure();
      $participants = $structure->getParticipantHandler()->populate($this->participantList(14));

      $structure2 = new TournamentStructure($category, AreaCollection::new());
      $structure2->generateStructure();
      $structure2->getParticipantHandler()->loadParticipants($participants);

      $this->assertEquals($structure, $structure2);
   }

   /**
    * test whether a fully set-up combined structure with allocated participants
    * can be re-generated from memory
    */
   public function testCombinedReproducability()
   {
      $category = new Category(1, 1, "test", CategoryMode::Combined, new CategoryConfiguration(3));
      $structure = new TournamentStructure($category, AreaCollection::new());
      $structure->generateStructure();
      $participants = $structure->getParticipantHandler()->populate($this->participantList(20));

      $structure2 = new TournamentStructure($category, AreaCollection::new());
      $structure2->generateStructure();
      $structure2->getParticipantHandler()->loadParticipants($participants);

      $this->assertEquals($structure, $structure2);
   }

}