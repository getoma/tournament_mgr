<?php

namespace Tournament\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

use Tournament\Model\Category\CategoryCollection;
use Tournament\Model\Participant\Participant;
use Tournament\Model\Participant\CategoryAssignment;
use Tournament\Model\Participant\CategoryAssignmentCollection;
use Tournament\Model\TournamentStructure\MatchNode\KoNode;
use Tournament\Model\TournamentStructure\Pool\Pool;

use Tournament\Service\ParticipantImportService;
use Tournament\Service\TournamentStructureService;

use Tournament\Repository\TournamentRepository;
use Tournament\Repository\ParticipantRepository;

use Base\Service\PrgService;
use Base\Service\DataValidationService;
use Respect\Validation\Validator as v;

class ParticipantsDataController
{
   public function __construct(
      private Twig $view,
      private ParticipantRepository $repo,
      private TournamentRepository $tournamentRepo,
      private ParticipantImportService $plistParser,
      private TournamentStructureService $tournamentService,
      private PrgService $prgService,
   ) {
   }

   /**
    * retrieve the start slots for a category, to enable pre-assignment of starting slots
    * if Participant provided as well, further filter them down to the selection available to that one
    */
   private function getStartingSlotSelection(CategoryCollection $categories, ?Participant $participant = null): array
   {
      $MATCH_PREFIX = 'Kampf';
      $POOL_PREFIX = 'Pool';
      $RANDOM_VALUE = '?';

      $result = [];
      foreach( $categories as $category )
      {
         $struc = $this->tournamentService->initialize($category); // only initialize the basic structure, without loading any actual data
         $selection = ['' => $RANDOM_VALUE];

         if( $struc->pools->empty() )
         {
            foreach($struc->ko->getFirstRound() as $node)
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

      if( $participant )
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
    * Render the participant list page with optional errors and previous input data
    */
   private function renderParticipantList(Request $request, Response $response, array $args, array $errors = [], array $prev = []): Response
   {
      $tournament = $request->getAttribute('route_context')->tournament;
      $categories = $this->tournamentRepo->getCategoriesByTournamentId($tournament->id);
      $participants = $this->repo->getParticipantsByTournamentId($tournament->id);

      /* retrieve any possible PRG message and pre-process it */
      $status = $this->prgService->getStatusMessage() ?? [];
      if( isset($status['duplicate']) )
      {
         $status['duplicate'] = $participants->filter(fn($p) => in_array($p->id, $status['duplicate']));
      }

      return $this->view->render($response, 'tournament/participants/overview.twig', [
         'tournament'     => $tournament,
         'categories'     => $categories,
         'starting_slots' => $this->getStartingSlotSelection($categories),
         'participants'   => $participants,
         'prg_message'    => $status,
         'errors' => $errors,
         'prev'   => $prev,
      ]);
   }

   /**
    * Show the participant list for a tournament
    */
   public function showParticipantList(Request $request, Response $response, array $args): Response
   {
      return $this->renderParticipantList($request, $response, $args);
   }

   /**
    * Update the participant list for a tournament - this updates the mapping of participants to categories.
    */
   public function updateParticipantList(Request $request, Response $response, array $args): Response
   {
      $tournament = $request->getAttribute('route_context')->tournament;
      $categories = $this->tournamentRepo->getCategoriesByTournamentId($tournament->id);

      $validation_rules = [];
      /* preprocess category input data */
      foreach ($categories as $category)
      {
         $form_name = 'category_' . $category->id;
         $validation_rules[$form_name] = v::oneOf(
            v::nullType(),
            v::arrayType()->each(v::numericVal()->intVal()->notEmpty()->min(0))
         );
      }

      $data = $request->getParsedBody();
      $errors = DataValidationService::validate($data, $validation_rules);

      // return form if there are errors
      if (count($errors) > 0)
      {
         $err = ['participants' => ['update' => $errors]];
         $prev = ['participants' => ['update' => $data]];
         return $this->renderParticipantList($request, $response, $args, $err, $prev);
      }

      // update the mapping of participants to categories
      foreach ($categories as $category)
      {
         $participantIds = $data['category_' . $category->id] ?? [];
         $this->repo->setCategoryParticipants($category->id, $participantIds);
      }

      return $this->prgService->redirect($request, $response, 'show_participant_list', $args, ['status' => 'updated']);
   }

   /**
    * Import a list of participants from a text input
    * The input should be a string with one participant per line, either as "firstname lastname" or "lastname, firstname"
    * participants that are already registered will only be added to the new categories
    */
   public function importParticipantList(Request $request, Response $response, array $args): Response
   {
      $tournament = $request->getAttribute('route_context')->tournament;
      $categories = $this->tournamentRepo->getCategoriesByTournamentId($tournament->id);

      $data = $request->getParsedBody();
      $rules = [
         'participants' => v::stringType()->notEmpty()->length(1, max: 10000)->setTemplate('ungültige Länge'),
         'club' => v::stringType()->length(max:100),
         'categories' => v::arrayType()->each(v::numericVal()->intVal()->notEmpty()->in($categories->column('id')))->setTemplate('mindestens eine gültige Kategorie muss gewählt werden'),
      ];
      $errors = DataValidationService::validate($data, $rules);

      $p_categories = isset($errors['categories'])? CategoryCollection::new() : $categories->filter(fn($c) => in_array($c->id, $data['categories']));

      if (!isset($errors['participants']))
      {
         $import_report = $this->plistParser->import($data['participants']??'', $tournament->id, $p_categories, $data['club']??null);
         if (isset($import_report['errors']))
         {
            $errors['participants'] = "Konnte folgende Zeilen nicht erkennen: \n" . join("\n", $import_report['errors']);
         }
         elseif(empty($import_report['participants']))
         {
            $errors['participants'] = 'Keine Teilnehmer gefunden.';
         }
      }

      if (!empty($errors))
      {
         // If there are errors, render the participant list with errors
         $errors['input_error'] = true;
         return $this->renderParticipantList($request, $response, $args, $errors, $data);
      }

      $import_ok = $this->repo->importParticipants($import_report['new']);

      // Process the uploaded file and import participants
      if ($import_ok)
      {
         return $this->prgService->redirect($request, $response, 'show_participant_list', $args,
            [ 'status'    => 'imported',
              'duplicate' => $import_report['duplicate']->column('id')
            ]
         );
      }
      else
      {
         // If there are errors, render the participant list with errors
         $errors = [
            'sql_error' => $this->repo->getLastErrors(),
         ];
         return $this->renderParticipantList($request, $response, $args, $errors);
      }
   }

   /**
    * Delete a participant from a tournament
    */
   public function deleteParticipant(Request $request, Response $response, array $args): Response
   {
      if ($this->repo->deleteParticipant($request->getAttribute('route_context')->participant->id))
      {
         return $this->prgService->redirect($request, $response, 'show_participant_list', $args, ['status' => 'deleted']);
      }
      else
      {
         $response->getBody()->write('Failed to delete participant');
         return $response->withStatus(400);
      }
   }

   /**
    * Show the details of a participant in a tournament
    * This includes the participant's name and the categories they are registered in
    */
   public function showParticipant(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $categories = $this->tournamentRepo->getCategoriesByTournamentId($ctx->tournament->id);

      $starting_slots = $this->getStartingSlotSelection($categories, $ctx->participant);

      return $this->view->render($response, 'tournament/participants/details.twig', [
         'tournament'     => $ctx->tournament,
         'categories'     => $categories,
         'starting_slots' => $starting_slots,
         'participant'    => $ctx->participant,
      ]);
   }

   /**
    * Update the details of a participant in a tournament
    * This includes updating the participant's name and categories they are registered in
    */
   public function updateParticipant(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $categories = $this->tournamentRepo->getCategoriesByTournamentId($ctx->tournament->id);

      $starting_slots = $this->getStartingSlotSelection($categories, $ctx->participant);

      $data = $request->getParsedBody();
      $data['category_selection'] ??= [];
      $participant_rules = Participant::validationRules();
      $participant_rules['categories'] = v::arrayType()->each(v::numericVal()->intVal()->notEmpty()->min(0));
      $participant_rules['category_selection'] = $participant_rules['categories'];
      $participant_rules['pre_assign'] = v::arrayType();
      $errors = DataValidationService::validate($data, $participant_rules);

      // return form if there are errors
      if (!count($errors))
      {
         // Update participant data
         $ctx->participant->updateFromArray($data);

         // take over category assignment
         $ctx->participant->categories = CategoryAssignmentCollection::new(
            array_map(fn($id) => new CategoryAssignment($this->tournamentRepo->getCategoryById($id)), $data['category_selection']??[])
         );

         // take over pre-assigned slots
         for( $i = 0; $i < count($data['categories']); ++$i )
         {
            $categoryId = $data['categories'][$i];

            if( $assignment = $ctx->participant->categories[$categoryId] ?? null )
            {
               if( isset($starting_slots[$categoryId][$data['pre_assign'][$i]]) )
               {
                  $assignment->pre_assign = $data['pre_assign'][$i] ?: null;
               }
            }
         }

         // try to save
         if ($this->repo->saveParticipant($ctx->participant))
         {
            return $this->prgService->redirect($request, $response, 'show_participant', $args, 'updated');
         }
         else
         {
            $errors['sql_error'] = $this->repo->getLastErrors();
         }
      }

      return $this->view->render($response, 'tournament/participants/details.twig', [
         'tournament'  => $ctx->tournament,
         'categories'  => $categories,
         'participant' => $ctx->participant,
         'errors'      => $errors,
         'prev'        => $data,
      ]);
   }
}