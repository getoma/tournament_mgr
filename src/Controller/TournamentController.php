<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Slim\Routing\RouteContext;

use App\Repository\TournamentRepository;
use App\Repository\AreaRepository;
use App\Repository\CategoryRepository;
use App\Model\Data\Area;
use App\Model\Data\Category;
use App\Model\Data\Tournament;
use App\Service\Validator;


class TournamentController
{
   public function __construct(
      private Twig $view,
      private TournamentRepository $repo,
      private AreaRepository $areaRepo,
      private CategoryRepository $categoryRepo,
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
         ->urlFor('show_tournament_config', ['id' => $tournament_id]))->withStatus(302);
   }

   /**
    * Render the form to edit an existing tournament
    */
   private function renderTournamentConfiguration(Response $response, array $args, array $errors = [], array $prev = []): Response
   {
      $tournament = $this->repo->getTournamentById($args['id']);
      if (!$tournament)
      {
         return $response->withStatus(404)->write('Tournament not found');
      }
      $categories = $this->categoryRepo->getCategoriesByTournamentId($args['id']);
      $areas = $this->areaRepo->getAreasByTournamentId($args['id']);

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
         ->urlFor('show_tournament_config', ['id' => $args['id']]))->withStatus(302);
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
      $tournament = $this->repo->getTournamentById($args['id']);
      if (!$tournament)
      {
         return $response->withStatus(404)->write('Tournament not found');
      }
      $tournament->name = $data['name'];
      $tournament->date = $data['date'];
      $tournament->notes = $data['notes'] ?? null;
      $this->repo->updateTournament($tournament);

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

      $area = new Area(null, $args['id'], $data['name']);
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

      $area = new Area($args['areaId'], $args['id'], $data['name']);
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

      $this->categoryRepo->createCategory($args['id'], $data['name'], $data['mode']);

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
