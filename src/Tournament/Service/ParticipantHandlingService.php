<?php

namespace Tournament\Service;

use Tournament\Model\Category\Category;
use Tournament\Model\Category\CategoryCollection;
use Tournament\Model\Participant\Participant;
use Tournament\Model\Participant\ParticipantCollection;
use Tournament\Model\Participant\Team;

use Tournament\Model\Tournament\Tournament;
use Tournament\Model\TournamentStructure\MatchNode\MatchNode;

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
               /** @var MatchNode $node */
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
   public function validateParticipantData(array &$data, int $tournamentId, ?Participant $participant = null): array
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
   private function updateAndSaveParticipant(Participant $participant, array $data): void
   {
      $categories = $this->tournamentRepo->getCategoriesByTournamentId($participant->tournament_id);
      $starting_slots = $this->getStartingSlotSelection($categories, $participant);

      /* take over pre-assigned slots
       * the form data is of following format right now (example):
       * [ category_list: [ catA, catB, catC, ... ],  // full list of all categories
       *   categories:    [ catB ],                   // only the assigned categories
       *   pre_assign:    [ '', '1', '' ],            // full list of pre-assigns, indexed by category_list
       */
      $pre_assign_list = array_combine($data['category_list'], $data['pre_assign']);
      for ($i = 0; $i < count($data['categories']); ++$i)
      {
         $categoryId = $data['categories'][$i];

         if ($assignment = $participant->categories[$categoryId] ?? null)
         {
            $pre_assign = $pre_assign_list[$categoryId] ?? '';
            if (isset($starting_slots[$categoryId][$pre_assign])) // if the selected slot really exists...
            {
               // ... take it over
               $assignment->pre_assign = $pre_assign ?: null;
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

   /**
    * validate team data input
    */
   public function validateTeamData(array &$data, Category $category, ?Team $team = null): array
   {
      /* set default values */
      $data['withdrawn'] ??= false;

      /* validate and return */
      $rules = Team::validationRules();
      return DataValidationService::validate($data, $rules);
   }

   /**
    * update an existing team
    */
   public function updateTeam(Team $team, array $data): array
   {
      $team->updateFromArray($data);
      $report = $this->updateTeamMembersAndSave($team, $data['member']??[]);
      return $report;
   }

   /**
    * update team members from form input
    * steps:
    * 1) check if all participant ids are valid participants in this category
    * 2) check if any participant is already in another team
    * 3) if any participant is part of another team: add it to the report
    * 4) assemble the list of Participant instances according the given list and put it into the team
    * 5) sync any updates to the repo
    * @return array update report: [ participantId -> teamId ]:
    *     [ participantId => null,   // participant doesn't exist (anymore)
    *       participantId => teamId, // participant is already part of this team and needs to be dropped from there first
    *     ]
    */
   private function updateTeamMembersAndSave(Team $team, array $participantIdList): array
   {
      $team_mapping = $this->repo->getParticipantTeamMapping($team->category_id);
      $participants = $this->repo->getParticipantsByCategoryId($team->category_id);
      $new_participants = new ParticipantCollection();
      $report = [];
      foreach ($participantIdList as $pid)
      {
         if (!$participants->keyExists($pid))
         {
            $report[$pid] = null;
         }
         else
         {
            $prev_team = $team_mapping[$pid] ?? null;
            if ($prev_team !== null && $prev_team !== $team)
            {
               $report[$pid] = $prev_team;
            }
            $new_participants[] = $participants[$pid];
         }
      }
      $team->members = $new_participants;
      /* report keys are all participant ids of 1) non-existing participants, 2) participants currently assigned to a different team
       * drop those members from their current team so we can assign them now here to this team
       * no need to filter out the non-existing ones for dropTeamMembers(), they will just be ignored anyway
       */
      $this->repo->dropTeamMembers($team->category_id, array_keys($report));
      $this->repo->saveTeam($team);
      return $report;
   }

}