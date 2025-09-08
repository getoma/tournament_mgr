<?php

namespace Tournament\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Slim\Routing\RouteContext;

use Tournament\Repository\TournamentRepository;
use Tournament\Repository\CategoryRepository;
use Tournament\Repository\ParticipantRepository;

use Tournament\Model\Data\Participant;

use Base\Service\Validator;
use Respect\Validation\Validator as v;

class ParticipantsController
{
   public function __construct(
      private Twig $view,
      private ParticipantRepository $repo,
      private TournamentRepository $tournamentRepo,
      private CategoryRepository $categoryRepo,
   ) {
   }

   /**
    * Render the participant list page with optional errors and previous input data
    */
   private function renderParticipantList(Request $request, Response $response, array $args, array $errors = [], array $prev = []): Response
   {
      $tournament = $this->tournamentRepo->getTournamentById($args['id']);
      if (!$tournament)
      {
         $response->getBody()->write('Tournament not found');
         return $response->withStatus(404);
      }

      $categories = $this->categoryRepo->getCategoriesByTournamentId($args['id']);
      $participants = $this->repo->getParticipantsByTournamentId($args['id'], true);

      return $this->view->render($response, 'participants/home.twig', [
         'tournament' => $tournament,
         'categories' => $categories,
         'participants' => $participants,
         'errors' => $errors,
         'prev' => $prev,
      ]);
   }

   /**
    * Redirect to the participants details page after an action
    */
   private function sendToParticipantList(Request $request, Response $response, array $args): Response
   {
      return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()
         ->urlFor('show_participant_list', ['id' => $args['id']]))->withStatus(302);
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
      $tournament = $this->tournamentRepo->getTournamentById($args['id']);
      if (!$tournament)
      {
         $response->getBody()->write('Tournament not found');
         return $response->withStatus(404);
      }

      $categories = $this->categoryRepo->getCategoriesByTournamentId($args['id']);
      $validation_rules = [];
      foreach ($categories as $category)
      {
         $validation_rules['category_' . $category->id] = v::arrayType()->each(
            v::numericVal()->intVal()->notEmpty()->min(0)
         );
      }

      $data = $request->getParsedBody();
      $errors = Validator::validate($data, $validation_rules);

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
         $this->categoryRepo->setCategoryParticipants($category->id, $participantIds);
      }

      return $this->sendToParticipantList($request, $response, $args);
   }

   /**
    * Import a list of participants from a text input
    * The input should be a string with one participant per line, either as "firstname lastname" or "lastname, firstname"
    * participants that are already registered will only be added to the new categories
    */
   public function importParticipantList(Request $request, Response $response, array $args): Response
   {
      $tournament = $this->tournamentRepo->getTournamentById($args['id']);
      if (!$tournament)
      {
         $response->getBody()->write('Tournament not found');
         return $response->withStatus(404);
      }

      $data = $request->getParsedBody();
      $rules = [
         'categories' => v::arrayType()->each(v::numericVal()->intVal()->notEmpty()->min(0)),
         'participants' => v::stringType()->notEmpty()->length(1, max: 10000)->setTemplate('ungültige Länge')
      ];
      $errors = Validator::validate($data, $rules);

      /* parse the file content into an array - one participant per line, either as:
         - "firstname lastname", or
         - "lastname, firstname"
      */

      $participants = [];
      $parse_errors = [];

      if (!array_key_exists('participants', $errors) && !empty($data['participants']))
      {
         $lines = explode("\n", $data['participants'] ?? '');
         foreach ($lines as $line)
         {
            $line = trim($line);
            if (empty($line))
               continue; // skip empty lines

            // Split by comma if present, otherwise treat as "firstname lastname"
            if (strpos($line, ',') !== false)
            {
               list($lastname, $firstname) = explode(',', $line, 2);
            }
            elseif (strpos($line, ' ') !== false)
            {
               list($firstname, $lastname) = explode(' ', $line, 2);
            }
            else
            {
               $parse_errors[] = "Kann Namen nicht erkennen (Nachname, Vorname nötig): '$line'";
               continue; // skip invalid lines
            }
            $participants[] = ['firstname' => trim($firstname), 'lastname' => trim($lastname)];
         }

         if (count($parse_errors))
         {
            $errors['participants'] = join("\n", $parse_errors);
         }
      }

      if (count($participants) === 0 && count($errors) === 0)
      {
         $errors['participants'] = 'Keine Teilnehmer gefunden.';
      }

      if (count($errors) > 0)
      {
         // If there are errors, render the participant list with errors
         return $this->renderParticipantList($request, $response, $args, $errors, $data);
      }

      // Process the uploaded file and import participants
      $imported = $this->repo->importParticipants($tournament->id, $participants, $data['categories'] ?? []);
      if ($imported)
      {
         return $this->sendToParticipantList($request, $response, $args);
      }

      $response->getBody()->write('Failed to import participants');
      return $response->withStatus(500);
   }

   /**
    * Delete a participant from a tournament
    */
   public function deleteParticipant(Request $request, Response $response, array $args): Response
   {
      $tournament = $this->tournamentRepo->getTournamentById($args['id']);
      if (!$tournament)
      {
         $response->getBody()->write('Tournament not found');
         return $response->withStatus(404);
      }

      $participantId = $args['participantId'] ?? null;
      if (!$participantId || !$this->repo->getParticipantById($participantId))
      {
         $response->getBody()->write('Participant not found');
         return $response->withStatus(404);
      }

      $deleted = $this->repo->deleteParticipant($participantId);
      if ($deleted)
      {
         return $this->sendToParticipantList($request, $response, $args);
      }

      $response->getBody()->write('Failed to delete participant');
      return $response->withStatus(400);
   }

   /**
    * Show the details of a participant in a tournament
    * This includes the participant's name and the categories they are registered in
    */
   public function showParticipant(Request $request, Response $response, array $args): Response
   {
      $tournament = $this->tournamentRepo->getTournamentById($args['id']);
      if (!$tournament)
      {
         $response->getBody()->write('Tournament not found');
         return $response->withStatus(404);
      }

      $participant = $this->repo->getParticipantById($args['participantId']);
      if (!$participant)
      {
         $response->getBody()->write('Participant not found');
         return $response->withStatus(404);
      }

      $categories = $this->categoryRepo->getCategoriesByTournamentId($args['id']);

      return $this->view->render($response, 'participants/details.twig', [
         'tournament' => $tournament,
         'categories' => $categories,
         'participant' => $participant,
      ]);
   }

   /**
    * Update the details of a participant in a tournament
    * This includes updating the participant's name and categories they are registered in
    */
   public function updateParticipant(Request $request, Response $response, array $args): Response
   {
      $tournament = $this->tournamentRepo->getTournamentById($args['id']);
      if (!$tournament)
      {
         $response->getBody()->write('Tournament not found');
         return $response->withStatus(404);
      }

      $participant = $this->repo->getParticipantById($args['participantId']);
      if (!$participant)
      {
         $response->getBody()->write('Participant not found');
         return $response->withStatus(404);
      }

      $data = $request->getParsedBody();
      $participant_rules = Participant::getValidationRules('create');
      $participant_rules['categories'] = v::arrayType()->each(v::numericVal()->intVal()->notEmpty()->min(0));
      $errors = Validator::validate($data, $participant_rules);

      // return form if there are errors
      if (count($errors) > 0)
      {
         return $this->view->render($response, 'participants/details.twig', [
            'tournament' => $tournament,
            'categories' => $this->categoryRepo->getCategoriesByTournamentId($args['id']),
            'participant' => $participant,
            'errors' => $errors,
            'prev' => $data,
         ]);
      }

      // Update participant data
      if ($this->repo->updateParticipant($participant->id, $data))
      {
         return $this->sendToParticipantList($request, $response, $args);
      }

      $response->getBody()->write('Failed to update participant');
      return $response->withStatus(400);
   }
}