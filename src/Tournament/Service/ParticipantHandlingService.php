<?php

namespace Tournament\Service;

use Tournament\Model\Category\CategoryCollection;
use Tournament\Model\Participant\Participant;
use Tournament\Model\Tournament\Tournament;

use Tournament\Repository\MatchDataRepository;
use Tournament\Repository\ParticipantRepository;
use Tournament\Repository\TournamentRepository;

use Base\Service\DataValidationService;

use Respect\Validation\Validator as v;

/**
 * Service for modifying/updating any participants
 */
class ParticipantHandlingService
{
   public function __construct(
      private ParticipantRepository $repo,
      private MatchDataRepository $matchDataRepo,
      private TournamentRepository $tournamentRepo,
      private TournamentStructureService $tournamentService,
   )
   {
   }

   /**
    * retrieve the start slots for a category, to enable pre-assignment of starting slots
    * if Participant provided as well, further filter them down to the selection available to that one
    */
   public function getStartingSlotSelection(CategoryCollection $categories, ?Participant $participant = null): array
   {
      $MATCH_PREFIX = 'Kampf';
      $POOL_PREFIX = 'Pool';
      $RANDOM_VALUE = '?';

      $result = [];
      foreach ($categories as $category)
      {
         $struc = $this->tournamentService->initialize($category); // only initialize the basic structure, without loading any actual data
         $selection = ['' => $RANDOM_VALUE];

         if ($struc->pools->empty())
         {
            foreach ($struc->ko->getFirstRound() as $node)
            {
               /** @var KoNode $node */
               $selection[$node->getName()] = $MATCH_PREFIX . ' ' . $node->getName();
            }
         }
         else
         {
            foreach ($struc->pools as $pool)
            {
               /** @var Pool $pool */
               $selection[$pool->getName()] = $POOL_PREFIX . ' ' . $pool->getName();
            }
         }
         $result[$category->id] = $selection;
      }

      if ($participant)
      {
         /* remove already taken starting slots from the selection */
         foreach ($this->repo->getPreAssignmentsByTournamentId($participant->tournament_id) as $categoryId => $list)
         {
            foreach ($list as $pa)
            {
               if ($pa != $participant->categories[$categoryId]->pre_assign)
               {
                  unset($result[$categoryId][$pa]);
               }
            }
         }
      }

      return $result;
   }

   /**
    * validate input data for updating or creating a participant
    */
   public function validateParticipantData(array $data, int $tournamentId, ?Participant $participant = null): array
   {
      /* set some default values */
      $data['categories'] ??= [];
      $data['withdrawn'] ??= false;

      /* derive and extend validation rules */
      $participant_rules = Participant::validationRules();
      $categories = $this->tournamentRepo->getCategoriesByTournamentId($tournamentId);
      $participant_rules['categories'] = $participant_rules['categories']->each(v::in($categories->column('id')));
      $participant_rules['pre_assign'] = v::arrayType();
      if (!$participant || $this->matchDataRepo->hasParticipantPoints($participant->id))
      {
         $participant_rules['withdrawn'] = v::equals(false)->setTemplate('Teilnehmer kann nicht mehr abgemeldet werden');
      }

      /* validate and return */
      return DataValidationService::validate($data, $participant_rules);
   }

   /**
    * create and store a new participant from the provided input $data
    */
   public function storeParticipant(Tournament $tournament, array $data): Participant
   {
      $participant = Participant::createFromArray($tournament->id, $data);
      $this->updateAndSaveParticipant($participant, $data);
      return $participant;
   }

   /**
    * update an existing participant from the provided input $data
    */
   public function patchParticipant(Participant $participant, array $data): Participant
   {
      $participant->updateFromArray($data);
      $this->updateAndSaveParticipant($participant, $data);
      return $participant;
   }

   /**
    * update related participant data (like category selection) and save a participant to the DB
    */
   private function updateAndSaveParticipant(Participant $participant, array $data)
   {
      $categories = $this->tournamentRepo->getCategoriesByTournamentId($participant->tournament_id);
      $starting_slots = $this->getStartingSlotSelection($categories, $participant);

      // take over pre-assigned slots
      for ($i = 0; $i < count($data['categories']); ++$i)
      {
         $categoryId = $data['categories'][$i];

         if ($assignment = $participant->categories[$categoryId] ?? null)
         {
            if (isset($starting_slots[$categoryId][$data['pre_assign'][$i]]))
            {
               $assignment->pre_assign = $data['pre_assign'][$i] ?: null;
            }
         }
      }

      // save
      $this->repo->saveParticipant($participant);

      // if participant was withdrawn, also delete any assigned matches that may already exist
      // as well as any starting slot assignment, to free up this slot
      if ($participant->withdrawn)
      {
         $this->matchDataRepo->deleteMatchRecordsByParticipantId($participant->id);
         $this->repo->freeParticipantSlots($participant->id);
      }
   }

   /**
    * check whether a participant may still withdraw his registration
    */
   public function mayWithdraw(Participant $participant): bool
   {
      /* do not allow to withdraw a participant who is already registered as participating by
       * having achieved some points.
       * TODO: it would be even more accurate to test if participant is already involved in any matches
       *       where anyone achived any point.
       *       The pure existance of any match record with this participant involved is not enough -
       *       the users may already have created the first match record data (if by accident) before
       *       realizing that this participant is not actually there.
       */
      return !$this->matchDataRepo->hasParticipantPoints($participant->id);
   }

   /**
    * assign all currently unslotted participants in the provided categories
    */
   public function assignParticipants(CategoryCollection $categories): void
   {
      foreach ($categories as $c)
      {
         $this->tournamentService->addParticipants($c);
      }
   }

   /**
    * fully re-shuffle all participants in the provided categories
    */
   public function shuffleParticipants(CategoryCollection $categories): void
   {
      foreach ($categories as $c)
      {
         $this->tournamentService->repopulate($c);
      }
   }

}