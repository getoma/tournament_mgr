<?php

namespace Tournament\Model\TournamentStructure\MatchNode;

/**
 * Iterate over MatchNodes in a MatchRoundCollection
 */
class MatchRoundIterator implements \SeekableIterator, \Base\Model\ChainableIterator
{
   use \Base\Model\ChainableIteratorTrait;

   private readonly MatchRoundCollection $rounds;
   private int $round = 0;
   private int $pos = 0;

   public function __construct(
      MatchRoundCollection $rounds,
      int $position = 0,
   )
   {
      /* enforce re-indexing of each round, and translate the linear position */
      $this->rounds = MatchRoundCollection::new();
      foreach( $rounds as $round )
      {
         $this->rounds[] = MatchNodeCollection::new($round->values());
      }
      $this->seek($position);
   }

   public function current(): ?MatchNode
   {
      return $this->rounds->offsetGet($this->round)?->offsetGet($this->pos) ?? null;
   }

   public function key(): int
   {
      $result = $this->pos;
      if( $this->round > 0 )
      {
         $result += array_reduce(range(0, $this->round - 1), fn($result, $i) => $result + $this->rounds[$i]->count(), 0);
      }
      return $result;
   }

   public function roundIdx(): int
   {
      return $this->round;
   }

   public function roundCount(): int
   {
      return $this->rounds->count();
   }

   public function nodeIdx(): int
   {
      return $this->pos;
   }

   public function nodeCount(): int
   {
      return $this->rounds->offsetGet($this->round)?->count() ?? 0;
   }

   public function next(): void
   {
      ++$this->pos;
      if( $this->pos >= ($this->rounds[$this->round]?->count() ?? 0) )
      {
         /* go to next round, skip any empty round (this may happen for a partial view on the tree) */
         $rndCount = $this->roundCount();
         while( ++$this->round < $rndCount && $this->rounds[$this->round]->empty() );
         $this->pos = 0;
      }
   }

   public function prev(): void
   {
      if( $this->pos === 0 )
      {
         /* go to previous round, skip any empty round (this may happen for a partial view on the tree) */
         while( --$this->round >= 0 && $this->rounds[$this->round]->empty() );
         $this->pos = ($this->rounds[$this->round]?->count()-1) ?? 0;
      }
      else
      {
         --$this->pos;
      }
   }

   public function rewind(): void
   {
      $this->round = 0;
      $this->pos = 0;
   }

   public function valid(): bool
   {
      return $this->rounds->offsetExists($this->round) && $this->rounds[$this->round]->offsetExists($this->pos);
   }

   public function seek($position): void
   {
      $r = 0;
      $p = 0;

      foreach ($this->rounds as $round)
      {
         if ($position >= $round->count())
         {
            $r += 1;
            $position -= $round->count();
         }
         else if ($position > 0)
         {
            $p = $position;
            $position = 0;
            break;
         }
      }

      if( $position !== 0 )
      {
         throw new \OutOfRangeException('invalid position');
      }

      $this->round = $r;
      $this->pos = $p;
   }

   public function goto(string $name): void
   {
      $this->rewind();
      while( $cur = $this->current() ) // as long as we point to a valid node...
      {
         if( $cur->getName() === $name ) return; // check if it is the one we are looking for
         $this->next(); // if not, continue to next
      }
      throw new \OutOfRangeException("Invalid match '$name'"); // node not found
   }

   public function findNode(string $name): self
   {
      $copy = clone $this;
      $copy->goto($name);
      return $copy;
   }
}
