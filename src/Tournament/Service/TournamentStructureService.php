<?php

namespace Tournament\Service;

use Tournament\Model\Category\Category;
use Tournament\Model\TournamentStructure\TournamentStructure;
use Tournament\Repository\MatchDataRepository;
use Tournament\Repository\ParticipantRepository;
use Tournament\Repository\TournamentRepository;

/**
 * Service to load a complete tournament structure from the repositories, with all data contained
 */
class TournamentStructureService
{
   public function __construct(
      private TournamentRepository $tournamentRepo,
      private ParticipantRepository $participantRepo,
      private MatchDataRepository $matchDataRepo
   ) {
   }

   /**
    * completely load a tournament structure for a specific category from the database,
    * with all corresponding data (participants, match records)
    */
   public function load(Category $category): TournamentStructure
   {
      $participants = $this->participantRepo->getParticipantsWithSlotByCategoryId($category->id);
      $matchRecords = $this->matchDataRepo->getMatchRecordsByCategoryId($category->id);

      $struc = $this->initialize($category);
      $struc->getParticipantHandler()->loadParticipants($participants);
      $struc->loadMatchRecords($matchRecords);
      return $struc;
   }

   /**
    * populate a tournament structure by shuffling in all participants
    */
   public function populate(Category $category): TournamentStructure
   {
      $struc = $this->initialize($category);
      $participants = $this->participantRepo->getParticipantsByCategoryId($category->id);
      $slot_assignment = $struc->getParticipantHandler()->populate($participants);
      $this->participantRepo->updateAllParticipantSlots($category->id, $slot_assignment);
      return $struc;
   }

   /**
    * initialize a new TournamentStructure for a category and assign areas.
    */
   public function initialize(Category $category): TournamentStructure
   {
      $areas = $this->tournamentRepo->getAreasByTournamentId($category->tournament_id);
      $struc = new TournamentStructure($category, $areas);
      $struc->generateStructure();
      return $struc;
   }

   /**
    * reset all match records for a specific category - TEMPORARY, FOR TESTING PURPOSES ONLY
    * @param Category $category
    */
   public function resetMatchRecords(Category $category): void
   {
      $this->matchDataRepo->deleteMatchRecordsByCategoryId($category->id);
   }

}