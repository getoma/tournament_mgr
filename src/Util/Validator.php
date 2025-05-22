<?php

namespace App\Util;

use Respect\Validation\Exceptions\NestedValidationException;

class Validator
{
   /**
    * validates input data against a set of validators
    *
    * @param array $data Input data, e.g. $_POST
    * @param array $validators associative array: field => Respect\Validator
    * @return array associative array of errors: field => error message
    */
   public static function validate(array $data, array $validators): array
   {
      $errors = [];

      foreach ($validators as $field => $validator)
      {
         try
         {
            $validator->assert($data[$field] ?? null);
         }
         catch (NestedValidationException $e)
         {
            $errors[$field] = implode(', ', $e->getMessages());
         }
      }

      return $errors;
   }
}
