<?php declare(strict_types=1);

namespace Base\Model;

class ChangeLogCollection extends ObjectCollection
{
   protected const DEFAULT_ELEMENTS_TYPE = ChangeLogEntry::class;

   /**
    * the entitiy type of this collection - not defined for this base class
    */
   static public function getEntityType(): ?string
   {
      return null;
   }

   /**
    * upgrade to a derived ChangeLogCollection
    */
   static public function from(ChangeLogCollection $orig): static
   {
      $result = static::new();
      $type = static::getEntityType();
      $result->elements = $orig->filter(fn($e) => $type === null || $e->entity_type === $type)->values();
      return $result;
   }

   /**
    * compress the change log collection by removing duplicate/obsolete entries,
    * or combining consecutive changes to the same entity
    */
   public function compress(): static
   {
      /* to be implemented in derived log collections, does nothing for the base log collection */
      throw new \LogicException('attempt to compress generic ChangeLogCollection');
   }

   /**
    * sort according change date - ascending by default.
    * @param bool $desc - (optional) if true, sort descending instead of ascending
    */
   public function sorted(bool $desc = false): static
   {
      $sorter = $desc ? fn($a, $b) => $b->changed_at <=> $a->changed_at
              :         fn($a, $b) => $a->changed_at <=> $b->changed_at;
      return $this->usort($sorter);
   }
}