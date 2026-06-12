<?php declare(strict_types=1);

namespace Tournament\Service;

use Tournament\Repository\ParticipantRepository;
use Tournament\Repository\TournamentRepository;

use Tournament\Exception\EntityNotFoundException;

use Psr\Http\Message\ServerRequestInterface;

use Slim\Routing\RouteContext;

/**
 * This class implements the logic to fetch the corresponding data classes from the
 * repo that are referenced in the route parameters.
 * If any entry does not exists, it will throw an EntityNotFoundException
 */
class RouteArgsResolverService
{
   public function __construct(
      private TournamentRepository  $tournamentRepo,
      private ParticipantRepository $participantRepo,
      private TournamentStructureService $structureService,
   )
   {
   }

   /**
    * Resolve the route arguments to their corresponding data objects.
    * If any entity is not found, an EntityNotFoundException is thrown.
    */
   public function resolve(ServerRequestInterface $request): RouteArgsContext
   {
      $args   = RouteContext::fromRequest($request)->getRoute()->getArguments();
      $result = new RouteArgsContext(args: $args);
      if( isset($args['tournamentId']) )
      {
         $result->tournament = $this->tournamentRepo->getTournamentById((int)$args['tournamentId'])
                             ?? throw new EntityNotFoundException($request, 'Tournament not found');
         $this->structureService->prepareTournament($result->tournament);
      }
      if (isset($args['categoryId']))
      {
         $result->category = $this->tournamentRepo->getCategoryById((int)$args['categoryId'])
                           ?? throw new EntityNotFoundException($request, 'Category not found');
         if( !$result->tournament ) $this->structureService->prepare($result->category);
      }
      if (isset($args['participantId']))
      {
         $result->participant = $this->participantRepo->getParticipantById((int)$args['participantId'])
                              ?? throw new EntityNotFoundException($request, 'Participant not found');
      }
      if (isset($args['teamId']))
      {
         $result->team = $this->participantRepo->getTeamById((int)$args['teamId'])
                       ?? throw new EntityNotFoundException($request, 'Team not found');
      }
      if (isset($args['areaId']))
      {
         $result->area = $this->tournamentRepo->getAreaById((int)$args['areaId'])
                       ?? throw new EntityNotFoundException($request, 'Area not found');
      }
      if (isset($args['pool']))
      {
         $result->pool = $result->category->getTournamentStructure()->pools[$args['pool']]
                       ?? throw new EntityNotFoundException($request, 'Unknown Pool');
      }
      if (isset($args['matchName']))
      {
         $result->match = $result->category->getTournamentStructure()->findNode($args['matchName'], $result->pool?->getName() ?? false)
                        ?? throw new EntityNotFoundException($request, 'match not found');
      }
      return $result;
   }

}