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
   private function participantList(int $num, int $id_start = 1): ParticipantCollection
   {
      return new ParticipantCollection(
         array_map( fn($i) => new Participant($i, 0, "firstname_".$i, "lastname_".$i),
         range($id_start,$id_start+$num-1) ) );
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
    * test whether adding additional participants into pure KO works as expected
    * (new participants added according rules, established participants are not altered)
    */
   public function testKoParticipantsAdding()
   {
      $category = new Category(1, 1, "test", CategoryMode::KO, new CategoryConfiguration(4));
      $structure = new TournamentStructure($category, AreaCollection::new());
      $structure->generateStructure();
      $hdl   = $structure->getParticipantHandler();

      /* generate two rounds of participant additions */
      $first_list = $this->participantList(4, 1);
      $second_list = $this->participantList(4, 5);

      /* add both rounds to the structure */
      $first_assignment = $hdl->populate($first_list);
      $full_assignment = $hdl->populate($second_list);

      /* now test for plausibility according our expections */
      $this->assertCount($first_list->count(), $first_assignment);
      $this->assertCount($first_list->count() + $second_list->count(), $full_assignment);
      $firstExtracted = $full_assignment->intersect_key($first_assignment);
      $this->assertEqualsCanonicalizing($first_assignment, $firstExtracted, 'original assignments modified');
      $secondExtracted = array_uintersect($full_assignment->values(), $second_list->values(), fn($a,$b) => $a->id <=> $b->id);
      $this->assertEqualsCanonicalizing($second_list->values(), $secondExtracted, 'second participant list not matching');

      /* with 8 participants and 4 rounds (=2**4=16 participants),
       * all BYE slots should be in the white slots
       */
      foreach ($structure->ko->getFirstRound() as $node)
      {
         $this->assertFalse($node->slotRed->isBye());
         $this->assertTrue($node->slotWhite->isBye());
      }
   }

   /**
    * test whether adding additional participants into Pools works as expected
    * (new participants added according rules, established participants are not altered)
    */
   public function testPoolsParticipantsAdding()
   {
      $category = new Category(1, 1, "test", CategoryMode::Combined, new CategoryConfiguration(3, pool_winners: 2));
      $structure = new TournamentStructure($category, AreaCollection::new());
      $structure->generateStructure();
      $hdl = $structure->getParticipantHandler();

      /* generate two rounds of participant additions */
      $first_list = $this->participantList(10, 1);
      $second_list = $this->participantList(3, 11);

      /* add both rounds to the structure */
      $first_assignment = $hdl->populate($first_list);
      $full_assignment = $hdl->populate($second_list);

      /* now test for plausibility according our expections */
      $this->assertCount($first_list->count(), $first_assignment);
      $this->assertCount($first_list->count() + $second_list->count(), $full_assignment);
      $firstExtracted = $full_assignment->intersect_key($first_assignment);
      $this->assertEqualsCanonicalizing($first_assignment, $firstExtracted, 'original assignments modified');
      $secondExtracted = array_uintersect($full_assignment->values(), $second_list->values(), fn($a, $b) => $a->id <=> $b->id);
      $this->assertEqualsCanonicalizing($second_list->values(), $secondExtracted, 'second participant list not matching');

      /* check pool allocation of final result */
      $this->assertCount(4, $structure->pools);
      $this->assertCount(4, $structure->pools['1']->getParticipants());
      $this->assertCount(3, $structure->pools['2']->getParticipants());
      $this->assertCount(3, $structure->pools['3']->getParticipants());
      $this->assertCount(3, $structure->pools['4']->getParticipants());
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
      $participants = $structure->getParticipantHandler()->populate($this->participantList(14)); // set more participants than starting slots on purpose

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