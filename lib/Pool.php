<?php

class Pool
{
   /** @var array[Fight] */
   private $fights = [];

   /** @var array[Participant] */
   private $participants = [];

   /** @var integer */
   private $id;

   public function __construct(int $id)
   {
      $this->id = $id;
   }

   public function getParticipantByPlace( $place = 0 )
   {

   }

   public function getId()
   {
      return $this->id;
   }

   public function getFights()
   {
      return $this->fights;
   }

   public function getParticipants()
   {
      return $this->participants;
   }

   public function clean()
   {
      $this->participants = [];
      $this->fights = [];
   }

   public function addParticipant( Participant $new_p )
   {
      /* generate a fight between each participant */
      foreach( $this->participants as $p )
      {
         $fight_id = $this->getId() * 1000 + count($this->fights);
         $this->fights[] = new Fight( $fight_id, $p, $new_p);
      }

      /* add participant to our list */
      $this->participants[] = $new_p;
   }
}