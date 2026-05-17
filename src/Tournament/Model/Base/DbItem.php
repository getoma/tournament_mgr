<?php

namespace Tournament\Model\Base;

interface DbItem
{
   /** get the primary key id of the db entry */
   public function id(): ?int;

   /**
    * validate a data array against the validation rules.
    * this method provides additional parameters to modify the native rules of the DbItem
    * required: if set, only these fields are taken over from the original rules.
    *           Any other fields are expected to not be provided, and providing them will result in an error
    * optional: if set, these fields will be made optional. This overules any listing in 'required', ie
    *           optional fields do not need to be listed in required
    * @param array $data     - the input data to validate
    * @param array $required - list of fields that are expected and required
    * @param array $optional - list of fields that are expected and optional
    * @return array - list of errors on validation isses, or empty array if no issues.
    */
   public static function validateArray(array $data, ?array $required = null, ?array $optional = null): array;

   /** read in an array and update the content from it */
   public function updateFromArray(array $data): void;

   /**
    * convert object to an array to provide it to PDOStatement::execute
    * @param string[] $keys - optional: list of attributes to use.
    */
   public function asArray(string ...$keys): array;
}