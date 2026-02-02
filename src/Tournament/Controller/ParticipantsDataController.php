<?php

namespace Tournament\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

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
use Base\Service\TmpStorageService;
use Respect\Validation\Validator as v;

use Slim\Views\Twig;
use Slim\Routing\RouteContext;

class ParticipantsDataController
{
   const IMPORT_BUFFER_FILE = 'participant_import.json';

   public function __construct(
      private Twig $view,
      private ParticipantRepository $repo,
      private TournamentRepository $tournamentRepo,
      private ParticipantImportService $importService,
      private TournamentStructureService $tournamentService,
      private TmpStorageService $storage,
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
      if( isset($status['duplicates']) )
      {
         $status['duplicates'] = $participants->filter(fn($p) => in_array($p->id, $status['duplicates']));
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
   public function addParticipants(Request $request, Response $response, array $args): Response
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
         $import_report = $this->importService->importText($data['participants']??'', $tournament->id, $p_categories, $data['club']??null);
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

      $import_ok = $this->repo->importParticipants($import_report['participants']);

      // Process the uploaded file and import participants
      if ($import_ok )
      {
         return $this->prgService->redirect($request, $response, 'show_participant_list', $args,
            [ 'status'    => 'imported',
              'duplicates' => $import_report['duplicates']->column('id')
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
    * import a file of participants
    */
   public function uploadParticipantFile(Request $request, Response $response, array $args): Response
   {
      $file = $request->getUploadedFiles()['participant_file'];

      if (!isset($file) || $file->getError() !== UPLOAD_ERR_OK)
      {
         $errors['file'] = 'Upload fehlgeschlagen';
      }
      else
      {
         /* parse the imported file */
         $uploadPath = $file->getStream()->getMetadata('uri');
         $parsed = $this->importService->parseSpreadsheet($uploadPath);

         if( empty($parsed) )
         {
            $errors['file'] = 'Kein relevanter Inhalt gefunden';
         }
         else
         {
            /* for now, only take the first sheet */
            $parsed = $parsed[0];

            /* store it as json into the tmp directory */
            $current_user = $request->getAttribute('auth_context')->user;
            $this->storage->store($current_user->id, static::IMPORT_BUFFER_FILE, json_encode($parsed));

            return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()
               ->urlFor('upload_participants_confirm', $args))
               ->withStatus(302);
         }
      }

      return $this->renderParticipantList($request, $response, $args, $errors, []);
   }

   /**
    * confirm/adjust an upload of a file of participants
    */
   public function confirmUpload(Request $request, Response $response, array $args)
   {
      $current_user = $request->getAttribute('auth_context')->user;

      /* try to read in the buffered import file */
      $json = $this->storage->load($current_user->id, static::IMPORT_BUFFER_FILE);
      if( !$json )
      {
         // there is no import ongoing right now, just re-direct to the participant list.
         return $this->prgService->redirect($request,$response,'show_participant_list',$args,[]);
      }

      /* try to parse it */
      $import = json_decode($json, true);
      if ($import === null)
      {
         throw new \RuntimeException('Import nicht lesbar');
      }

      /* get a list of categories for the import */
      $tournament = $request->getAttribute('route_context')->tournament;
      $categories = $this->tournamentRepo->getCategoriesByTournamentId($tournament->id);

      /* derive the column type selection from expected column names according the ParticipantUploadService */
      $category_value = fn($c) => 'category_' . $c->id;
      $category_keys  = $categories->map($category_value);
      $column_types = ["" => "---"]
                    + array_map(fn($c) => $c[0], ParticipantImportService::EXPECTED_COLUMN_NAMES)
                    + array_combine($category_keys, $categories->column('name'));

      /* provide the category selection for the global category setting */
      $category_selection = ["" => "---"] + $categories->column('name', 'id');

      if( $request->getMethod() === 'POST' )
      {
         /* get the input */
         $data = $request->getParsedBody();

         /* check for abort action */
         if( $data['action'] === 'abort' )
         {
            return $this->abortUpload($request, $response, $args);
         }

         $unique_check = function($value)
         {
            $nonEmpty = array_filter($value);
            return count($nonEmpty) === count(array_unique($nonEmpty));
         };
         $column_cross_check = function(string $global_value, array $columns, array $blocked)
         {
            // if a global value is set, no related column value shall be set
            return !$global_value || !array_intersect($columns, $blocked);
         };

         /* validate input */
         $rules = [
            'start_row'  => v::intVal()->min(1)->max(count($import['rows'])),
            'club'       => v::optional(v::allOf(
               v::stringType()->length(max: 100),
               v::callback(fn($club) => $column_cross_check($club, $data['column_map'], ['club']))
                  ->setTemplate('Verband/Verein auch als Spalte gesetzt'),
            )),
            'category'   => v::optional(v::allOf(
               v::intVal()->in(array_keys($category_selection)),
               v::callback(fn($category) => $column_cross_check($category, $data['column_map'], $category_keys))
                  ->setTemplate('Globale Kategorie UND spaltenweise Kategorie-Zuordnung gesetzt')
            )),
            'column_map' => v::allOf(
               v::arrayType()->each(v::in(array_keys($column_types))), // shall be an array that only contains known column types
               v::callback($unique_check)->setTemplate('Jede Spaltenzuordnung darf nur einmal genutzt werden.'),
               v::callback(fn(array $coldata) => $column_cross_check($data['category']??'', $coldata, $category_keys))
                  ->setTemplate('Kategorie-Zuordnung via Spalte nicht erlaubt wenn globale Kategorie gesetzt'),
               v::callback(fn(array $coldata) => $column_cross_check($data['club']??'', $coldata, ['club']))
                  ->setTemplate('Club-Zuordnung via Spalte nicht erlaubt wenn Verein/Verband global gesetzt'),
            ),
         ];
         $errors = DataValidationService::validate($data, $rules);

         if( !$errors )
         {
            /* update $import data for the find_participant_rows() call below */
            $import['content_row'] = $data['start_row']-1; // user side row counting is 1-based
            foreach( $data['column_map'] as $index => $name )
            {
               $import['headers'][$name] ??= [];
               $import['headers'][$name]['column'] = $index;
            }
         }
      }
      else
      {
         // take over the default values from the parsed $import structure
         $data = [
            'start_row'  => $import['content_row'] + 1,
            'column_map' => array_combine(array_column($import['headers'], 'column'), array_keys($import['headers'])),
            'action'     => '',
         ];
      }

      if( $data['action'] === 'import' && !$errors )
      {
         /* map column mapping values to their actual category */
         $category_map = ['category' => $categories ]; // initialize with the "select any category column"
         foreach( $categories as $c )
         {
            $category_map[$category_value($c)] = $c;
         };

         /* check each mapping column whether it is assigned to a category */
         $category_column_map = [];
         foreach( $data['column_map'] as $i => $v)
         {
            $selected = $category_map[$v]??null;
            if( $selected ) $category_column_map[$i] = $selected;
         }

         /* global category setting */
         $global_category = $categories[$data['category']] ?? null;

         /* now actually parse the participant list and save it */
         $import_report = $this->importService->import($import, $tournament->id, $global_category, $category_column_map, $data['club']?:null);
         $import_ok = $this->repo->importParticipants($import_report['participants']);

         /* delete the buffered file */
         $this->storage->drop($current_user->id, static::IMPORT_BUFFER_FILE);

         if( $import_ok )
         {
            return $this->prgService->redirect($request, $response, 'show_participant_list', $args,
               [ 'status'     => 'imported',
                 'duplicates' => $import_report['duplicates']->column('id')
               ]
            );
         }
         else
         {
            // If there are errors, render the participant list directly with all info
            $errors = [ 'sql_error' => $this->repo->getLastErrors() ];
            return $this->renderParticipantList($request, $response, $args, $errors, []);
         }
      }
      else
      {
         /* provide the confirmation data */
         return $this->view->render($response, 'tournament/participants/upload_preview.twig', [
            'import'       => $import,
            'data_rows'    => $this->importService->findParticipantRows($import),
            'column_types' => $column_types,
            'category_sel' => $category_selection,
            'column_map'   => $data['column_map'],
            'start_row'    => $data['start_row'] ?? 1,
            'club'         => $data['club'] ?? '',
            'category'     => $data['category'] ?? '',
            'errors'       => $errors,
         ]);
      }
   }

   /**
    * Abort an file import
    */
   public function abortUpload(Request $request, Response $response, array $args): Response
   {
      $current_user = $request->getAttribute('auth_context')->user;
      $this->storage->drop($current_user->id, static::IMPORT_BUFFER_FILE);
      return $this->prgService->redirect($request, $response, 'show_participant_list', $args, false);
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