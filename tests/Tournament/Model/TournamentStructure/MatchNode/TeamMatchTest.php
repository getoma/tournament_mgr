<?php

declare(strict_types=1);

namespace Tests\Tournament\Model\TournamentStructure\MatchNode;

use PHPUnit\Framework\TestCase;
use Tournament\Model\Category\Category;
use Tournament\Model\Category\CategoryMode;
use Tournament\Model\Participant\Participant;
use Tournament\Model\Participant\Team;
use Tournament\Model\TournamentStructure\MatchNode\TeamMatch;
use Tournament\Model\TournamentStructure\MatchSlot\ParticipantSlot;

/**
 * perform TeamMatch tests
 */
class TeamMatchTest extends TestCase
{
   protected function setUp(): void
   {
   }

   public function testSubMatchGeneration(int $teamSize = 3)
   {
      $category = new Category(1, 1, "-", CategoryMode::KO, true);
      $category->config->team_size = $teamSize;

      /* in the actual application, MatchNodes are first created with empty slots,
       * and in another later step the slots are filled with participants
       * We are following the same order in this test */
      $slotRed = new ParticipantSlot();
      $slotWhite = new ParticipantSlot();
      $dut = new TeamMatch("test", $category, $slotRed, $slotWhite);

      /* now create and assign the teams */
      $teamRed = new Team(1, 1, "red");
      $teamWhite = new Team(2, 1, "white");
      foreach( range(1,$teamSize) as $i )
      {
         $teamRed->members[] = new Participant(2*$i-1, 1, 'red', strval($i));
         $teamWhite->members[] = new Participant(2*$i, 1, 'white', strval($i));
      }
      $slotRed->participant = $teamRed;
      $slotWhite->participant = $teamWhite;

      /* all set up, now verify DUT behavior */
      $matches = $dut->getSubMatches();
      $teamRedIndexed = $teamRed->members->values();
      $teamWhiteIndexed = $teamWhite->members->values();

      $this->assertTrue($dut->isComposite());
      $this->assertEquals($teamSize, $matches->count());
      foreach( range(0, $teamSize-1) as $i )
      {
         $this->assertSame($teamRedIndexed[$i], $matches[$i]->getRedParticipant());
         $this->assertSame($teamWhiteIndexed[$i], $matches[$i]->getWhiteParticipant());
      }
   }

}