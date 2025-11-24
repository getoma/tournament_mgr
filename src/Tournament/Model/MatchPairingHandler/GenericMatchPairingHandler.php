<?php

namespace Tournament\Model\MatchPairingHandler;

use Tournament\Model\Participant\ParticipantCollection;
use Tournament\Model\TournamentStructure\MatchNode\MatchNodeCollection;
use Tournament\Model\TournamentStructure\MatchSlot\ParticipantSlot;
use Tournament\Model\TournamentStructure\TournamentStructureFactory;

class GenericMatchPairingHandler implements MatchPairingHandler
{
   public function __construct()
   {
   }

   public function generate(ParticipantCollection $participants, TournamentStructureFactory $nodeFactory): MatchNodeCollection
   {
      $p = $participants->values();

      /* for 3 and 4 participants, return a hardcoded, optimized schedule */
      if( $participants->count() === 3 )
      {
         return MatchNodeCollection::new([
            $nodeFactory->createMatchNode(0, new ParticipantSlot($p[0]), new ParticipantSlot($p[1])),
            $nodeFactory->createMatchNode(0, new ParticipantSlot($p[0]), new ParticipantSlot($p[2])),
            $nodeFactory->createMatchNode(0, new ParticipantSlot($p[1]), new ParticipantSlot($p[2])),
         ]);

      }
      else if( $participants->count() === 4 )
      {
         return MatchNodeCollection::new( [
            $nodeFactory->createMatchNode(0, new ParticipantSlot($p[0]), new ParticipantSlot($p[1])),
            $nodeFactory->createMatchNode(0, new ParticipantSlot($p[2]), new ParticipantSlot($p[3])),
            $nodeFactory->createMatchNode(0, new ParticipantSlot($p[0]), new ParticipantSlot($p[3])),
            $nodeFactory->createMatchNode(0, new ParticipantSlot($p[0]), new ParticipantSlot($p[2])),
            $nodeFactory->createMatchNode(0, new ParticipantSlot($p[1]), new ParticipantSlot($p[2])),
            $nodeFactory->createMatchNode(0, new ParticipantSlot($p[1]), new ParticipantSlot($p[3])),
         ]);
      }
      else
      {
         /* https://de.wikipedia.org/wiki/Jeder-gegen-jeden-Turnier#Rutschsystem */
         $result = MatchNodeCollection::new();

         $players = $participants->values();
         if (count($players) % 2) $players[] = null; // fill up to an even number of participants with one BYE slot if needed

         $numPlayers = count($players);
         $rounds = $numPlayers - 1;

         /**
          * Default setting system pairs "first" with "last" pool member in first round,
          * which may be confusing to someone not used to it
          * We can achive a "1vs2, 3vs4, ..." first round be reordering the participant list:
          */
         $red   = array_filter($players, fn($i) => !($i % 2), ARRAY_FILTER_USE_KEY);
         $white = array_filter($players, fn($i) => ($i % 2), ARRAY_FILTER_USE_KEY);
         $players = array_merge($red, array_reverse($white));

         $result = MatchNodeCollection::new();

         for ($r = 0; $r < $rounds; $r++)
         {
            // generate matches for each participant
            for ($i = 0; $i < $numPlayers / 2; $i++)
            {
               $p_red   = $players[$i];
               $p_white = $players[$numPlayers - 1 - $i];
               if ($p_red && $p_white) // no BYE
               {
                  $red       = new ParticipantSlot($p_red);
                  $white     = new ParticipantSlot($p_white);
                  $matchId   = $result->count();
                  $result[] = $nodeFactory->createMatchNode($matchId, $red, $white);
               }
            }

            // rotate participant pool
            $players = array_merge(
               [$players[0]],                // fix position of first participant
               [$players[$numPlayers - 1]],    // move last participant to first position
               array_slice($players,  1, -1) // keep rest of the list
            );
         }

         return $result;
      }
   }
}
