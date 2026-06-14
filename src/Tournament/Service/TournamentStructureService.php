<?php

namespace Tournament\Service;

use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\Participant\ParticipantChangeLog;
use Tournament\Model\Participant\ParticipantCollection;
use Tournament\Model\Participant\TeamCollection;
use Tournament\Model\Tournament\Tournament;
use Tournament\Model\TournamentStructure\MatchNode\MatchNode;
use Tournament\Model\TournamentStructure\MatchParticipant\MatchParticipantCollection;
use Tournament\Model\TournamentStructure\Pool\Pool;
use Tournament\Model\TournamentStructure\TournamentStructure;

use Tournament\Repository\MatchDataRepository;
use Tournament\Repository\ParticipantRepository;
use Tournament\Repository\TournamentRepository;

use Base\Repository\ChangeLogRepository;

/**
 * Service to load a complete tournament structure from the repositories, with all data contained
 */
class TournamentStructureService
{
   public function __construct(
      private TournamentRepository $tournamentRepo,
      private ParticipantRepository $participantRepo,
      private MatchDataRepository $matchDataRepo,
      private ChangeLogRepository $chgLogRepo,
   ) {
   }

   /**
    * assign TournamentStructure loading hook to a specific category
    */
   public function prepare(Category $category): void
   {
      $category->setTournamentStructure(fn($c) => $this->load($c));
   }

   /**
    * assign TournamentStructure loading hooks to all categories of a tournament
    */
   public function prepareTournament(Tournament $tournament): void
   {
      $tournament->categories->walk(fn($c) => $this->prepare($c));
   }

   /**
    * completely load a tournament structure for a specific category from the database,
    * with all corresponding data (participants, match records)
    */
   public function load(Category $category): TournamentStructure
   {
      $participants = $category->team_mode ? $this->participantRepo->getActiveTeamsByCategoryId($category->id)
                    :                        $this->participantRepo->getActiveParticipantsByCategoryId($category->id);
      $matchRecords = $this->matchDataRepo->getMatchRecordsByCategoryId($category->id);

      $struc = $this->initialize($category);
      $struc->loadParticipants($participants);
      $struc->loadMatchRecords($matchRecords);
      return $struc;
   }

   /**
    * repopulate a tournament structure by shuffling in all participants again from scratch
    */
   public function repopulate(Category $category): TournamentStructure
   {
      /* fetch list of participants from repo */
      $participants = $category->team_mode ? $this->participantRepo->getActiveTeamsByCategoryId($category->id)
                    :                        $this->participantRepo->getActiveParticipantsByCategoryId($category->id);
      /* (re)initialize category structure without loading any participants */
      $this->initialize($category);
      /* add all active participants to the initialized structure */
      return $this->addParticipants($category, $participants);
   }

   /**
    * add a new list of participants to an already populated structure
    * @param Category $category - the category to add participants to
    * @param MatchParticipantCollection $participants - the participants to add, defaults to $struc->unmapped_participants
    */
   public function addParticipants(Category $category, ?MatchParticipantCollection $participants = null): TournamentStructure
   {
      /* load up/initialize input data */
      $struc = $category->getTournamentStructure();
      $participants ??= $struc->unmapped_participants->copy();

      /* check if we need to handle participant change logs */
      $tournament    = $this->tournamentRepo->getTournamentById($struc->category->tournament_id);
      $track_changes = $tournament->trackChanges();
      $old_state = $track_changes? $participants->map(fn($p) => clone $p) : null;

      /* perform slot assignments */
      $assigned = $struc->populate($participants);
      if ($category->team_mode)
      {
         $col = TeamCollection::new($assigned->values());
         $this->participantRepo->updateAllTeamSlots($category->id, $col);
      }
      else
      {
         $col = ParticipantCollection::new($assigned->values());
         $this->participantRepo->updateAllParticipantSlots($category->id, $col);
      }

      /* generate the change log */
      if( $track_changes )
      {
         $log = ParticipantChangeLog::new();
         foreach( $participants as $p )
         {
            $log->mergeInPlace(ParticipantChangeLog::create($old_state[$p->id], $p));
         }
         $this->chgLogRepo->storeChangeLog($log);
      }

      return $struc;
   }

   /**
    * initialize a new TournamentStructure for a category and assign areas.
    */
   public function initialize(Category $category): TournamentStructure
   {
      $areas = $this->tournamentRepo->getAreasByTournamentId($category->tournament_id);
      $area_mappings = $this->tournamentRepo->getMatchAreaMappingByCategoryId($category->id);
      $struc = new TournamentStructure($category, $areas);
      $struc->generateStructure();
      $struc->loadAreaMappings($area_mappings);
      $category->setTournamentStructure($struc);
      return $struc;
   }

   /**
    * reset all match records for a specific category - TEMPORARY, FOR TESTING PURPOSES ONLY
    */
   public function resetMatchRecords(Category $category): void
   {
      $this->matchDataRepo->deleteMatchRecordsByCategoryId($category->id);
   }

   /**
    * explicitly assign an area to a match or pool, that superseeds the automatic assignment
    */
   public function updateAreaAssignment(MatchNode|Pool $entity, Area|int $area): void
   {
      if( is_int($area) )
      {
         $area = $this->tournamentRepo->getAreaById($area);
      }

      if( !($area instanceof Area) )
      {
         throw new \OutOfRangeException('invalid area assigned');
      }

      $entity->setArea($area);
      $this->tournamentRepo->storeAreaAssignment($entity);
   }
}