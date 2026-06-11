<?php declare(strict_types=1);

namespace Tournament\Service;

use Tournament\Model\Category\Category;
use Tournament\Model\Participant\CategoryAssignment;
use Tournament\Model\Participant\Participant;
use Tournament\Model\Tournament\Tournament;
use Tournament\Model\Tournament\TournamentStatus;
use Tournament\Repository\MatchDataRepository;
use Tournament\Repository\ParticipantRepository;
use Tournament\Repository\TournamentRepository;

use Base\Repository\ChangeLogRepository;

/**
 * service for tournament state transition handling
 * This service performs necessary condition checks when attempting a state transition,
 * assembles user consent lists if user needs to explicitly confirm any issues, and performs necessary
 * actions on status changes (e.g. partially reset of tournament data when returning to an earlier state)
 */
class TournamentStateHandlingService
{
   public const ACTION_DELETE_MATCH_RESULTS = 'action_delete_match_results';
   public const ACTION_DELETE_CHANGE_LOGS   = 'action_delete_change_logs';

   public const ACCEPT_UNASSIGNED_PARTICIPANTS = 'accept_unassigned_participants';
   public const ACCEPT_UNFITTING_TOURNAMENT_TREE = 'accept_unfitting_tournament_tree';
   public const ACCEPT_INCOMPLETE_TOURNAMENT_RESULTS = 'accept_incomplete_tournament_results';

   public const ISSUE_NO_PARTICIPANTS = 'issue_no_participants';
   public const ISSUE_NO_COMBAT_AREAS_CREATED = 'issue_no_combat_areas_created';

   public function __construct(
      private TournamentRepository $tournamentRepo,
      private ParticipantRepository $participantRepo,
      private MatchDataRepository $matchDataRepo,
      private ChangeLogRepository $chgLogRepo,
   )
   {

   }

   /**
    * check current tournament state, and whether it can be progressed to the requested state
    * @param Tournament $tournament - the Tournament to update
    * @param TournamentStatus $newStatus - the status to progress to
    * @return bool|array: true: state change can be done, false: state change not possible, array: list of consent items that need to be acquired from the user
    * returned consent list is of format [ <consent_name> => <further details> ]
    * further details is usually a list of category ids for which the consent is required
    */
   public function checkStatusChangeConditions(Tournament $tournament, TournamentStatus $newStatus): bool|array
   {
      switch ($tournament->status)
      {
         case TournamentStatus::Planning:
            switch ($newStatus)
            {
               case TournamentStatus::Planned:
               {
                  $checkResult = [];
                  /* Prio 1: check for unacceptable issues */
                  $emptyCategories = $this->checkCategoryAssignment($tournament);
                  if( $emptyCategories ) $checkResult[self::ISSUE_NO_PARTICIPANTS] = $emptyCategories;

                  if( $this->tournamentRepo->getAreasByTournamentId($tournament->id)->empty() )
                  {
                     $checkResult[self::ISSUE_NO_COMBAT_AREAS_CREATED] = true;
                  }

                  /* if any issues, return here */
                  if( $checkResult ) return $checkResult;

                  /* Prio 2: check for any neccessary consent requests */
                  $incompleteCategories = $this->checkForUnassignedParticipants($tournament);
                  if( $incompleteCategories ) $checkResult[self::ACCEPT_UNASSIGNED_PARTICIPANTS] = $incompleteCategories;
                  $wronglySizedCategories = $this->checkForUnfittingTournamentSize($tournament);
                  if( $wronglySizedCategories ) $checkResult[self::ACCEPT_UNFITTING_TOURNAMENT_TREE] = $wronglySizedCategories;

                  return count($checkResult)? $checkResult : true;
               }

               default:
                  return false;
            }

         case TournamentStatus::Planned:
            switch ($newStatus)
            {
               case TournamentStatus::Running:
                  /* planned -> running is always allowed without additional actions.
                   * Consent for unexpected setups was already requested when transitioning from planning to planned
                   */
                  return true;

               case TournamentStatus::Planning:
                  /* when returning to planning, delete any acquired change logs */
                  $checkResult = [];
                  if( $this->checkForChangeLogs($tournament) ) $checkResult[self::ACTION_DELETE_CHANGE_LOGS] = true;
                  return count($checkResult) ? $checkResult : true;

               default:
                  return false;
            }

         case TournamentStatus::Running:
            switch ($newStatus)
            {
               case TournamentStatus::Completed:
               {
                  $checkResult = [];

                  /* check if all tournament categories are fully resolved */
                  $matchRecordsList = $this->checkMatchesCompleted($tournament);
                  if ($matchRecordsList) $checkResult[self::ACCEPT_INCOMPLETE_TOURNAMENT_RESULTS] = $matchRecordsList;

                  return count($checkResult) ? $checkResult : true;
               }

               case TournamentStatus::Planning:
               {
                  $checkResult = [];

                  /* check if we already have any recorded match results and change logs, which need to be deleted on transition */
                  $matchRecordsList = $this->checkMatchRecordExistence($tournament);
                  if( $matchRecordsList ) $checkResult[self::ACTION_DELETE_MATCH_RESULTS] = $matchRecordsList;
                  if ($this->checkForChangeLogs($tournament)) $checkResult[self::ACTION_DELETE_CHANGE_LOGS] = true;

                  return count($checkResult)? $checkResult : true;
               }

               default:
                  return false;
            }

         case TournamentStatus::Completed:
            return false;

         default:
            throw new \DomainException("Unhandled tournament status: " . $tournament->status->value);
      }
   }

   /**
    * update tournament state, perform any necessary actions
    * @param Tournament $tournament - the Tournament to update
    * @param TournamentStatus $newStatus - the status to progress to
    * @param string[] $consentList - list of provided user consent (see checkStatusConditions())
    */
   public function updateState(Tournament $tournament, TournamentStatus $newStatus, array $consentList): bool
   {
      $checkResult = $this->checkStatusChangeConditions($tournament, $newStatus);
      // if actions needed, check for user consent and perform them
      if( is_array($checkResult) )
      {
         $checkResult = array_keys($checkResult);
         // check if any "issue" entry found and directly deny in this case
         if( array_any($checkResult, fn($c) => str_starts_with($c, 'issue_') ) )
         {
            $checkResult = false;
         }
         // check $consentList contains all entries requested in $checkResult
         else if( !array_diff($checkResult, $consentList) )
         {
            // necessary consent was provided, perform corresponding actions
            $this->performStateChangeActions($tournament, $consentList);
            $checkResult = true;
         }
         else
         {
            // necessary consent was not provided
            $checkResult = false;
         }
      }
      if( $checkResult === true )
      {
         $tournament->status = $newStatus;
         $this->tournamentRepo->saveTournament($tournament);
         return true;
      }
      else
      {
         return false;
      }
   }

   /**
    * perform all state change actions as per the provided list
    * @param Tournament $tournament - the Tournament to update
    * @param string[] $consentList - list of provided user consent (see checkStatusConditions())
    */
   private function performStateChangeActions(Tournament $tournament, array $consentList): void
   {
      $actions = array_filter($consentList, fn($a) => str_starts_with($a, 'action_'));
      foreach( $actions as $action )
      {
         switch($action)
         {
            case self::ACTION_DELETE_MATCH_RESULTS:
               $this->matchDataRepo->deleteMatchRecordsByTournamentId($tournament->id);
               break;

            case self::ACTION_DELETE_CHANGE_LOGS:
               $this->chgLogRepo->deleteChangeLogsByGroupId($tournament->id);
               break;

            default:
               throw new \LogicException("unhandled tournament state change action '{$action}'");
         }
      }
   }

   /**
    * check whether all tournament categories do have at least one participant assigned.
    * Return a list of all category ids where this is not the case
    */
   private function checkCategoryAssignment(Tournament $tournament): array
   {
      $catList = $tournament->categories->column('id', 'id');
      foreach ($this->participantRepo->getParticipantsByTournamentId($tournament->id) as $p)
      {
         /** @var Participant $p */
         foreach ($p->categories as $ca)
         {
            /** @var CategoryAssignment $ca */
            $catList[$ca->categoryId] = false;
         }
      }
      return array_values(array_filter($catList));
   }

   /**
    * check whether there are any participants without a starting slot
    * Return a list of all category ids where this is not the case
    */
   private function checkForUnassignedParticipants(Tournament $tournament): array
   {
      $result = [];
      foreach( $tournament->categories as $category )
      {
         /** @var Category $category */
         if( !$category->getTournamentStructure()->unmapped_participants->empty() )
         {
            $result[] = $category->id;
         }
      }
      return $result;
   }

   /**
    * check whether the size of the tournament tree does not seem to fit to the number
    * of participants.
    * Return a list of all category ids where issues where detected
    */
   private function checkForUnfittingTournamentSize(Tournament $tournament): array
   {
      $result = [];
      foreach( $tournament->categories as $category )
      {
         /** @var Category $category */
         $structure = $category->getTournamentStructure();
         $pcount = $this->participantRepo->getParticipantsByCategoryId($category->id)->count();

         /* for pure KO, size is deemed unfitting if we do not have more than half of the starting slots filled */
         /* for pools, size is deemed unfitting if any pool has less than 2 participants */
         $issue = $structure->pools->empty()? ($structure->ko->getFirstRound()->count() <= $pcount)
                  :                           ($structure->pools->count() > 2*$pcount);
         if( $issue )
         {
            $result[] = $category->id;
         }
      }
      return $result;
   }

   /**
    * check for existing match records - return a list of all categories where there are already match records available
    */
   private function checkMatchRecordExistence(Tournament $tournament): array
   {
      $result = [];
      foreach( $tournament->categories as $category )
      {
         /** @var Category $category */
         if( !$this->matchDataRepo->getMatchRecordsByCategoryId($category->id)->empty() )
         {
            $result[] = $category->id;
         }
      }
      return $result;
   }

   /**
    * check if we have full match results for each match - return a list of all incomplete categories
    */
   private function checkMatchesCompleted(Tournament $tournament): array
   {
      $result = [];
      foreach ($tournament->categories as $category)
      {
         /** @var Category $category */
         $structure = $category->getTournamentStructure();
         /* check if there is a winner of the whole category - if so, then also everything else
          * is resolved as per current setup
          */
         if( $structure->ko->getRanked(1)->empty() ) $result[] = $category->id;
      }
      return $result;
   }

   /**
    * check if there are any change logs acquired already
    */
   private function checkForChangeLogs(Tournament $tournament): bool
   {
      return $this->chgLogRepo->hasChangeLogsForGroupId($tournament->id);
   }

}
