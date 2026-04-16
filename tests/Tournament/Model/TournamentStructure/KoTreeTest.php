<?php declare(strict_types=1);

namespace Tests\Tournament\Model\TournamentStructure;

use PHPUnit\Framework\TestCase;

use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\Category\CategoryMode;
use Tournament\Model\MatchRecord\MatchRecord;
use Tournament\Model\MatchRecord\MatchRecordCollection;
use Tournament\Model\Participant\Participant;
use Tournament\Model\TournamentStructure\MatchSlot\MatchWinnerSlot;
use Tournament\Model\TournamentStructure\MatchSlot\ParticipantSlot;
use Tournament\Model\MatchPointHandler\MatchPointHandler;
use Tournament\Model\TournamentStructure\KoTree;
use Tournament\Model\TournamentStructure\MatchNode\MatchNode;
use Tournament\Model\TournamentStructure\MatchNode\MatchSide;
use Tournament\Model\TournamentStructure\MatchNode\SoloKoMatch;

/**
 * test KoTree traversal methods
 */
class KoTreeTest extends TestCase
{
   const ROUNDS = 3;

   /** @var KoTree $ko - the ko tree under test */
   protected KoTree $ko;
   /** @var MatchNode[] - flat list of all created nodes as test input */
   protected array $node_list;
   /** @var Stub[] list of all starting slots (ParticipantSlots, basically) */
   protected array $start_slots;

   /* match point handler for the match node */
   protected MatchPointHandler $mpHdl;
   protected Category $category;

   /**
    * create a KO tree with ROUNDS rounds, and fill up above member variables
    */
   protected function setUp(): void
   {
      $this->mpHdl = $this->createStub(MatchPointHandler::class);
      $category = $this->getStubBuilder(Category::class)
         ->enableOriginalConstructor()
         ->setConstructorArgs([1, 1, 'test', CategoryMode::KO])
         ->getStub();
      $category->method('getMatchPointHandler')->willReturn($this->mpHdl);
      $this->category = $category;

      /* for testing, set up a KO tree according ROUNDS config */
      $round1_cnt = pow(2, self::ROUNDS - 1); // e.g. 3 ROUNDS -> 2**(3-1) = 4 matches in round 1
      $currentRound = array_map(
         function($i) use ($category)
         {
            /** @var ParticipantSlot $a */
            $this->start_slots[] = $a = $this->createStub(ParticipantSlot::class);
            /** @var ParticipantSlot $b */
            $this->start_slots[] = $b = $this->createStub(ParticipantSlot::class);
            return new SoloKoMatch("test_".$i, $category, $a, $b);
         }
         , range(1, $round1_cnt) );

      $this->node_list = $currentRound;

      $nextMatchId = $round1_cnt + 1; // next match ID, starting after the last match in the first round
      // use the current round to create the next round until we reach the finale
      while (count($currentRound) > 1)
      {
         $previousRound = $currentRound;
         $currentRound = [];
         for ($i = 0; $i < count($previousRound); $i += 2)
         {
            $slotRed   = new MatchWinnerSlot($previousRound[$i]);
            $slotWhite = new MatchWinnerSlot($previousRound[$i + 1]);
            $node = new SoloKoMatch("test_" . $nextMatchId++, $category, slotRed: $slotRed, slotWhite: $slotWhite);
            $currentRound[] = $node;
            $this->node_list[] = $node;
         }
      }
      $this->ko = new KoTree($currentRound[0]);
   }

   /**
    * test fetching of all contained MatchNodes
    */
   public function testGetRounds(): void
   {
      $rounds = $this->ko->getRounds();
      $this->assertCount(self::ROUNDS, $rounds);

      $node_idx = 0;
      for( $i=0; $i<self::ROUNDS; ++$i)
      {
         $this->assertCount(pow(2,self::ROUNDS-$i-1), $rounds[$i]);
         foreach( $rounds[$i] as $node )
         {
            $this->assertSame($this->node_list[$node_idx++], $node);
         }
      }

      /* also, check the output of the very related method getMatchList() */
      $this->assertSame($this->node_list, $this->ko->getMatchList()->values());
   }

   /**
    * test MatchNode query by name
    */
   public function testFindByName(): void
   {
      foreach( $this->node_list as $node )
      {
         $this->assertSame($node, $this->ko->findByName($node->getName()));
      }
      $this->assertSame(null, $this->ko->findByName("bla"));
   }

   /**
    * test fetching of all participants
    */
   public function testGetParticipantList(): void
   {
      $sortCbk = fn($a, $b) => $a->id <=> $b->id;

      $participants = [];
      $this->assertEmpty($this->ko->getParticipantList());

      // start with adding a participant to the start, end and somewhere in the middle
      $index = [0, count($this->start_slots)-1, count($this->start_slots)/2, count($this->start_slots)/4];
      // then fill up the rest
      $index = array_merge($index, array_filter( range(0,count($this->start_slots)-1), fn($i) => !in_array($i, $index) ));

      foreach($index as $i)
      {
         // add the new participant
         $p = new Participant($i+1, 1, '', '');
         $participants[] = $p;
         $this->start_slots[$i]->method('getParticipant')->willReturn($p);

         // get list of participants and see if it matches
         usort($participants, $sortCbk);
         $received = $this->ko->getParticipantList();
         usort($received, $sortCbk);
         $this->assertSame($participants, $received);
      }
   }

   /**
    * test setting of match records and querying ranking due to those
    */
   public function testMatchData(): void
   {
      $area = $this->createStub(Area::class);
      /* iterate through the tree and fill up the match records backward.
       * alternate winner between red and white.
       * fill expected ranks accordingly
       */
      $pid = 1;
      $ranks = [[new Participant($pid++, 1, '', '')]];
      $next = [[1, $this->ko->root, $ranks[0][0]]];
      $records = MatchRecordCollection::new();
      $red_winner = true;
      while( count($next) )
      {
         /** @var MatchNode $node */
         /* fetch/create input data */
         list($rank_idx, $node, $winner) = array_shift($next);
         $defeated = new Participant($pid++, 1, '', '');
         /* prepare expected ranks */
         $ranks[$rank_idx] ??= [];
         $ranks[$rank_idx][] = $defeated;
         /* assign participants to slots */
         $redParticipant   = $red_winner ? $winner : $defeated;
         $whiteParticipant = $red_winner ? $defeated : $winner;
         list( $slotRed, $slotWhite ) = [ $node->getRedSlot(), $node->getWhiteSlot() ];
         if( $slotRed instanceof MatchWinnerSlot )
         {
            $next[] = [$rank_idx+1, $slotRed->matchNode, $redParticipant];
         }
         else
         {
            /** @var Stub $slotRed */
            $slotRed->method('getParticipant')->willReturn($redParticipant);
         }
         if ($slotWhite instanceof MatchWinnerSlot)
         {
            $next[] = [$rank_idx+1, $slotWhite->matchNode, $whiteParticipant];
         }
         else
         {
            /** @var Stub $slotWhite */
            $slotWhite->method('getParticipant')->willReturn($whiteParticipant);
         }
         /* create the match record */
         $records[] = new MatchRecord(
            1, $node->getName(), $this->category, $area,
            $redParticipant, $whiteParticipant, $red_winner? MatchSide::RED : MatchSide::WHITE,
            tie_break: false, finalized_at: new \DateTime() );

         /* alternate the winner */
         $red_winner = !$red_winner;
      }

      /* also add another unrelated match record to ensure record loading doesn't trip over it */
      $records[] = new MatchRecord(
         1, "test_dummy", $this->category, $area,
         new Participant($pid++, 1, '', ''),
         new Participant($pid++, 1, '', ''),
         null,
         tie_break: false, finalized_at: null );

      /* now assign the match records */
      $this->ko->setMatchRecords($records);

      /* ... and check whether the ranks are reconstructed accordingly */
      for( $i=0; $i < self::ROUNDS+1; ++$i )
      {
         $received_ranks = $this->ko->getRanked($i+1);
         $this->assertSame($ranks[$i], $received_ranks->values());
      }
   }
}