<?php

require_once "Participant.php";
require_once "Fight.php";
require_once "Pool.php";

class Tournament
{
   /** @var array[Participant] */
   private $participants = []; // TODO: make the list a class?

   /** @var array[Pool] */
   private $pools = []; // TODO: make the list a class?

   /** @var array[Fight] */
   private $fights = []; // TODO: make the list a class?

   public function addParticipant( Participant $p )
   {
      $this->participants[] = $p;
   }

   public function getPools()
   {
      return $this->pools;
   }

   public function getParticipants()
   {
      return $this->participants;
   }

   public function getFights()
   {
      $this->fights;
   }

   public function getFightsPerRound( $round = null )
   {
      $result = [];
      foreach( $this->fights as $f )
      {
         $f_r = $f->getRound();
         if( is_null($round) || ($f_r === $round) ) $result[$f_r][] = $f;
      }
      return $result;
   }

   public function getNumRounds()
   {
      return end($this->fights)->getRound();
   }

   public function generateTournamentTree( $expectedParticipantsCount, $pool_size = 1, $winners_per_pool = 2 )
   {
      $nr_of_start_fights = 0;

      if( ($winners_per_pool < 1) || ($winners_per_pool > 2) )
      {
         throw "invalid winners per pool, only 1 or 2 supported";
      }

      if( $pool_size <= 1 )
      {
         $pool_size = 1;
         $winners_per_pool = 1;
      }

      $nr_of_start_fights = (2 ** ceil(log($expectedParticipantsCount/$pool_size*$winners_per_pool)/log(2)) ) / 2;

      $this->fights = [];

      /* generate the starting fights */
      for( $i = 0; $i < $nr_of_start_fights; ++$i )
      {
         $this->fights[] = new Fight($i);
      }

      /* generate all follow-up fights */
      for( $i = 0; $i < count($this->fights)-1; $i+=2 )
      {
         $this->fights[] = new Fight( count($this->fights), $this->fights[$i], $this->fights[$i+1] );
      }

      /* generate pools */
      if( $pool_size > 1 )
      {
         /* track next fights to assign pools to */
         $ff_i = 0;
         $sf_i = $nr_of_start_fights/2;
         $pool_count = $nr_of_start_fights * 2 / $winners_per_pool;
         for( $i = 0; $i < $pool_count; ++$i )
         {
            $this->pools[] = $nextPool = new Pool($i);

            $this->fights[$ff_i]->setPrevious($nextPool, RED);
            $this->fights[$sf_i]->setPrevious($nextPool, WHITE);

            /* increment fight indexes, wrap around at number of start fights */
            $ff_i = ($ff_i + 1) % $nr_of_start_fights;
            $sf_i = ($sf_i + 1) % $nr_of_start_fights;
         }
      }
   }

   public function shuffleParticipants()
   {
      if( count($this->pools) )
      {
         $this->shuffleParticipantsPools();
      }
      else
      {
         $this->shuffleParticipantsFights();
      }
   }

   private function shuffleParticipantsPools()
   {
      /* wipe already set participants from all pools */
      foreach( $this->pools as $p )
      {
         $p->clean();
      }

      /* copy the list of participants into local array, and shuffle it */
      $plist = $this->participants;
      shuffle($plist);

      /* now spread the random list of participants among the pools */
      $pid = 0;
      while( count($plist) ) // while participants left...
      {
         /* add a particpant to currently selected pool */
         $this->pools[$pid]->addParticipant( array_shift($plist) );
         /* go to next pool, wrap around at end of array */
         $pid = ($pid+1)%count($this->pools);
      }
   }

   private function shuffleParticipantsFights()
   {
      /* copy the list of participants into local array, and shuffle it */
      $plist = $this->participants;
      shuffle($plist);

      /* store back the number of participants we have now */
      $pcount = count($plist);

      /* first, assign red for each fight */
      foreach( $this->fights as $f )
      {
         if( is_null( $f->getPrevious(RED) ) ) $f->setPrevious( array_shift($plist), RED );
         else break;
      }

      /* now, fill up the list of remaining participants with wildcards */
      $pcount -= count($plist); // get number of needed participants to fill up fights,
                                // which is equal to the number of participants that got removed from plist
      while( count($plist) < $pcount )
      {
         $plist[] = null;
      }

      // shuffle again to spread the wildcards
      shuffle($plist);

      // add remaining
      foreach( $this->fights as $f )
      {
         if( is_null( $f->getPrevious(WHITE)) ) $f->setPrevious(array_shift($plist), WHITE );
         else break;
      }

      // sanity check at the end
      if( count($plist) ) throw new LogicException("more participants than available fights!");
   }
}