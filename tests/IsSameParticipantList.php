<?php

namespace Tests;

use Base\Model\ObjectCollection;
use Tournament\Model\Participant\ParticipantCollection;

use PHPUnit\Framework\ExpectationFailedException;

use SebastianBergmann\Comparator\ComparisonFailure;
use SebastianBergmann\Comparator\Factory as ComparatorFactory;

final class IsSameParticipantList extends \PHPUnit\Framework\Constraint\Constraint
{
   private $expected;

   public function __construct(
      ParticipantCollection $expected
   )
   {
      $this->expected = $expected->values();
   }

   public function evaluate(mixed $other, string $description = '', bool $returnResult = false): ?bool
   {
      $actual = match (true)
      {
         is_array($other) => $other,
         $other instanceof ObjectCollection => $other->values(),
         default => null
      };
      if (!isset($actual)) return false;

      $comparatorFactory = ComparatorFactory::getInstance();
      try
      {
         $comparator = $comparatorFactory->getComparatorFor($this->expected, $actual);
         $comparator->assertEquals($this->expected, $actual);
      }
      catch (ComparisonFailure $f)
      {
         if ($returnResult)
         {
            return false;
         }

         throw new ExpectationFailedException(
            trim($description . "\n" . $f->getMessage()),
            $f,
         );
      }

      return true;
   }

   public function toString(bool $exportObjects = false): string
   {
      return 'is same list like (' . join(',', array_column($this->expected, 'id')) . ')';
   }

   final public static function like(ParticipantCollection $expected): static
   {
      return new static($expected);
   }
}