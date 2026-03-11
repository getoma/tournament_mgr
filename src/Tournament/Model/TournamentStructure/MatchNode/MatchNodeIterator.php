<?php

namespace Tournament\Model\TournamentStructure\MatchNode;

class MatchNodeIterator implements \SeekableIterator, \Base\Model\ChainableIterator
{
   use \Base\Model\ChainableIteratorTrait;

   private readonly MatchNodeCollection $matches;

   public function __construct(
      MatchNodeCollection $matches,
      private int $position = 0,
   )
   {
      $this->matches = MatchNodeCollection::new($matches->values()); // enforce re-indexing for our position handling
   }

   public function current(): ?MatchNode
   {
      return $this->matches[$this->position] ?? null;
   }

   public function key(): int
   {
      return $this->position;
   }

   public function next(): void
   {
      ++$this->position;
   }

   public function prev(): void
   {
      --$this->position;
   }

   public function rewind(): void
   {
      $this->position = 0;
   }

   public function valid(): bool
   {
      return $this->matches->offsetExists($this->position);
   }

   public function seek($position): void
   {
      if ($position < 0 || $position > $this->matches->count())
      {
         throw new \OutOfRangeException("Invalid position $position");
      }
      $this->position = $position;
   }

   public function goto(string $name): void
   {
      $this->rewind();
      while ($cur = $this->current()) // as long as we point to a valid node...
      {
         if ($cur->getName() === $name) return; // check if it is the one we are looking for
         $this->next(); // if not, continue to next
      }
      throw new \OutOfRangeException("Invalid match '$name'"); // node not found
   }

   public function findNode(string $name): self
   {
      $new = clone $this;
      $new->goto($name);
      return $new;
   }
}
