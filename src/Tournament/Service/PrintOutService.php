<?php declare(strict_types=1);

namespace Tournament\Service;

use Tournament\Model\Category\Category;
use Tournament\Model\Participant\Participant;
use Tournament\Model\Participant\ParticipantCollection;
use Tournament\Model\PrintPage\PrintPage;
use Tournament\Model\PrintPage\PrintPageCollection;
use Tournament\Model\PrintPage\PrintPageSetup;
use Tournament\Model\TournamentStructure\MatchNode\KoNode;
use Tournament\Model\TournamentStructure\MatchNode\MatchNodeCollection;
use Tournament\Model\TournamentStructure\Pool\Pool;
use Tournament\Repository\ParticipantRepository;

/**
 * service to generate printout data
 */
class PrintOutService
{
   public function __construct(
      private ParticipantRepository $participantRepo,
      private TournamentStructureService $strucService,
   )
   {

   }

   public function printTournamentSetup()
   {

   }

   /**
    * @param $printOptions - print options
    *
    * print options: (for now)
    * - order: 'match', 'single'
    * - fontSize: [pt]
    */
   public function getNameSheetsData(Category $category, array $printOptions): PrintPageCollection
   {
      /* for now, only support 2 names per A4 page, in landscape format */
      $setup = new PrintPageSetup(paperSize: 'a4', orientation: 'landscape');
      $per_page = 2;
      $participants = match($printOptions['order']??'match')
      {
         'single' => $this->getParticipantList($category),
         'match'  => $this->getParticipantListByMatchOrder($category),
         default  => throw new \OutOfBoundsException('invalid print order: ' . $printOptions['order'] )
      };
      $result = PrintPageCollection::new();
      foreach( array_chunk( $participants, $per_page) as $plist )
      {
         $result[] = new PrintPage(
            template: 'namesheet.twig',
            data: [ 'participants' => array_map( fn($p) => [
                                       'name' => static::reduceName($p),
                                      ], $plist)
                  , 'fontSize' => $printOptions['fontSize'] ?? '96',
            ],
            setup: $setup
         );
      }
      return $result;
   }

   private function getParticipantList(Category $category): ParticipantCollection
   {
      return $this->participantRepo->getParticipantsByCategoryId($category->id)
               ->filter(fn($p) => $p->categories[$category->id]->slot_name !== null)
               ->usort(fn($a,$b) => $a->categories[$category->id]->slot_name <=> $b->categories[$category->id]->slot_name);
   }

   private function getParticipantListByMatchOrder(Category $category): array
   {
      $struc = $this->strucService->load($category);
      $matches = $struc->pools->empty()? $struc->ko->getFirstRound()
               : array_reduce( $struc->pools->values(), fn($m, $pool) => $m->merge($pool->getMatchList()), MatchNodeCollection::new());
      $result = [];
      foreach($matches as $node)
      {
         foreach( [ $node->getRedParticipant(), $node->getWhiteParticipant() ] as $p )
         {
            if( $p && !$p->isDummy() )
            {
               $result[] = $p;
            }
         }
      }
      return $result;
   }

   /**
    * use a simple approach to transform a participant name so that it will likely fit a name sheet:
    * 1) use <lastname>, <firstname>
    * 2) if this is more than $max_length bytes, reduce first name to the first letter of each word
    * - This approach fits the usual namesheet generation we are using, but does not handle corner cases well
    * TODO:
    * - handle name collisions (if there are two or more people with same lastname and similar firstname)
    * - provide some configurability, e.g. default to using lastname, only
    */
   private static function reduceName(Participant $p, int $max_length = 18): string
   {
      $name = "{$p->lastname}, {$p->firstname}";
      if( strlen($name) > $max_length )
      {
         $reduced_firstname = join(' ', array_map( fn($n) => "{$n[0]}.", explode(' ', $p->firstname) ) );
         $name = "$p->lastname, $reduced_firstname";
      }
      return $name;
   }
}

