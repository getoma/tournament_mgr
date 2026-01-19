<?php

namespace Tournament\Model\TournamentStructure\MatchNode;

class MatchNodeIterator implements \SeekableIterator, \Base\Model\ChainableIterator
{
   public function __construct(private MatchNodeCollection $matches, private int $position = 0)
   {
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
      return isset($this->matches[$this->position]);
   }

   public function seek($position): void
   {
      if ($position < 0 || $position > count($this->matches))
      {
         throw new \OutOfBoundsException("Invalid position $position");
      }
      $this->position = $position;
   }

   public function goto(string $name): void
   {
      for( $i = 0; $i < $this->matches->count(); ++$i) // do NOT use foreach loop, or this will spawn another MatchNodeIterator
      {
         if ($this->matches[$i]->getName() === $name)
         {
            $this->position = $i;
            return;
         }
      }
      throw new \OutOfBoundsException("Invalid match $name");
   }

   public function skip(int $count = 1): self
   {
      return new self($this->matches, $this->position+$count);
   }

   public function back(int $count = 1): self
   {
      return new self($this->matches, $this->position-$count);
   }

   public function jump(int $position): self
   {
      return new self($this->matches, $position);
   }

   public function findNode(string $name): self
   {
      $new = new self($this->matches);
      $new->goto($name);
      return $new;
   }
}
