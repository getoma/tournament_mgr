<?php declare(strict_types=1);

namespace Tests\Tournament\Model\TournamentStructure;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use Tests\Tournament\Model\TestStubs\TestMatchParticipant;

use Tournament\Model\Area\AreaCollection;
use Tournament\Model\Category\Category;
use Tournament\Model\Category\CategoryConfiguration;
use Tournament\Model\Category\CategoryMode;
use Tournament\Model\TournamentStructure\MatchNode\MatchNode;
use Tournament\Model\TournamentStructure\MatchParticipant\MatchParticipant;
use Tournament\Model\TournamentStructure\MatchParticipant\MatchParticipantCollection;
use Tournament\Model\TournamentStructure\Pool\Pool;
use Tournament\Model\TournamentStructure\TournamentStructure;

class ParticipantHandlerTest extends TestCase
{
   private function participantList(int $num, int|MatchParticipantCollection $id_start = 1, ?string $club = null): MatchParticipantCollection
   {
      if( $id_start instanceof MatchParticipantCollection )
      {
         // use an already existing participant collection to derive the next id
         $id_start = $id_start->empty()? 1 : max($id_start->map(fn($p) => $p->getId())) + 1;
      }
      return new MatchParticipantCollection(
         array_map( fn($i) => new TestMatchParticipant($i, "name_".$i, $club, false),
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
      $structure->populate($this->participantList(4));
      $rounds = $structure->ko->getRounds();
      $this->assertCount(4, $rounds[0]); // 4 matches in the first round

      // All BYEs should have ended up in the white slots
      foreach ($rounds[0] as $match)
      {
         $this->assertFalse($match->getRedSlot()->isBye());
         $this->assertTrue($match->getWhiteSlot()->isBye());
      }
   }

   public static function combinedModeSetupProvider()
   {
      return [
         # rounds, participants per pool
         [ 3, 4.5  ], // 4 pools with 5 or 4 participants
         [ 4, 3.75 ], // 8 pools with 4 or 3 participants
         [ 5, 3.25 ], // 16 pools with 4 or 3 participants
      ];
   }

   /**
    * test whether participants are allocated into pools as expected
    * for a combined structure
    */
   #[DataProvider('combinedModeSetupProvider')]
   public function testCombinedParticipantDistribution(int $rounds = 5, float $p_per_pool = 3.5)
   {
      // derive expected parameters:
      $winners_per_pool = 2;
      $expected_pool_count = (int)round((2 ** $rounds) / $winners_per_pool);
      $participant_count = (int)round($p_per_pool * $expected_pool_count);
      $min_participants_per_pool = (int)floor($p_per_pool);
      $ppcount_switch_index = (int)round(fmod($p_per_pool, 1) * $expected_pool_count);

      // generate structure
      $participants = $this->participantList($participant_count);
      $category = new Category(1, 1, "test", CategoryMode::Combined, new CategoryConfiguration($rounds, pool_winners: $winners_per_pool));
      $structure = new TournamentStructure($category, AreaCollection::new());
      $structure->generateStructure();
      $assignment = $structure->populate($participants);

      // check that list of assigned participants matches list of provided participants
      $assigned = array_map(fn($p) => $p->getId(), $assignment->values());
      $given = array_map(fn($p) => $p->getId(), $participants->values());
      $this->assertEqualsCanonicalizing($assigned, $given);

      // check that pool distribution matches
      $this->assertCount($expected_pool_count, $structure->pools);
      foreach( $structure->pools->values() as $i => $pool )
      {
         $expected = $min_participants_per_pool + ($i<$ppcount_switch_index? 1 : 0);
         $this->assertCount($expected, $pool->getParticipants());
      }
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
      $hdl   = $structure;

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
      $secondExtracted = array_uintersect($full_assignment->values(), $second_list->values(), fn($a,$b) => $a->getId() <=> $b->getId());
      $this->assertEqualsCanonicalizing($second_list->values(), $secondExtracted, 'second participant list not matching');

      /* with 8 participants and 4 rounds (=2**4=16 participants),
       * all BYE slots should be in the white slots
       */
      foreach ($structure->ko->getFirstRound() as $node)
      {
         $this->assertFalse($node->getRedSlot()->isBye());
         $this->assertTrue($node->getWhiteSlot()->isBye());
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

      /* generate two rounds of participant additions */
      $first_list = $this->participantList(10, 1);
      $second_list = $this->participantList(3, 11);

      /* add first round to the structure */
      $first_assignment = $structure->populate($first_list);
      /* store back the current slot assignment in a full copy to verify it after the next step */
      $first_slots = array_combine($first_assignment->map(fn($p) => $p->getId()), $first_assignment->map(fn($p) => $p->getStartSlot($category)));
      /* add second round to the structure */
      $full_assignment = $structure->populate($second_list);

      /* check whether the length of the returned lists match */
      $this->assertCount($first_list->count(), $first_assignment);
      $this->assertCount($first_list->count() + $second_list->count(), $full_assignment);

      /* make sure the first assignment didn't get modified */
      foreach( $first_slots as $pid => $slot_name )
      {
         $this->assertEquals($slot_name, $full_assignment[$pid]->getStartSlot($category), "Pre-existing slot assignments got modified!");
      }

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
      $participants = $structure->populate($this->participantList(14)); // set more participants than starting slots on purpose

      $structure2 = new TournamentStructure($category, AreaCollection::new());
      $structure2->generateStructure();
      $structure2->loadParticipants($participants->reverse()); // explicitly provide them in a different order

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
      $participants = $structure->populate($this->participantList(20));

      $structure2 = new TournamentStructure($category, AreaCollection::new());
      $structure2->generateStructure();
      $structure2->loadParticipants($participants->reverse()); // explicitly provide them in a different order

      $this->assertEquals($structure, $structure2);
   }

   /**
    * test whether participants from the same club are placed apart in pools
    */
   public function testClubSpreadPools()
   {
      $pool_count = 4;
      $category = new Category(1, 1, "test", CategoryMode::Combined, new CategoryConfiguration(intval(ceil(log($pool_count*2, 2)))));
      $structure = new TournamentStructure($category, AreaCollection::new());
      $structure->generateStructure();
      $this->assertCount($pool_count, $structure->pools);

      $setup = [
         [ "A" => 1, "B" => 2, "C" => 1 ],
         [ "A" => 1, "D" => 1 ],
      ];

      $cumulated = [];
      $slots = [];
      $all_participants = MatchParticipantCollection::new();
      foreach( $setup as $step => $club_setup )
      {
         $participants = MatchParticipantCollection::new();
         foreach($club_setup as $club => $count)
         {
            $pl = $this->participantList($count * $pool_count, $all_participants, $club);
            $participants->mergeInPlace($pl);
            $all_participants->mergeInPlace($pl);
            $cumulated[$club] ??= 0;
            $cumulated[$club] += $count;
         }

         $assigned = $structure->populate($participants);

         /* verify that previously placed participants are not modified */
         foreach ($slots as $slotting)
         {
            foreach ($slotting as $pid => $slotName)
            {
               $this->assertEquals($slotName, $all_participants[$pid]->getStartSlot($category), "assignment for Participant $pid got modified");
            }
         }

         /* store back the slots that were assigned in this round */
         $new_assigned = $assigned->filter( fn($p) => $participants->contains($p) );
         $slots[$step] = array_combine($new_assigned->map(fn($p) => $p->getId()), $new_assigned->map(fn($p) => $p->getStartSlot($category)) );

         /* verify that each pool has the expected number of participants per club */
         foreach ($structure->pools as $pool)
         {
            /** @var Pool $pool */
            $tracker = [];
            foreach( $pool->getParticipants() as $p )
            {
               /** @var MatchParticipant $p */
               $tracker[$p->getClub()] ??= 0;
               $tracker[$p->getClub()] += 1;
            }

            foreach( $tracker as $club => $cnt )
            {
               $this->assertEquals($cumulated[$club], $cnt, "assignment count does not match for $club in Pool {$pool->getName()} in iteration {$step}");
            }
         }
      }
   }

   /**
    * test whether participants from the same club are placed apart in a KO tree
    */
   public function testClubSpreadKo()
   {
      $max_participant_count = 8;
      $category = new Category(1, 1, "test", CategoryMode::KO, new CategoryConfiguration(intval(ceil(log($max_participant_count, 2)))));
      $structure = new TournamentStructure($category, AreaCollection::new());
      $structure->generateStructure();

      $setup = [
         ["A" => 2, "B" => 1, "C" => 1],
         ["A" => 2, "B" => 1, "D" => 1],
      ];

      $cumulated = [];
      $slots = [];
      $all_participants = MatchParticipantCollection::new();
      foreach ($setup as $step => $club_setup)
      {
         $participants = MatchParticipantCollection::new();
         foreach ($club_setup as $club => $count)
         {
            $pl = $this->participantList($count, $all_participants, $club);
            $participants->mergeInPlace($pl);
            $all_participants->mergeInPlace($pl);
            $cumulated[$club] ??= 0;
            $cumulated[$club] += $count;
         }

         $assigned = $structure->populate($participants);

         /* verify that previously placed participants are not modified */
         foreach ($slots as $slotting)
         {
            foreach ($slotting as $pid => $slotName)
            {
               $this->assertEquals($slotName, $all_participants[$pid]->getStartSlot($category), "assignment for Participant $pid got modified");
            }
         }

         /* store back the slots that were assigned in this round */
         $new_assigned = $assigned->filter(fn($p) => $participants->contains($p));
         $slots[$step] = array_combine($new_assigned->map(fn($p) => $p->getId()), $new_assigned->map(fn($p) => $p->getStartSlot($category)));

         /* verify that on no node, two participants do have the same club */
         foreach ($structure->ko->getFirstRound() as $node)
         {
            /** @var MatchNode $node */
            $red = $node->getRedParticipant();
            $white = $node->getWhiteParticipant();
            if( isset($red) && isset($white) )
            {
               $this->assertNotEquals($red->getClub(), $white->getClub(), 'participants of same club put into the same start fight!');
            }
         }
      }
   }
}