<?php

namespace Tournament\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Slim\Routing\RouteContext;

use Base\Service\Validator;

use Tournament\Repository\TournamentRepository;
use Tournament\Repository\AreaRepository;
use Tournament\Repository\CategoryRepository;

use Tournament\Model\Data\Area;
use Tournament\Model\Data\Category;
use Tournament\Model\Data\Tournament;
use Tournament\Model\Data\TournamentStatus;

use Tournament\Policy\TournamentPolicy;


class TournamentController
{
   public function __construct(
      private Twig $view,
      private TournamentRepository $repo,
      private AreaRepository $areaRepo,
      private CategoryRepository $categoryRepo,
      private TournamentPolicy $policy,
   ) {
   }

   /**
    * Show the form to create a new tournament
    */
   public function showFormNewTournament(Request $request, Response $response, array $args): Response
   {
      return $this->view->render($response, 'tournament/new.twig');
   }

   /**
    * Create a new tournament
    */
   public function createTournament(Request $request, Response $response, array $args): Response
   {
      $data = $request->getParsedBody();
      $errors = Validator::validate($data, Tournament::getValidationRules('create'));

      // return form if there are errors
      if (count($errors) > 0)
      {
         return $this->view->render($response, 'tournament/new.twig', [
            'errors' => ['tournament' => $errors ],
            'prev'   => ['tournament' => $data]
         ]);
      }

      // everything is ok, create the tournament
      $tournament_id = $this->repo->createTournament(...$data);

      return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()
         ->urlFor('show_tournament_config', ['tournamentId' => $tournament_id]))->withStatus(302);
   }

   /**
    * Render the form to edit an existing tournament
    */
   private function renderTournamentConfiguration(Response $response, array $args, array $errors = [], array $prev = []): Response
   {
      $tournament = $this->repo->getTournamentById($args['tournamentId']);
      if (!$tournament)
      {
         $response->getBody()->write('Tournament not found');
         return $response->withStatus(404);
      }
      $categories = $this->categoryRepo->getCategoriesByTournamentId($args['tournamentId']);
      $areas = $this->areaRepo->getAreasByTournamentId($args['tournamentId']);

      return $this->view->render($response, 'tournament/configure.twig', [
         'tournament' => $tournament,
         'areas' => $areas,
         'categories' => $categories,
         'category_modes' => Category::get_modes(),
         'errors' => $errors,
         'prev' => $prev,
      ]);
   }

   /**
    * Show a tournament
    */
   public function showTournamentConfiguration(Request $request, Response $response, array $args): Response
   {
      return $this->renderTournamentConfiguration($response, $args);
   }

   /**
    * Redirect to the tournament details page after an action
    */
   private function sendToTournamentConfiguration(Request $request, Response $response, array $args): Response
   {
      return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()
         ->urlFor('show_tournament_config', ['tournamentId' => $args['tournamentId']]))->withStatus(302);
   }

   /**
    * Update tournament details
    */
   public function updateTournament(Request $request, Response $response, array $args): Response
   {
      $data = $request->getParsedBody();
      $errors = Validator::validate($data, Tournament::getValidationRules());

      // return form if there are errors
      if (count($errors) > 0)
      {
         $prev = ['tournament' => $data];
         $err = ['tournament' => $errors];
         return $this->renderTournamentConfiguration($response, $args, $err, $prev);
      }

      // everything is ok, update the tournament
      $tournament = $this->repo->getTournamentById($args['tournamentId']);
      if (!$tournament)
      {
         $response->getBody()->write('Tournament not found');
         return $response->withStatus(404);
      }
      $tournament->name = $data['name'];
      $tournament->date = $data['date'];
      $tournament->notes = $data['notes'] ?? null;
      $this->repo->updateTournament($tournament);

      return $this->sendToTournamentConfiguration($request, $response, $args);
   }

   public function changeTournamentStatus(Request $request, Response $response, array $args): Response
   {
      $tournament_id = (int)$args['tournamentId'];

      $data = $request->getParsedBody();
      $new_state = TournamentStatus::load($data['status']);

      if( $this->policy->canTransition($tournament_id, $new_state) )
      {
         $this->repo->updateState($tournament_id, $new_state);
      }
      else
      {
         $err = ['status' => 'not allowed'];
         return $this->renderTournamentConfiguration($response, $args, $err);
      }

      return $this->sendToTournamentConfiguration($request, $response, $args);
   }

   /**
    * Create a new area for the tournament
    */
   public function createArea(Request $request, Response $response, array $args): Response
   {
      $data = $request->getParsedBody();
      $errors = Validator::validate($data, Area::getValidationRules());

      if (count($errors) > 0)
      {
         $prev = ['areas' => ['new' => $data]];
         $err = ['areas' => ['new' => $errors]];
         return $this->renderTournamentConfiguration($response, $args, $err, $prev);
      }

      $area = new Area(null, $args['tournamentId'], $data['name']);
      $this->areaRepo->createArea($area);

      return $this->sendToTournamentConfiguration($request, $response, $args);
   }

   /**
    * Update an existing area for the tournament
    */
   public function updateArea(Request $request, Response $response, array $args): Response
   {
      $data = $request->getParsedBody();
      $errors = Validator::validate($data, Area::getValidationRules());

      if (count($errors) > 0)
      {
         $prev = ['areas' => [$args['areaId'] => $data]];
         $err = ['areas' => [$args['areaId'] => $errors]];
         return $this->renderTournamentConfiguration($response, $args, $err, $prev);
      }

      $area = new Area($args['areaId'], $args['tournamentId'], $data['name']);
      $this->areaRepo->updateArea($area);

      return $this->sendToTournamentConfiguration($request, $response, $args);
   }

   /**
    * Delete an area from the tournament
    */
   public function deleteArea(Request $request, Response $response, array $args): Response
   {
      $this->areaRepo->deleteArea($args['areaId']);
      return $this->sendToTournamentConfiguration($request, $response, $args);
   }

   /**
    * Create a new category for the tournament
    */
   public function createCategory(Request $request, Response $response, array $args): Response
   {
      $data = $request->getParsedBody();
      $errors = Validator::validate($data, Category::getValidationRules('create'));

      if (count($errors) > 0)
      {
         $prev = ['categories' => ['new' => $data]];
         $err = ['categories' => ['new' => $errors]];
         return $this->renderTournamentConfiguration($response, $args, $err, $prev);
      }

      $this->categoryRepo->createCategory($args['tournamentId'], $data['name'], $data['mode']);

      return $this->sendToTournamentConfiguration($request, $response, $args);
   }

   /**
    * Update an existing category for the tournament
    */
   public function updateCategory(Request $request, Response $response, array $args): Response
   {
      $data = $request->getParsedBody();
      $errors = Validator::validate($data, Category::getValidationRules());

      if (count($errors) > 0)
      {
         $prev = ['categories' => [$args['categoryId'] => $data]];
         $err = ['categories' => [$args['categoryId'] => $errors]];
         return $this->renderTournamentConfiguration($response, $args, $err, $prev);
      }

      $data['id'] = $args['categoryId'];
      if (!$this->categoryRepo->updateCategory($data))
      {
         return $this->renderTournamentConfiguration($response, $args, ['category' => ['update' => 'Failed to update category']], $data);
      }

      return $this->sendToTournamentConfiguration($request, $response, $args);
   }

   /**
    * Delete a category from the tournament
    */
   public function deleteCategory(Request $request, Response $response, array $args): Response
   {
      $this->categoryRepo->deleteCategory($args['categoryId']);
      return $this->sendToTournamentConfiguration($request, $response, $args);
   }
}
