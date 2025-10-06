<?php

namespace Tournament\Model\TournamentStructure\MatchSlot;

use Tournament\Model\Participant\Participant;

/* a true BYE that also cannot be filled even if new Participants are added.
 * currently only used in combined mode if the number of pools cannot fill up
 * the whole first round of the KO tree.
 */
class ByeSlot extends MatchSlot
{
   public function isBye(): bool
   {
      return true;
   }

   public function str(): string
   {
      return '--';
   }

   public function getParticipant(): ?Participant
   {
      return null;
   }
}
