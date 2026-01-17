<?php

use PHPUnit\Framework\TestCase;

use Tournament\Model\Area\Area;
use Tournament\Model\Area\AreaCollection;
use Tournament\Model\Category\Category;
use Tournament\Model\Category\CategoryConfiguration;
use Tournament\Model\Category\CategoryMode;
use Tournament\Model\Participant\Participant;
use Tournament\Model\Participant\ParticipantCollection;
use Tournament\Model\PoolRankHandler\PoolRankHandler;
use Tournament\Model\TournamentStructure\MatchNode\KoNode;
use Tournament\Model\TournamentStructure\MatchSlot\PoolWinnerSlot;
use Tournament\Model\TournamentStructure\TournamentStructure;
use Tournament\Model\TournamentStructure\Pool\Pool;
use Tournament\Model\TournamentStructure\Pool\PoolCollection;

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
    * test whether BYEs are correctly placed in a pure KO tree
    */
   public function testBYEDistributionKO()
   {
      $category = new Category(1, 1, "test", CategoryMode::KO, new CategoryConfiguration(3));
      $structure = new TournamentStructure($category, AreaCollection::new());
      $structure->generateStructure();
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
    * test whether participants are allocated into pools as expected
    * for a combined structure
    */
   public function testCombinedParticipantDistribution()
   {
      $participants = $this->participantList(18);
      $category = new Category(1, 1, "test", CategoryMode::Combined, new CategoryConfiguration(3, pool_winners: 2));
      $structure = new TournamentStructure($category, AreaCollection::new());
      $structure->generateStructure();
      $assignment = $structure->shuffleParticipants($participants);

      $assigned = array_map( fn($p) => $p->id, $assignment->values() );
      $given = array_map( fn($p) => $p->id, $participants->values() );
      $this->assertEqualsCanonicalizing($assigned, $given);

      // 4 pools
      $this->assertCount(4, $structure->pools);
      $this->assertCount(5, $structure->pools['1']->getParticipants());
      $this->assertCount(5, $structure->pools['2']->getParticipants());
      $this->assertCount(4, $structure->pools['3']->getParticipants());
      $this->assertCount(4, $structure->pools['4']->getParticipants());
   }

   public static function PoolSetupProvider()
   {
      return [
         /* rounds, pool limit, pool_winners */
         [ 4, 0, 2], // 4 rounds, no limit
         [ 4, 6, 2], // 4 rounds, 6 pools
         [ 4, 5, 2], // 4 rounds, 5 pools
         [ 3, 8, 2]  // 3 rounds, pool limit higher than possible for 3 rounds
      ];
   }

   /**
    * test whether "pool limit" configuration works as expected
    * @dataProvider PoolSetupProvider
    */
   public function testCombinedPoolLimit(int $rounds = 4, int $pool_limit = 6, int $pool_winners = 2)
   {
      $category = new Category(1, 1, "test", CategoryMode::Combined, new CategoryConfiguration($rounds, pool_winners: $pool_winners, max_pools: $pool_limit));
      $structure = new TournamentStructure($category, AreaCollection::new());
      /* expected pool count is number of KO start slots, divided by winners by pool, OR the configured limit if lower */
      $expected_pool_count = floor(pow(2, $rounds) / $pool_winners);
      if( $pool_limit && $pool_limit < $expected_pool_count ) $expected_pool_count = $pool_limit;
      /* lets put 2 or 3 particitpants per pool */
      $participants = $this->participantList(ceil($expected_pool_count * $pool_winners * 1.5));

      /* set up the structure and shuffle in participants*/
      $structure->generateStructure();
      $assignment = $structure->shuffleParticipants($participants);

      /* verify the created setup to be as expected */
      $this->assertNotNull($structure->ko);
      $this->assertNotEmpty($structure->pools);
      $this->assertCount($expected_pool_count, $structure->pools);

      /* sanity check of first round: wildcards should be distributed among higher pool ranks first */
      $match_count = array_fill_keys(range(1,$pool_winners), 0);
      $wildcard_count = array_fill_keys(range(1, $pool_winners), 0);
      /* count the number of matches and wildcards per pool result rank */
      /** @var KoNode $node */
      foreach($structure->ko->getRounds(0, 1)->front() as $node)
      {
         $red_rank   = ($node->slotRed instanceof PoolWinnerSlot)? $node->slotRed->rank : null;
         $white_rank = ($node->slotWhite instanceof PoolWinnerSlot) ? $node->slotWhite->rank : null;

         if( isset($white_rank) && isset($red_rank) )
         {
            $match_count[$red_rank] += 1;
            $match_count[$white_rank] += 1;
         }
         elseif( isset($white_rank) )
         {
            $wildcard_count[$white_rank] += 1;
         }
         elseif( isset($red_rank) )
         {
            $wildcard_count[$red_rank] += 1;
         }
         else
         {
            // nothing, full BYE match
         }
      }
      /* now check if the distribution is as expected: wildcards should be on highest places first */
      $wildcards_allowed = true;
      for( $rank = 1; $rank <= $pool_winners; ++$rank )
      {
         if( !$wildcards_allowed ) $this->assertEquals(0, $wildcard_count[$rank], "wildcards assigned to place $rank although matches assigned to higher ranks" );
         if( $match_count[$rank] ) $wildcards_allowed = false;
      }

      /* verify the participant allocation */
      $assigned = array_map(fn($p) => $p->id, $assignment->values());
      $given = array_map(fn($p) => $p->id, $participants->values());
      $this->assertEqualsCanonicalizing($assigned, $given);
      $min_per_pool = floor($participants->count()/$structure->pools->count());
      $max_per_pool = ceil($participants->count()/$structure->pools->count());
      /** @var Pool $pool */
      foreach( $structure->pools as $pool )
      {
         $this->assertGreaterThanOrEqual($min_per_pool, $pool->getParticipants()->count());
         $this->assertLessThanOrEqual($max_per_pool, $pool->getParticipants()->count());
      }
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
         $structure->shuffleParticipants($this->participantList(20));

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
      $participants = $structure->shuffleParticipants($this->participantList(14));

      $structure2 = new TournamentStructure($category, $this->areaList(2));
      $structure2->generateStructure();
      $structure2->loadParticipants($participants);

      $this->assertEquals($structure, $structure2);
   }

   /**
    * test whether a fully set-up combined structure with allocated participants
    * can be re-generated from memory
    * @dataProvider PoolSetupProvider
    */
   public function testCombinedReproducability(int $rounds = 4, int $pool_limit = 6, int $pool_winners = 2)
   {
      $category = new Category(1, 1, "test", CategoryMode::Combined, new CategoryConfiguration($rounds, pool_winners: $pool_winners, max_pools: $pool_limit));
      $structure = new TournamentStructure($category, $this->areaList(2));
      $structure->generateStructure();
      $participants = $structure->shuffleParticipants($this->participantList(20));

      $structure2 = new TournamentStructure($category, $this->areaList(2));
      $structure2->generateStructure();
      $structure2->loadParticipants($participants);

      $this->assertEquals($structure, $structure2);
   }
}
