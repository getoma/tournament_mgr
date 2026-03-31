<?php declare(strict_types=1);

namespace Tournament\Model\TournamentStructure;

use Tournament\Model\Area\Area;
use Tournament\Model\MatchRecord\MatchRecordCollection;
use Tournament\Model\TournamentStructure\MatchNode\KoNode;
use Tournament\Model\TournamentStructure\MatchNode\MatchNode;
use Tournament\Model\TournamentStructure\MatchNode\MatchNodeCollection;
use Tournament\Model\TournamentStructure\MatchNode\MatchRoundCollection;
use Tournament\Model\TournamentStructure\MatchNode\SoloMatch;
use Tournament\Model\TournamentStructure\MatchParticipant\MatchParticipantCollection;
use Tournament\Model\TournamentStructure\MatchSlot\MatchWinnerSlot;

/**
 * provide methods for traversing a KO structure
 * This trait expects the infrastructure provided by MatchNode
 */
class KoTree
{
   private ?Area   $area;
   private ?string $name;

   public function __construct(public readonly KoNode $root, ?string $name = null, ?Area $area = null)
   {
      if( isset($name) ) $this->setName($name);
      if( isset($area) ) $this->setArea($area);
   }

   /**
    * Return a unique name for this (sub-)tree
    */
   public function getName(): string
   {
      return $this->name;
   }

   /**
    * set a name for this subtree, and also integrate its name into all match names
    */
   public function setName(string $name): void
   {
      $this->name = $name;

      if (isset($name))
      {
         $local_match_idx = 1;
         /** @var MatchNode $node */
         foreach ($this->getMatchList() as $node)
         {
            $node->setName($name . '-' . $local_match_idx++);
         }
      }
   }

   /**
    * get the default area for this tree, if set
    */
   public function getArea(): ?Area
   {
      return $this->area;
   }

   /**
    * globally set the area for all matches in this (sub-)tree
    */
   public function setArea(?Area $area): void
   {
      $this->area = $area;

      if (isset($area))
      {
         /** @var MatchNode $node */
         foreach ($this->getMatchList() as $node)
         {
            $node->setArea($area);
         }
      }
   }

   /**
    * Return the rounds of matches in this knockout (sub)structure.
    * Each round is an array of MatchNode objects.
    * The first round is the first array, the second round is the second array, etc.
    * The last round is the final match represented by $this object.
    */
   public function getRounds(int $offset = 0, ?int $length = null): MatchRoundCollection
   {
      $rounds = MatchRoundCollection::new();
      $currentRound = MatchNodeCollection::new([$this->root]);
      while (!$currentRound->empty())
      {
         $rounds->unshift($currentRound);
         $nextRound = MatchNodeCollection::new();
         /** @var MatchNode $node */
         foreach ($currentRound as $node)
         {
            foreach($node->getSlots() as $slot)
            {
               if( $slot instanceof MatchWinnerSlot )
               {
                  $nextRound[] = $slot->matchNode;
               }
            }
         }
         $currentRound = $nextRound;

         if (($offset < 0) && ($rounds->count() >= -$offset))
         {
            /* abort early if we are asked to cut rounds counting from the back */
            break;
         }
      }

      if ($offset < 0) $offset = 0; // in this case, we didn't even collect anything beyond the offset

      return $rounds->slice($offset, $length);
   }

   /**
    * explicitly return the first round of this KO tree
    */
   public function getFirstRound(): MatchNodeCollection
   {
      return $this->getRounds()->first();
   }

   /**
    * find a specific node by its name in this KO tree (or a subtree)
    */
   public function findByName(string $name, ?KoNode $root = null): ?KoNode
   {
      $root ??= $this->root;
      if ($name === $root->getName()) return $root;
      foreach ($root->getSlots() as $slot)
      {
         if ($slot instanceof MatchWinnerSlot && $node = $this->findByName($name, $slot->matchNode) )
         {
            return $node;
         }
      }
      return null;
   }

   /**
    * recursively collect all participants in this KO tree (or a subtree)
    * @return array of Participant objects
    */
   public function getParticipantList(?KoNode $root = null): array
   {
      $root ??= $this->root;
      $participants = [];
      foreach ($root->getSlots() as $slot)
      {
         if ($slot instanceof MatchWinnerSlot)
         {
            array_push($participants, ...$this->getParticipantList($slot->matchNode));
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
    */
   public function getRanked(int $rank, ?KoNode $root = null): MatchParticipantCollection
   {
      $root ??= $this->root;
      $result = MatchParticipantCollection::new();
      if ($rank === 1)
      {
         $winner = $root->getWinner();
         if ($winner) $result[] = $winner;
      }
      else if ($rank === 2)
      {
         $defeated = $root->getDefeated();
         if ($defeated) $result[] = $defeated;
      }
      else
      {
         /* from here, recursively collect the ranks from the red/white subtrees */
         foreach ($root->getSlots() as $slot)
         {
            if ($slot instanceof MatchWinnerSlot)
            {
               $result->mergeInPlace( $this->getRanked($rank-1, $slot->matchNode) );
            }
         }
      }
      return $result;
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
      foreach ($this->getMatchList() as $match)
      {
         if ($match instanceof SoloMatch)
         {
            if( $matchRecords->keyExists($match->getName()) )
            {
               $match->setMatchRecord($matchRecords[$match->getName()]);
            }
         }
         else
         {
            throw new \LogicException("invalid match for record assignment");

         }
      }
   }
}