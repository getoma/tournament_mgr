<?php

namespace Tournament\Controller\App;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

use Tournament\Model\Category\CategoryCollection;
use Tournament\Model\Participant\Team;
use Tournament\Model\Participant\Participant;

use Tournament\Service\ParticipantHandlingService;
use Tournament\Service\ParticipantImportService;
use Tournament\Service\RouteArgsContext;

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
      private ParticipantHandlingService $service,
      private ParticipantRepository $repo,
      private TournamentRepository $tournamentRepo,
      private ParticipantImportService $importService,
      private TmpStorageService $storage,
      private PrgService $prgService,
   ) {
   }

   /**
    * Render the participant list page with optional errors and previous input data
    */
   public function showParticipantList(Request $request, Response $response, array $args, array $errors = [], array $prev = []): Response
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
         'starting_slots' => $this->service->getStartingSlotSelection($categories),
         'participants'   => $participants,
         'prg_message'    => $status,
         'errors' => $errors,
         'prev'   => $prev,
      ]);
   }

   /**
    * Assign all unslotted participants for the current tournament
    */
   public function assignParticipants(Request $request, Response $response): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $categories = $ctx->category? [ $ctx->category ] : $this->tournamentRepo->getCategoriesByTournamentId($ctx->tournament->id);
      $this->service->assignParticipants($categories);
      return $this->prgService->redirectBack($request, $response, 'assigned');
   }

   /**
    * Re-shuffle all participants
    */
   public function shuffleParticipants(Request $request, Response $response): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $categories = $ctx->category ? [$ctx->category] : $this->tournamentRepo->getCategoriesByTournamentId($ctx->tournament->id);
      $this->service->shuffleParticipants($categories);
      return $this->prgService->redirectBack($request, $response, 'assigned');
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
         return $this->showParticipantList($request, $response, $args, $errors, $data);
      }

      $this->repo->importParticipants($import_report['participants']);

      // Process the uploaded file and import participants
      return $this->prgService->redirect($request, $response, 'tournaments.participants.index', $args, [
         'status'     => 'imported',
         'duplicates' => $import_report['duplicates']->column('id')
      ]);
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
               ->urlFor('tournaments.participants.import.show', $args))
               ->withStatus(302);
         }
      }

      return $this->showParticipantList($request, $response, $args, $errors);
   }

   /**
    * confirm/adjust an upload of a file of participants
    */
   public function handleImport(Request $request, Response $response, array $args)
   {
      $current_user = $request->getAttribute('auth_context')->user;

      /* try to read in the buffered import file */
      $json = $this->storage->load($current_user->id, static::IMPORT_BUFFER_FILE);
      if( !$json )
      {
         // there is no import ongoing right now, just re-direct to the participant list.
         return $this->prgService->redirect($request,$response,'tournaments.participants.index',$args,[]);
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

      /* get the import parsing parameters */
      $data = $request->getMethod() === 'GET'? $request->getQueryParams() : $data = $request->getParsedBody();

      /* for empty input, take over default values from import structure */
      $data['start_row']  ??= $import['content_row'] + 1;
      $data['column_map'] ??= array_combine(array_column($import['headers'], 'column'), array_keys($import['headers']));

      /* validate input */
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

      if( $request->getMethod() === 'POST' && !$errors )
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
         $this->repo->importParticipants($import_report['participants']);

         /* delete the buffered file */
         $this->storage->drop($current_user->id, static::IMPORT_BUFFER_FILE);

         return $this->prgService->redirect($request, $response, 'tournaments.participants.index', $args, [
            'status'     => 'imported',
            'duplicates' => $import_report['duplicates']->column('id')
         ]);
      }
      else
      {
         /* provide the preview data */
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
      return $this->prgService->redirect($request, $response, 'tournaments.participants.index', $args, false);
   }

   /**
    * Delete a participant from a tournament
    */
   public function deleteParticipant(Request $request, Response $response, array $args): Response
   {
      $this->repo->deleteParticipant($request->getAttribute('route_context')->participant->id);
      return $this->prgService->redirect($request, $response, 'tournaments.participants.index', $args, ['status' => 'deleted']);
   }

   /**
    * Show the details of a participant in a tournament
    * This includes the participant's name and the categories they are registered in
    * This method may also be called with no participant in the context to get the form
    * to create a new participant.
    */
   public function showParticipantDetails(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $categories = $this->tournamentRepo->getCategoriesByTournamentId($ctx->tournament->id);

      $starting_slots = $this->service->getStartingSlotSelection($categories, $ctx->participant);

      $withdrawal_allowed = $ctx->participant && $this->service->mayWithdraw($ctx->participant);

      return $this->view->render($response, 'tournament/participants/details.twig', [
         'tournament'       => $ctx->tournament,
         'categories'       => $categories,
         'starting_slots'   => $starting_slots,
         'participant'      => $ctx->participant ?? null, // null to get the form for a new participant
         'withdraw_allowed' => $withdrawal_allowed,
      ]);
   }

   /**
    * Update the details of a participant in a tournament
    * This includes updating the participant's name and categories they are registered in
    */
   public function saveParticipant(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $categories = $this->tournamentRepo->getCategoriesByTournamentId($ctx->tournament->id);

      $data = $request->getParsedBody();
      $errors = $this->service->validateParticipantData($data, $ctx->tournament->id, $ctx->participant);

      if($errors)
      {
         return $this->view->render($response, 'tournament/participants/details.twig', [
            'tournament'  => $ctx->tournament,
            'categories'  => $categories,
            'participant' => $ctx->participant,
            'errors'      => $errors,
            'prev'        => $data,
         ]);
      }
      else
      {
         $participant = $ctx->participant?
            $this->service->patchParticipant($ctx->participant, $data)
          : $this->service->storeParticipant($ctx->tournament, $data);

         return $this->prgService->redirect(
            $request,
            $response,
            'tournaments.participants.show',
            $ctx->args + ['participantId' => $participant->id],
            $ctx->participant ? 'updated' : 'created'
         );
      }
   }

   /*********************************
    * TEAM MANAGEMENT
    */

   /**
    * team index page - show a list of all teams and their members
    */
   public function listTeams(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $teams = $this->repo->getTeamsByCategoryId($ctx->category->id);
      return $this->view->render($response, 'tournament/teams/index.twig', [
         'teams' => $teams
      ]);
   }

   /**
    * show the configuration page of a specific team
    */
   public function showTeam(Request $request, Response $response, array $args, array $errors = [], array $prev = []): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $category_participants = $this->repo->getParticipantsByCategoryId($ctx->category->id);
      $all_teams = $this->repo->getTeamsByCategoryId($ctx->category->id);
      $team_mapping = $this->repo->getParticipantTeamMapping($ctx->category->id);

      /* build the option list for team member selection */
      $member_selection = [ '' => '--' ];
      foreach( $category_participants as $p )
      {
         /** @var Participant $p */
         $disp = $p->getDisplayName();
         if( $team = $team_mapping[$p->id] ) // show each members current team as well
         {
            $disp .= " (" . $team->getDisplayName() . ")";
         }
         $member_selection[$p->id] = $disp;
      }

      return $this->view->render($response, 'tournament/teams/details.twig', [
         'team'             => $ctx->team,                   // explicitly forward the selected team for this page
         'team_members'     => $ctx->team?->members->keys(), // needed to set current values to select-boxes
         'member_selection' => $member_selection,            // option-list of select-boxes
         'all_teams'        => $all_teams,                   // needed for prgMessage if members were moved from another team
         'errors' => $errors,
         'prev'   => $prev,
      ]);
   }

   /**
    * update a specific team
    */
   public function saveTeam(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      $data = $request->getParsedBody();
      $errors = $this->service->validateTeamData($data, $ctx->category, $ctx->team);

      if ($errors)
      {
         return $this->showTeam($request, $response, $args, $errors, $data);
      }
      else
      {
         $team   = $ctx->team ?? Team::createFromArray($ctx->category->id, $data);
         $report = $this->service->updateTeam($team, $data);

         return $this->prgService->redirect($request, $response, 'tournaments.categories.teams.show',
            args:       $ctx->args + ['teamId' => $team->id],
            prgMessage: ['status'  => $ctx->team? 'updated' : 'stored',
                         'moved'   => array_filter($report),
                         'unknown' => array_filter($report, fn($e) => !$e)],
         );
      }
   }

   /**
    * delete a specific team
    */
   public function deleteTeam(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $this->repo->deleteTeam($ctx->team->id);
      return $this->prgService->redirect($request, $response, 'tournaments.categories.teams.index', $args,
         prgMessage: [ 'status' => 'removed',
                       'team'   => $ctx->team->getDisplayName() ]
      );
   }
}

