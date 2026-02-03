<?php

namespace Tournament\Model\Base;

use \Base\Service\DataValidationService;

use Respect\Validation\Validator as v;

trait DbItemTrait
{
   /* validation rules to check any user input against before accepting */
   abstract protected static function validationRules(): array;

   /* get the id, expect each user to have a corresponding attribute */
   public function id(): ?int
   {
      return $this->id ?? null;
   }

   /**
    * validate a data array against the validation rules.
    * this method provides additional parameters to modify the native rules of the DbItem
    * required: if set, only these fields are taken over from the original rules.
    *           Any other fields are expected to not be provided, and providing them will result in an error
    * optional: if set, these fields will be made optional. This overules any listing in 'required', ie
    *           optional fields do not need to be listed in required
    * @param $data     - the input data to validate
    * @param $required - list of fields that are expected and required
    * @param $optional - list of fields that are expected and optional
    */
   public static function validateArray(array $data, ?array $required = null, ?array $optional = null): array
   {
      $rules = static::validationRules();

      if ($required !== null)
      {
         $expected = array_flip(array_merge($required, $optional??[]));
         foreach ($rules as $key => &$rule)
         {
            if (!isset($expected[$key])) $rule = v::nullType();
         }
      }

      if ($optional !== null)
      {
         foreach ($optional as $key)
         {
            if (isset($rules[$key])) $rules[$key] = v::optional($rules[$key]);
         }
      }

      return DataValidationService::validate($data, $rules);
   }

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

         if (is_bool($value))
         {
            $result[$key] = $value ? 1 : 0;
         }
         else if (is_scalar($value) || $value === null)
         {
            $result[$key] = $value;
         }
         else if ($value instanceof DbItem)
         {
            $result[$key] = $value->id;
         }
         else if ($value instanceof \UnitEnum)
         {
            $result[$key] = $value instanceof \BackedEnum ? $value->value : $value->name;
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