<?php

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use Tournament\Model\Area\Area;
use Tournament\Model\Area\AreaCollection;
use Tournament\Model\Category\Category;
use Tournament\Model\Category\CategoryConfiguration;
use Tournament\Model\Category\CategoryMode;
use Tournament\Model\TournamentStructure\MatchSlot\MatchWinnerSlot;
use Tournament\Model\TournamentStructure\MatchSlot\PoolWinnerSlot;
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

      /* set up the structure and shuffle in participants*/
      $structure->generateStructure();

      /* verify the created setup to be as expected */
      $this->assertNotNull($structure->ko);
      $this->assertNotEmpty($structure->pools);
      $this->assertCount($expected_pool_count, $structure->pools);

      /* sanity check of first round: wildcards should be distributed among higher pool ranks first */
      $match_count = array_fill_keys(range(1,$pool_winners), 0);
      $wildcard_count = array_fill_keys(range(1, $pool_winners), 0);
      /* count the number of matches and wildcards per pool result rank */
      /** @var KoNode $node */
      foreach($structure->ko->getFirstRound() as $node)
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
   }

   /**
    * data provider to generate area assignment tests with different
    * number of areas and rounds
    * the algorithm was tested once with up to 8 areas and up to 10 rounds and yielded the expected results
    * for regression testing, we lower the test area to the expected ranges: up to 4 areas, up to 8 rounds
    */
   public static function numAreasProvider()
   {
      foreach( range(2,4) as $numAreas)
      {
         foreach( range(4,8) as $numRounds )
         {
            if( (2**$numRounds)/2 >= $numAreas ) // do not consider any usecase with more areas than nodes per round
            {
               yield "$numAreas Areas, $numRounds Rounds" => [$numAreas, $numRounds];
            }
         }
      }
   }

   /**
    * test whether areas are assigned as expected
    */
   #[DataProvider('numAreasProvider')]
   public function testAreaAssignment(int $numAreas=4, int $numRounds = 8)
   {
      $areas = $this->areaList($numAreas);
      $category = new Category(1, 1, "test", CategoryMode::Combined, new CategoryConfiguration($numRounds));
      $structure = new TournamentStructure($category, $areas);
      $structure->generateStructure();

      /* pools: are assigned alternating */
      $i = 0;
      $areas_i = $areas->values();
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

      /* KO: areas are assigned equally and in clusters */
      $tracker = [];
      foreach( $structure->ko->getRounds() as $i => $round )
      {
         $prev_area = null;
         foreach( $round as $node )
         {
            /* any area assigned at all? */
            $this->assertIsObject($node->area, "no area assigned to KO node");

            /* if both in-nodes are on the same area, this node should also be on this area */
            if( $node->slotRed instanceof MatchWinnerSlot && $node->slotWhite instanceof MatchWinnerSlot
               && $node->slotRed->matchNode->area === $node->slotWhite->matchNode->area )
            {
               $this->assertSame($node->slotRed->matchNode->area, $node->area, "area shift within the same cluster");
            }

            /* areas shall be assigned "in order" */
            if( isset($prev_area) )
            {
               $this->assertGreaterThanOrEqual($prev_area->id, $node->area->id);
            }
            $prev_area = $node->area;

            /* count matches per area */
            $tracker[$node->area->id] ??= 0;
            $tracker[$node->area->id] += 1;
         }

         /* after each round, the cummulated area distribution needs to be equal within a certain tolerance
          * This means after round 1 all areas should've been equally used, after round 2 all areas need to be equally used as well
          * *including* the usage counters for round 1, etc.
          * For low number of areas, tolerance is 1. Starting from 5 areas, we need to accept up to 2 matches difference
          */
         $r_disp = $i+1;
         $tolerance = ($numAreas > 4) ? 2 : 1;
         $this->assertEqualsWithDelta(min($tracker), max($tracker), $tolerance, "Areas are not equally distributed after round $r_disp!");
      };
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
    * @dataProvider PoolSetupProvider
    */
   public function testCombinedReproducability(int $rounds = 4, int $pool_limit = 6, int $pool_winners = 2)
   {
      $category = new Category(1, 1, "test", CategoryMode::Combined, new CategoryConfiguration($rounds, pool_winners: $pool_winners, max_pools: $pool_limit));
      $structure = new TournamentStructure($category, $this->areaList(2));
      $structure->generateStructure();

      $structure2 = new TournamentStructure($category, $this->areaList(2));
      $structure2->generateStructure();

      $this->assertEquals($structure, $structure2);
   }
}
