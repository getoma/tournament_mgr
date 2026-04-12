<?php declare(strict_types=1);

namespace Base\Model;

/**
 * trait of a simple class that just serves as a container for simple properties ("C struct"),
 * that shall be serializable from/to json and/or a php array
 */
trait FlatJsonTrait
{
   private \ReflectionObject $ro {
      get { // lazy-load it on first access
         $this->ro ??= new \ReflectionObject($this);
         return $this->ro;
      }
   }

   /**
    * property setting helper to enforce property type
    * to be used as property set hook
    */
   private function setter(string $key, $value)
   {
      $prop = $this->ro->getProperty($key);
      if ($value === null) return $prop->getDefaultValue();
      settype($value, (string)$prop->getType());
      return $value;
   }

   /**
    * update object properties from a php associative array
    */
   public function updateFromArray(array $data): void
   {
      foreach (array_keys(self::validationRules()) as $key)
      {
         if (isset($data[$key])) $this->$key = $data[$key];
      }
   }

   /**
    * convert into a php array with only public properties
    */
   public function toArray(): array
   {
      $props = $this->ro->getProperties(\ReflectionProperty::IS_PUBLIC);
      $keys = array_map(fn($p) => $p->getName(), $props);
      $data = array_map(fn($p) => $p->getValue($this), $props);
      return array_combine($keys, $data);
   }

   /**
    * load an Object from a json structure or php array
    */
   public static function load(string|array $data): static
   {
      $result = new static();
      if( is_string($data) ) $data = json_decode($data, associative: true, depth: 2, flags: JSON_THROW_ON_ERROR);
      $result->updateFromArray($data);
      return $result;
   }

   /**
    * serialize current object to a json structure
    */
   public function json(): string
   {
      return json_encode($this->toArray());
   }
}