<?php

namespace Tournament\Service;

use Tournament\Model\Category\Category;
use Tournament\Model\Participant\ParticipantCollection;
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
      $participants = $this->participantRepo->getParticipantsByCategoryId($category->id);
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
      $struc = $this->initialize($category);
      $participants = $this->participantRepo->getParticipantsByCategoryId($category->id);
      $slot_assignment = $struc->populate($participants);
      $this->participantRepo->updateAllParticipantSlots($category->id, $slot_assignment);
      return $struc;
   }

   /**
    * add a new list of participants to an already populated structure
    * @param Category|TournamentStructure $struc - the tournament structure to add participants to (optionally identified by the category)
    * @param ParticipantCollection $participants - the participants to add, defaults to $struc->unmapped_participants
    */
   public function addParticipants(Category|TournamentStructure $struc, ?ParticipantCollection $participants = null): TournamentStructure
   {
      if ($struc instanceof Category)
      {
         $struc = $this->load($struc);
      }
      $participants  ??= $struc->unmapped_participants->copy();
      $slot_assignment = $struc->populate($participants);
      $this->participantRepo->updateAllParticipantSlots($struc->category->id, $slot_assignment);
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