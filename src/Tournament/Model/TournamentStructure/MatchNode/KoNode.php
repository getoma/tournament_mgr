<?php

namespace Tournament\Model\TournamentStructure\MatchNode;

use Tournament\Model\Area\Area;
use Tournament\Model\MatchRecord\MatchRecord;
use Tournament\Model\MatchRecord\MatchRecordCollection;
use Tournament\Model\Participant\Participant;
use Tournament\Model\Participant\ParticipantCollection;

use Tournament\Model\MatchPointHandler\MatchPointHandler;

use Tournament\Model\TournamentStructure\MatchSlot\MatchSlot;
use Tournament\Model\TournamentStructure\MatchSlot\MatchWinnerSlot;
use Tournament\Model\TournamentStructure\MatchNode\MatchRoundCollection;

/**
 * MatchNode extension to handle an actual KO tree
 * extends MatchNode with methods to traverse the tree
 */
class KoNode extends MatchNode
{
   // use constructor to forward parentNode links to child nodes
   public function __construct( string $name,
                                MatchSlot $slotRed,
                                MatchSlot $slotWhite,
                                MatchPointHandler $mpHdl, // MatchPoint Handler to parse match points
                                ?Area $area = null,
                                ?MatchRecord $matchRecord = null)
   {
      parent::__construct($name, $slotRed, $slotWhite, $mpHdl, $area, false, $matchRecord);
   }

   /* KO matches always need a winner to be completed */
   public function tiesAllowed(): bool
   {
      return false;
   }

   /**
    * Return the rounds of matches in this knockout (sub)structure.
    * Each round is an array of MatchNode objects.
    * The first round is the first array, the second round is the second array, etc.
    * The last round is the final match represented by $this object.
    * @return MatchRoundCollection
    */
   public function getRounds(int $offset = 0, ?int $length = null): MatchRoundCollection
   {
      $rounds = MatchRoundCollection::new();
      $currentRound = MatchNodeCollection::new([$this]);
      while (!$currentRound->empty())
      {
         $rounds->unshift($currentRound);
         $nextRound = MatchNodeCollection::new();
         /** @var KoNode $match */
         foreach ($currentRound as $match)
         {
            if ($match->slotRed instanceof MatchWinnerSlot)
            {
               $nextRound[] = $match->slotRed->matchNode;
            }
            if ($match->slotWhite instanceof MatchWinnerSlot)
            {
               $nextRound[] = $match->slotWhite->matchNode;
            }
         }
         $currentRound = $nextRound;

         if( ($offset < 0) && ($rounds->count() >= -$offset) )
         {
            /* abort early if we are asked to cut rounds counting from the back */
            break;
         }
      }

      if( $offset < 0 ) $offset = 0; // in this case, we didn't even collect anything beyond the offset

      return $rounds->slice($offset, $length);
   }

   /**
    * find a specific node by its name
    */
   public function findByName(string $name): ?KoNode
   {
      if( $name === $this->name ) return $this;
      $node = null;
      foreach ([$this->slotRed, $this->slotWhite] as $slot)
      {
         if ($slot instanceof MatchWinnerSlot)
         {
            $node = $slot->matchNode->findByName($name);
            if( $node ) break;
         }
      }
      return $node;
   }

   /**
    * recursively collect all participants in this match tree
    * @return array of Participant objects
    */
   public function getParticipantList(): array
   {
      $participants = [];
      foreach ([$this->slotRed, $this->slotWhite] as $slot)
      {
         if ($slot instanceof MatchWinnerSlot)
         {
            array_push($participants, ...$slot->matchNode->getParticipantList());
         }
         else
         {
            $p = $slot->getParticipant();
            if ($p !== null)
            {
               $participants[] = $p;
            }
         }
      }
      return $participants;
   }

   /**
    * get a participants of a specific rank (1=winner, 2=runner-up, 3=third place, ...)
    * @return ParticipantCollection
    */
   public function getRanked(int $rank): ParticipantCollection
   {
      $result = [];
      if ($rank === 1)
      {
         $winner = $this->getWinner();
         if( $winner ) $result[] = $winner;
      }
      else if ($rank === 2)
      {
         $defeated = $this->getDefeated();
         if( $defeated ) $result[] = $defeated;
      }
      else
      {
         /* from here, recursively collect the ranks from the red/white subtrees */
         foreach ([$this->slotRed, $this->slotWhite] as $slot)
         {
            if ($slot instanceof MatchWinnerSlot)
            {
               $result = array_merge($result, $slot->matchNode->getRanked($rank - 1)->values());
            }
         }
      }
      return new ParticipantCollection($result);
   }

   /**
    * Return a flat list of all matches in this knockout (sub)structure.
    */
   public function getMatchList(): MatchNodeCollection
   {
      return $this->getRounds()->flatten();
   }

   /**
    * Assign match records to the matches in this KO structure.
    */
   public function setMatchRecords(MatchRecordCollection $matchRecords): void
   {
      /** @var KoNode $match */
      foreach ($this->getMatchList() as $match)
      {
         if ($matchRecords->keyExists($match->name))
         {
            $match->setMatchRecord($matchRecords[$match->name]);
         }
      }
   }
}
