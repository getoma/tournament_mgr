<?php

namespace Tests\Tournament\Model\TournamentStructure\MatchNode;

use PHPUnit\Framework\TestCase;

use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\MatchRecord\MatchRecord;
use Tournament\Model\MatchRecord\MatchRecordCollection;
use Tournament\Model\Participant\Participant;
use Tournament\Model\TournamentStructure\MatchNode\KoNode;
use Tournament\Model\TournamentStructure\MatchSlot\MatchWinnerSlot;
use Tournament\Model\TournamentStructure\MatchSlot\ParticipantSlot;
use Tournament\Model\MatchPointHandler\MatchPointHandler;

/**
 * test all additional functionalities of KoNode (compared to MatchNode)
 */
class KoNodeTest extends TestCase
{
   const ROUNDS = 3;

   /** @var KoNode $node - the root node and node-under-test */
   protected KoNode $node;
   /** @var KoNode[] - flat list of all created nodes as test input */
   protected array $node_list;
   /** @var Stub[] list of all starting slots (ParticipantSlots, basically) */
   protected array $start_slots;

   /* match point handler for the match node */
   protected MatchPointHandler $mpHdl;

   /**
    * create a KoNode tree with ROUNDS rounds, and fill up above member variables
    */
   protected function setUp(): void
   {
      $this->mpHdl = $this->createStub(MatchPointHandler::class);

      /* for testing, set up a KO tree according ROUNDS config */
      $round1_cnt = pow(2, self::ROUNDS - 1); // e.g. 3 ROUNDS -> 2**(3-1) = 4 matches in round 1
      $currentRound = array_map(
         function($i)
         {
            /** @var ParticipantSlot $a */
            $this->start_slots[] = $a = $this->createStub(ParticipantSlot::class);
            /** @var ParticipantSlot $b */
            $this->start_slots[] = $b = $this->createStub(ParticipantSlot::class);
            return new KoNode("test_".$i, $a, $b, $this->mpHdl);
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
            $node = new KoNode("test_" . $nextMatchId++, slotRed: $slotRed, slotWhite: $slotWhite, mpHdl: $this->mpHdl);
            $currentRound[] = $node;
            $this->node_list[] = $node;
         }
      }
      $this->node = $currentRound[0];
   }

   /**
    * test fetching of all contained MatchNodes
    */
   public function testGetRounds(): void
   {
      $rounds = $this->node->getRounds();
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
      $this->assertSame($this->node_list, $this->node->getMatchList()->values());
   }

   /**
    * test MatchNode query by name
    */
   public function testFindByName(): void
   {
      foreach( $this->node_list as $node )
      {
         $this->assertSame($node, $this->node->findByName($node->getName()));
      }
      $this->assertSame(null, $this->node->findByName("bla"));
   }

   /**
    * test fetching of all participants
    */
   public function testGetParticipantList(): void
   {
      $sortCbk = fn($a, $b) => $a->id <=> $b->id;

      $participants = [];
      $this->assertEmpty($this->node->getParticipantList());

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
         $received = $this->node->getParticipantList();
         usort($received, $sortCbk);
         $this->assertSame($participants, $received);
      }
   }

   /**
    * test setting of match records and querying ranking due to those
    */
   public function testMatchData(): void
   {
      $category = $this->createStub(Category::class);
      $area = $this->createStub(Area::class);
      /* iterate through the tree and fill up the match records backward.
       * alternate winner between red and white.
       * fill expected ranks accordingly
       */
      $pid = 1;
      $ranks = [[new Participant($pid++, 1, '', '')]];
      $next = [[1, $this->node, $ranks[0][0]]];
      $records = MatchRecordCollection::new();
      $red_winner = true;
      while( count($next) )
      {
         /** @var KoNode $node */
         /* fetch/create input data */
         list($rank_idx, $node, $winner) = array_shift($next);
         $defeated = new Participant($pid++, 1, '', '');
         /* prepare expected ranks */
         $ranks[$rank_idx] ??= [];
         $ranks[$rank_idx][] = $defeated;
         /* assign participants to slots */
         $redParticipant   = $red_winner ? $winner : $defeated;
         $whiteParticipant = $red_winner ? $defeated : $winner;
         if( $node->slotRed instanceof MatchWinnerSlot )
         {
            $next[] = [$rank_idx+1, $node->slotRed->matchNode, $redParticipant];
         }
         else
         {
            $node->slotRed->method('getParticipant')->willReturn($redParticipant);
         }
         if ($node->slotWhite instanceof MatchWinnerSlot)
         {
            $next[] = [$rank_idx+1, $node->slotWhite->matchNode, $whiteParticipant];
         }
         else
         {
            $node->slotWhite->method('getParticipant')->willReturn($whiteParticipant);
         }
         /* create the match record */
         $records[] = new MatchRecord(
            1, $node->getName(), $category, $area,
            $redParticipant, $whiteParticipant, $winner,
            tie_break: false, finalized_at: new \DateTime() );

         /* alternate the winner */
         $red_winner = !$red_winner;
      }

      /* also add another unrelated match record to ensure KoNode doesn't trip over it */
      $records[] = new MatchRecord(
         1, "test_dummy", $category, $area,
         new Participant($pid++, 1, '', ''),
         new Participant($pid++, 1, '', ''),
         null,
         tie_break: false, finalized_at: null );

      /* now assign the match records */
      $this->node->setMatchRecords($records);

      /* ... and check whether the ranks are reconstructed accordingly */
      for( $i=0; $i < self::ROUNDS+1; ++$i )
      {
         $received_ranks = $this->node->getRanked($i+1);
         $this->assertSame($ranks[$i], $received_ranks->values());
      }
   }
}