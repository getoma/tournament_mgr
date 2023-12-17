<?php

const   RED = 0;
const WHITE = 1;

class Fight
{
   private $in = [null,null];
   private $winner = null;
   private $id = null;

   function __construct( int $id, $in1 = null, $in2 = null )
   {
      $this->id = $id;
      $this->in = [ $in1, $in2 ];
   }

   public function getId()
   {
      return $this->id;
   }

   /***
    * TODO: cannot differenciate between "wildcard (=null)" and "no winner yet"
    */
   public function getWinner()
   {
      $winner_id = null;

      if( is_null($this->in[WHITE]) && !is_null( $this->in[RED]) )
      {
         $winner_id = RED;
      }
      else if( !is_null($this->in[WHITE]) && is_null( $this->in[RED]) )
      {
         $winner_id = WHITE;
      }
      else
      {
         $winner_id = $this->winner;
      }

      if( is_null($winner_id) ) return null;
      $prev = $this->in[$winner_id];
      if( $prev instanceof Fight ) return $prev->getWinner();
      if( $prev instanceof Pool )
      {

      }
      return $prev;
   }

   public function getRound()
   {
      if( $this->in[RED] instanceof Fight ) return $this->in[RED]->getRound() + 1;
      else return 1;
   }

   public function setPrevious( $prev, $color = -1 )
   {
      if( $color == -1 )
      {
         if( !isset($this->in[0]) ) $color = 0;
         else if( !isset($this->in[1]) ) $color = 1;
         else throw \LogicException("cannot assign new previous fight - full");
      }

      $this->in[$color] = $prev;
   }

   public function getPrevious( $color )
   {
      return $this->in[$color];
   }

   public function getParticipant( $color )
   {

   }

   public function setWinner( $color )
   {
      $this->winner = $color;
   }

}