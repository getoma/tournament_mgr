<?php

namespace Tournament\Model\TournamentStructure;

use Tournament\Model\TournamentStructure\MatchSlot\ParticipantSlot;
use Tournament\Model\Data\Participant;
use Tournament\Model\Data\Area;
use Tournament\Model\Data\MatchRecordCollection;
use Tournament\Model\Data\ParticipantCollection;
use Tournament\Model\Data\SlottedParticipantCollection;

class Pool
{
   public array $matches = [];

   public function __construct(
      private string $name,
      public  SlottedParticipantCollection $participants = new SlottedParticipantCollection(),
      private ?Area $area = null
   )
   {
   }

   /**
    * return a unique name for this pool, derived from the chunk id.
    */
   public function getName(): string
   {
      return $this->name;
   }

   /**
    * set the name of the pool
    */
   public function setName(string $name): void
   {
      $this->name = $name;

      $local_match_idx = 1;
      foreach ($this->matches as $node)
      {
         $node->setName($name . "." . $local_match_idx++);
      }
   }

   public function getArea(): ?Area
   {
      return $this->area;
   }

   public function setArea(?Area $area): void
   {
      $this->area = $area;
      foreach ($this->matches as $node)
      {
         $node->setArea($area);
      }
   }

   /**
    * recursively collect all participants in this match tree
    * @return ParticipantCollection of Participant objects
    */
   public function getParticipantList(): ParticipantCollection
   {
      return $this->participants->all();
   }

   public function getMatchList(): array
   {
      return $this->matches;
   }

   /**
    * get the participant of rank $rank
    */
   public function getRanked($rank = 1): ?Participant
   {
      /* TODO: derive ranked list from match results */
      return null;
   }

   /**
    * generate the matches in this Pool.
    */
   public function generateMatches(): void
   {
      /* https://de.wikipedia.org/wiki/Jeder-gegen-jeden-Turnier#Rutschsystem */
      $this->matches = [];

      $players = $this->participants->values();
      if( count($this->participants) % 2 ) $players[] = null; // fill up to an even number of participants

      $numPlayers = count($players);
      $rounds = $numPlayers - 1;

      for ($r = 0; $r < $rounds; $r++)
      {
         // generate matches for each participant
         for ($i = 0; $i < $numPlayers / 2; $i++)
         {
            $p_red   = $players[$i];
            $p_white = $players[$numPlayers - 1 - $i];
            if ($p_red && $p_white) // no BYE
            {
               $red     = new ParticipantSlot($p_red);
               $white   = new ParticipantSlot($p_white);
               $matchId = count($this->matches);
               $this->matches[] = new MatchNode($this->name.".".$matchId, $red, $white, $this->area);
            }
         }

         // rotate participant pool
         $players = array_merge(
            [$players[0]],                // fix position of first participant
            [$players[$numPlayers-1]],    // move last participant to first position
            array_slice($players,  1, -1) // keep rest of the list
         );
      }
   }

   /**
    * Assign match records to the matches in this Pool structure.
    */
   public function setMatchRecords(MatchRecordCollection $matchRecords): void
   {
      foreach ($this->matches as $match)
      {
         if ($matchRecords->has($match->name))
         {
            $match->setMatchRecord($matchRecords[$match->name]);
         }
      }
   }
}
