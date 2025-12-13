<?php

namespace Tests\Tournament\Model\MatchCreationHandler;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tournament\Model\Area\Area;
use Tournament\Model\MatchCreationHandler\GenericMatchCreationHandler;
use Tournament\Model\MatchPointHandler\MatchPointHandler;
use Tournament\Model\MatchRecord\MatchRecord;
use Tournament\Model\Participant\Participant;
use Tournament\Model\Participant\ParticipantCollection;
use Tournament\Model\TournamentStructure\MatchNode\MatchNode;
use Tournament\Model\TournamentStructure\MatchSlot\MatchSlot;
use Tournament\Model\TournamentStructure\MatchSlot\ParticipantSlot;
use Tournament\Model\TournamentStructure\TournamentStructureFactory;

class GenericMatchCreationHandlerTest extends TestCase
{
   private function createFactoryMock(): MockObject
   {
      $factMock = $this->createMock(TournamentStructureFactory::class);
      $factMock->method('createMatchNode')->willReturnCallback(
         function (
            string $name,
            MatchSlot $slotRed,
            MatchSlot $slotWhite,
            ?Area $area = null,
            bool $tie_break = false,
            ?MatchRecord $matchRecord = null,
            bool $frozen = false
         )
         {
            return new MatchNode($name, $slotRed, $slotWhite, $this->createStub(MatchPointHandler::class),
                                 $area, $tie_break, $matchRecord, $frozen);
         }
      );
      return $factMock;
   }

   /**
    * trivial match generations: 0,1,2 participants
    */
   public function testTrivial()
   {
      $tst = new GenericMatchCreationHandler();

      $fact = $this->createFactoryMock();
      $fact->expects($this->never())->method('createMatchNode');

      /** @var TournamentStructureFactory $fact */
      /* no Participant */
      $this->assertEmpty($tst->generate(new ParticipantCollection(), $fact));

      /* a single Participant */
      $p1 = $this->createStub(Participant::class);
      $this->assertEmpty($tst->generate(new ParticipantCollection([$p1]), $fact));

      /* two participants */
      $fact = $this->createFactoryMock();
      $fact->expects($this->once())->method('createMatchNode')
        ->with(name:        $this->isType('string'),
               slotRed:     $this->isInstanceOf(ParticipantSlot::class),
               slotWhite:   $this->isInstanceOf(ParticipantSlot::class),
               area:        $this->equalTo(null),
               tie_break:   $this->equalTo(false),
               MatchRecord: $this->equalTo(null),
               frozen:      $this->equalTo(false));
      $p2 = $this->createStub(Participant::class);
      /** @var TournamentStructureFactory $fact */
      $matchList = $tst->generate(new ParticipantCollection([$p1, $p2]), $fact);
      $this->assertCount(1, $matchList);
      $this->assertEquals($p1, $matchList->front()->slotRed->getParticipant());
      $this->assertEquals($p2, $matchList->front()->slotWhite->getParticipant());
   }

   /**
    * three participants: should return a fixed schedule:
    * A vs B, A vs C, B vs C
    */
   public function testThreeParticipants()
   {
      $tst = new GenericMatchCreationHandler();
      $fact = $this->createFactoryMock();
      $fact->expects($this->exactly(3))->method('createMatchNode')
         ->with(
            name: $this->isType('string'),
            slotRed: $this->isInstanceOf(ParticipantSlot::class),
            slotWhite: $this->isInstanceOf(ParticipantSlot::class),
            area: $this->equalTo(null),
            tie_break: $this->equalTo(false),
            MatchRecord: $this->equalTo(null),
            frozen: $this->equalTo(false)
         );

      $p = [
         $this->createStub(Participant::class),
         $this->createStub(Participant::class),
         $this->createStub(Participant::class)
      ];

      /** @var TournamentStructureFactory $fact */
      $matchList = $tst->generate(new ParticipantCollection($p), $fact);

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
      $tst = new GenericMatchCreationHandler();
      $fact = $this->createFactoryMock();
      $fact->expects($this->exactly(6))->method('createMatchNode')
         ->with(
            name: $this->isType('string'),
            slotRed: $this->isInstanceOf(ParticipantSlot::class),
            slotWhite: $this->isInstanceOf(ParticipantSlot::class),
            area: $this->equalTo(null),
            tie_break: $this->equalTo(false),
            MatchRecord: $this->equalTo(null),
            frozen: $this->equalTo(false)
         );

      $p = [
         $this->createStub(Participant::class),
         $this->createStub(Participant::class),
         $this->createStub(Participant::class),
         $this->createStub(Participant::class),
      ];

      /** @var TournamentStructureFactory $fact */
      $matchList = $tst->generate(new ParticipantCollection($p), $fact);

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

      $tst = new GenericMatchCreationHandler();
      $fact = $this->createFactoryMock();
      $fact->expects($this->exactly($matchCount))->method('createMatchNode')
         ->with(
            name: $this->isType('string'),
            slotRed: $this->isInstanceOf(ParticipantSlot::class),
            slotWhite: $this->isInstanceOf(ParticipantSlot::class),
            area: $this->equalTo(null),
            tie_break: $this->equalTo(false),
            MatchRecord: $this->equalTo(null),
            frozen: $this->equalTo(false)
         );

      /* generate List of participants */
      $p = array_map(function($i)
                     {
                        $a = $this->createStub(Participant::class);
                        $a->id = $i;
                        return $a;
                     },
                     range(1,$numPart));
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

      /** @var TournamentStructureFactory $fact */
      $matchList = $tst->generate(new ParticipantCollection($p), $fact);
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