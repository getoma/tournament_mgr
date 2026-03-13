<?php

namespace Tournament\Model\Base;

interface DbItem
{
   /* read in an array and update the content from it */
   function updateFromArray(array $data): void;

   /* convert object to an array to provide it to PDOStatement::execute */
   function asArray(array $keys = []): array;

   /* validate a data array against the validation rules */
   static function validateArray(array $data): array;
}