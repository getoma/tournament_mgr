<?php

namespace Tests\Tournament\Model\MatchCreationHandler;

use Tournament\Model\Category\Category;
use Tournament\Model\MatchCreationHandler\GenericMatchCreationHandler;
use Tournament\Model\Participant\Participant;
use Tournament\Model\Participant\ParticipantCollection;

use PHPUnit\Framework\TestCase;

class GenericMatchCreationHandlerTest extends TestCase
{
   /**
    * trivial match generations: 0,1,2 participants
    */
   public function testTrivial()
   {
      $tst = new GenericMatchCreationHandler($this->createStub(Category::class));

      /* no Participant */
      $this->assertEmpty($tst->generate(new ParticipantCollection()));

      /* a single Participant */
      $p1 = new Participant(1, 1, '', '');
      $this->assertEmpty($tst->generate(new ParticipantCollection([$p1])));

      /* two participants */
      $p2 = new Participant(2, 1, '', '');
      $matchList = $tst->generate(new ParticipantCollection([$p1, $p2]));
      $this->assertCount(1, $matchList);
      $this->assertEquals($p1, $matchList->first()->slotRed->getParticipant());
      $this->assertEquals($p2, $matchList->first()->slotWhite->getParticipant());
   }

   /**
    * three participants: should return a fixed schedule:
    * A vs B, A vs C, B vs C
    */
   public function testThreeParticipants()
   {
      $tst = new GenericMatchCreationHandler($this->createStub(Category::class));

      $p = array_map(fn($i) => new Participant($i, 1, '', ''), range(1,3));
      $matchList = $tst->generate(new ParticipantCollection($p));

      $this->assertCount(3, $matchList);
      /* A vs B */
      $this->assertEquals($p[0], $matchList[0]->slotRed->getParticipant());
      $this->assertEquals($p[1], $matchList[0]->slotWhite->getParticipant());
      /* A vs C */
      $this->assertEquals($p[0], $matchList[1]->slotRed->getParticipant());
      $this->assertEquals($p[2], $matchList[1]->slotWhite->getParticipant());
      /* B vs C */
      $this->assertEquals($p[1], $matchList[2]->slotRed->getParticipant());
      $this->assertEquals($p[2], $matchList[2]->slotWhite->getParticipant());
   }

   /**
    * four participants: should return a fixed schedule:
    * A vs B, C vs D, A vs D, A vs C, B vs C, B vs D
    */
   public function testFourParticipants()
   {
      $tst = new GenericMatchCreationHandler($this->createStub(Category::class));

      $p = array_map(fn($i) => new Participant($i, 1, '', ''), range(1, 4));
      $matchList = $tst->generate(new ParticipantCollection($p));

      $this->assertCount(6, $matchList);
      /* A vs B */
      $this->assertEquals($p[0], $matchList[0]->slotRed->getParticipant());
      $this->assertEquals($p[1], $matchList[0]->slotWhite->getParticipant());
      /* C vs D */
      $this->assertEquals($p[2], $matchList[1]->slotRed->getParticipant());
      $this->assertEquals($p[3], $matchList[1]->slotWhite->getParticipant());
      /* A vs D */
      $this->assertEquals($p[0], $matchList[2]->slotRed->getParticipant());
      $this->assertEquals($p[3], $matchList[2]->slotWhite->getParticipant());
      /* A vs C */
      $this->assertEquals($p[0], $matchList[3]->slotRed->getParticipant());
      $this->assertEquals($p[2], $matchList[3]->slotWhite->getParticipant());
      /* B vs C */
      $this->assertEquals($p[1], $matchList[4]->slotRed->getParticipant());
      $this->assertEquals($p[2], $matchList[4]->slotWhite->getParticipant());
      /* B vs D */
      $this->assertEquals($p[1], $matchList[5]->slotRed->getParticipant());
      $this->assertEquals($p[3], $matchList[5]->slotWhite->getParticipant());
   }

   /**
    * five participants:
    * still not possible without participants having consecutive matches
    * TODO
    */
   public function _testFiveParticipants()
   {
   }

   /**
    * six (or more) participants:
    * for now, no expected schedule pre-defined, just check some general fairness/plausibility rules:
    * - no participant should have consecutive matches
    * - each paring should happen exactly once
    */
   public function testManyParticipants()
   {
      $numPart = 6;
      $matchCount = $numPart*($numPart-1)/2;

      $tst = new GenericMatchCreationHandler($this->createStub(Category::class));

      /* generate List of participants */
      $p = array_map(fn($i) => new Participant($i, 1, '', ''), range(1,$numPart));
      /* generate a list of all pairings */
      $pairings = [];
      for( $i = 0; $i < $numPart; ++$i)
      {
         for( $j = $i+1; $j < $numPart; ++$j)
         {
            $id1 = $p[$i]->id;
            $id2 = $p[$j]->id;
            $pairings["{$id1},{$id2}"] = true;
         }
      }

      $matchList = $tst->generate(new ParticipantCollection($p));
      $previous = [];

      $this->assertCount($matchCount, $matchList);
      foreach($matchList as $match)
      {
         /* participants were not in previous match */
         $this->assertFalse(in_array($match->slotRed->getParticipant(), $previous));
         $this->assertFalse(in_array($match->slotWhite->getParticipant(), $previous));
         $previous = [$match->slotRed->getParticipant(), $match->slotWhite->getParticipant()];

         /* remove this pairing from our checklist */
         $id1 = $match->slotRed->getParticipant()->id;
         $id2 = $match->slotWhite->getParticipant()->id;
         unset($pairings["{$id1},{$id2}"]);
         unset($pairings["{$id2},{$id1}"]);
      }
      $this->assertEmpty($pairings);
   }

}