<?php

namespace Tournament\Model\Base;

use \Base\Service\DataValidationService;

abstract class DbItem
{
   public ?int $id = null;

   /* validation rules to check any user input against before accepting */
   abstract protected static function validationRules(): array;

   /* validate a data array against the validation rules */
   public static function validateArray(array $data): array
   {
      return DataValidationService::validate($data, static::validationRules());
   }

   /* read in an array and update the content from it */
   abstract public function updateFromArray(array $data): void;

   /* convert object to an array to provide it to PDOStatement::execute */
   public function asArray(array $keys = []): array
   {
      $result = [];

      $props = get_object_vars($this);
      $keys = $keys ?: array_keys($props);

      foreach ($keys as $key)
      {
         if (!array_key_exists($key, $props))
         {
            throw new \InvalidArgumentException(get_class($this) . ": requested unknown property '$key'");
         }

         $value = $props[$key];

         if( is_scalar($value) || $value === null )
         {
            $result[$key] = $value;
         }
         else if( $value instanceof DbItem )
         {
            $result[$key] = $value->id;
         }
         else if( $value instanceof \UnitEnum )
         {
            $result[$key] = $value instanceof \BackedEnum? $value->value : $value->name;
         }
         else if ($value instanceof \DateTime)
         {
            $result[$key] = $value->format('Y-m-d H:i:s');
         }
         else
         {
            // allow derived class to hook in additional conversions
            $result[$key] = $this->convertValue($key, $value);
         }
      }
      return $result;
   }

   protected function convertValue($key, $value): mixed
   {
      throw new \UnexpectedValueException(get_class($this) . ": Unexpected DbItem attribute for key '$key' of type " . get_class($value) ?? gettype($value));
   }

}