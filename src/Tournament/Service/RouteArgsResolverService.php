<?php

namespace Tournament\Service;

use Tournament\Repository\AreaRepository;
use Tournament\Repository\CategoryRepository;
use Tournament\Repository\ParticipantRepository;
use Tournament\Repository\TournamentRepository;

use Tournament\Exception\EntityNotFoundException;

/**
 * This class implements the logic to fetch the corresponding data classes from the
 * repo that are referenced in the route parameters.
 * If any entry does not exists, it will throw an EntityNotFoundException
 */
class RouteArgsResolverService
{
   public function __construct(
      private TournamentRepository $tournamentRepo,
      private CategoryRepository $categoryRepo,
      private ParticipantRepository $participantRepo,
      private AreaRepository $areaRepo
   )
   {
   }

   /**
    * Resolve the route arguments to their corresponding data objects.
    * If any entity is not found, an EntityNotFoundException is thrown.
    * @param array $args The route arguments, typically from $request->getAttribute('routeInfo')[2]
    * @return array An associative array with keys 'tournament', 'category', 'participant', 'area' depending on which IDs were present in $args
    * @return null if any entry could not be found
    */
   public function resolve(array $args): ?array
   {
      $result = [];
      if( isset($args['tournamentId']) )
      {
         $result['tournament'] = $this->tournamentRepo->getTournamentById($args['tournamentId'])
                               ?? throw new EntityNotFoundException('Tournament not found');
      }
      if (isset($args['categoryId']))
      {
         $result['category'] = $this->categoryRepo->getCategoryById($args['categoryId'])
                             ?? throw new EntityNotFoundException('Category not found');
      }
      if (isset($args['participantId']))
      {
         $result['participant'] = $this->participantRepo->getParticipantById($args['participantId'])
                                ?? throw new EntityNotFoundException('Participant not found');
      }
      if (isset($args['areaId']))
      {
         $result['area'] = $this->areaRepo->getAreaById($args['areaId'])
                        ?? throw new EntityNotFoundException('Area not found');
      }

      return $result;
   }

}