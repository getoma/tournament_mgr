<?php

namespace Tournament\Model\TournamentStructure;

use Tournament\Model\TournamentStructure\MatchSlot\MatchWinnerSlot;
use Tournament\Model\Data\Participant;

class KoNode extends MatchNode
{
   /**
    * Return the rounds of matches in this knockout (sub)structure.
    * Each round is an array of MatchNode objects.
    * The first round is the first array, the second round is the second array, etc.
    * The last round is the final match represented by $this object.
    * @return array of array of MatchNode
    */
   public function getRounds(int $offset = 0, ?int $length = null): array
   {
      $rounds = [];
      $currentRound = [$this];
      while (count($currentRound) > 0)
      {
         $rounds[] = $currentRound;
         $nextRound = [];
         foreach ($currentRound as $match)
         {
            if ($match->slotRed instanceof MatchWinnerSlot)
            {
               /** @var MatchWinnerSlot $match->slotRed */
               $nextRound[] = $match->slotRed->matchNode;
            }
            if ($match->slotWhite instanceof MatchWinnerSlot)
            {
               /** @var MatchWinnerSlot $match->slotWhite */
               $nextRound[] = $match->slotWhite->matchNode;
            }
         }
         $currentRound = $nextRound;

         if( ($offset < 0) && (count($rounds) >= -$offset) )
         {
            /* abort early if we are asked to cut rounds counting from the back */
            break;
         }
      }

      if( $offset < 0 ) $offset = 0; // in this case, we didn't even collect anything beyond the offset

      return array_slice( array_reverse($rounds), $offset, $length );
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
    * @return Participant[]
    */
   public function getRanked($rank = 1): array
   {
      /* TODO: derive from Database */
      return [];
   }

   /**
    * Return a flat list of all matches in this knockout (sub)structure.
    */
   public function getMatchList(): array
   {
      return array_merge(...$this->getRounds());
   }
}
