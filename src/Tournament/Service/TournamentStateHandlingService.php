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
   public const ACCEPT_PARTICIPANTS_WITHOUT_TEAM = 'accept_participants_without_team';

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
                  /* check for unacceptable issues first */
                  $checkResult = $this->performStateChangeChecks($tournament, self::ISSUE_NO_PARTICIPANTS, self::ISSUE_NO_COMBAT_AREAS_CREATED);
                  if( $checkResult !== true ) return $checkResult;

                  /* if no unacceptable issues, check for needed consent requests */
                  return $this->performStateChangeChecks($tournament,
                     self::ACCEPT_UNASSIGNED_PARTICIPANTS,
                     self::ACCEPT_UNFITTING_TOURNAMENT_TREE,
                     self::ACCEPT_PARTICIPANTS_WITHOUT_TEAM
                  );
               }

               default:
                  return false;
            }

         case TournamentStatus::Planned:
            switch ($newStatus)
            {
               case TournamentStatus::Running:
                  return $this->performStateChangeChecks( $tournament,
                     self::ACCEPT_UNASSIGNED_PARTICIPANTS,
                     self::ACCEPT_PARTICIPANTS_WITHOUT_TEAM
                  );

               case TournamentStatus::Planning:
                  /* when returning to planning, delete any acquired change logs */
                  return $this->performStateChangeChecks($tournament, self::ACTION_DELETE_CHANGE_LOGS);

               default:
                  return false;
            }

         case TournamentStatus::Running:
            switch ($newStatus)
            {
               case TournamentStatus::Completed:
                  return $this->performStateChangeChecks($tournament, self::ACCEPT_INCOMPLETE_TOURNAMENT_RESULTS);

               case TournamentStatus::Planning:
                  return $this->performStateChangeChecks($tournament, self::ACTION_DELETE_MATCH_RESULTS, self::ACTION_DELETE_CHANGE_LOGS);

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
      foreach ($consentList as $action)
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
               if( str_starts_with($action, 'action_') )
                  throw new \LogicException("unhandled tournament state change action '{$action}'");
               break;
         }
      }
   }

   /**
    * perform a list of provided state change checks
    * @param Tournament $tournament - the Tournament to update
    * @param string[] $checkList - list of checks to be performed - see constants in this class for possible values
    * @return bool|array: true: all checks passed, array: list of all checks that did not pass (format: [ <check_name> => <further details> ] - further details is usually a list of category ids for which the check failed)
    *
    */
   private function performStateChangeChecks(Tournament $tournament, string ...$checkList): bool|array
   {
      $result = [];
      foreach( $checkList as $check )
      {
         $issue = match( $check )
         {
            self::ACTION_DELETE_MATCH_RESULTS          => $this->getCategoriesWithMatchResults($tournament),
            self::ACTION_DELETE_CHANGE_LOGS            => $this->chgLogRepo->hasChangeLogsForGroupId($tournament->id),

            self::ACCEPT_UNASSIGNED_PARTICIPANTS       => $this->getCategoriesWithUnassignedParticipants($tournament),
            self::ACCEPT_UNFITTING_TOURNAMENT_TREE     => $this->getCategoriesWithUnfittingStructureSize($tournament),
            self::ACCEPT_INCOMPLETE_TOURNAMENT_RESULTS => $this->getCategoriesWithUncompletedMatches($tournament),
            self::ACCEPT_PARTICIPANTS_WITHOUT_TEAM     => $this->getCategoriesWithTeamlessParticipants($tournament),

            self::ISSUE_NO_PARTICIPANTS                => $this->getCategoriesWithNoParticipants($tournament),
            self::ISSUE_NO_COMBAT_AREAS_CREATED        => $this->tournamentRepo->getAreasByTournamentId($tournament->id)->empty(),
         };

         if ($issue) $result[$check] = $issue;
      }
      return count($result)? $result : true;
   }

   /**
    * check whether all tournament categories do have at least one participant assigned.
    * Return a list of all category ids where this is not the case
    */
   private function getCategoriesWithNoParticipants(Tournament $tournament): array
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
   private function getCategoriesWithUnassignedParticipants(Tournament $tournament): array
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
   private function getCategoriesWithUnfittingStructureSize(Tournament $tournament): array
   {
      $result = [];
      foreach( $tournament->categories as $category )
      {
         /** @var Category $category */
         $structure = $category->getTournamentStructure();

         /* for pure KO, size is deemed unfitting if there is at most one participant per first-round-match */
         /* for pools, size is deemed unfitting if any pool has less than 2 participants */
         $issue = $structure->pools->empty()? ($structure->ko->getFirstRound()->count() >= $structure->ko->getParticipantList()->count())
                  :                           ($structure->pools->any(fn($p) => $p->getParticipants()->count() < 2));
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
   private function getCategoriesWithMatchResults(Tournament $tournament): array
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
   private function getCategoriesWithUncompletedMatches(Tournament $tournament): array
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
    * check if there are any participants without an assigned team in team mode
    */
   private function getCategoriesWithTeamlessParticipants(Tournament $tournament): array
   {
      $categories = $tournament->categories;
      $catList = [];
      foreach ($this->participantRepo->getParticipantsByTournamentId($tournament->id) as $p)
      {
         /** @var Participant $p */
         foreach ($p->categories as $ca)
         {
            /** @var CategoryAssignment $ca */
            if( $categories[$ca->categoryId]->team_mode && !$ca->team_id ) $catList[$ca->categoryId] = true;
         }
      }
      return array_keys($catList);
   }
}
