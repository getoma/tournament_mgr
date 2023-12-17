<?php

class Participant
{
   private $name = null;

   function __construct(string $name)
   {
      $this->setName($name);
   }

   public function setName( string $name )
   {
      $this->name = $name;
   }

   public function getName()
   {
      return $this->name;
   }

   public function isPlaceholder()
   {
      return is_null($this->name);
   }
}