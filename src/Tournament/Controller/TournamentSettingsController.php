<?php

namespace Tournament\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Slim\Routing\RouteContext;

use Tournament\Repository\TournamentRepository;

use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\Category\CategoryConfiguration;
use Tournament\Model\Tournament\Tournament;
use Tournament\Model\Tournament\TournamentStatus;

use Tournament\Policy\TournamentPolicy;
use Tournament\Service\TournamentStructureService;

use Base\Service\Validator;


class TournamentSettingsController
{
   public function __construct(
      private Twig $view,
      private TournamentRepository $repo,
      private TournamentStructureService $structureLoadService,
      private TournamentPolicy $policy,
   ) {
   }

   /**
    * Show a specific tournament
    */
   public function showTournament(Request $request, Response $response, array $args): Response
   {
      $categories = $this->repo->getCategoriesByTournamentId($request->getAttribute('tournament')->id);

      return $this->view->render($response, 'tournament/home.twig', [
         'categories' => $categories,
      ]);
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
    * Show a tournament control panel
    */
   public function showControlPanel(Request $request, Response $response, array $args): Response
   {
      $categories = $this->repo->getCategoriesByTournamentId($request->getAttribute('tournament')->id);

      return $this->view->render($response, 'tournament/controlpanel.twig', [
         'categories' => $categories,
      ]);
   }

   /**
    * Show the configuration page of a tournament
    */
   public function showTournamentConfiguration(Request $request, Response $response, array $args, array $errors = [], array $prev = []): Response
   {
      $tournament = $request->getAttribute('tournament');
      $categories = $this->repo->getCategoriesByTournamentId($tournament->id);
      $areas = $this->repo->getAreasByTournamentId($tournament->id);

      return $this->view->render($response, 'tournament/configure.twig', [
         'areas' => $areas,
         'categories' => $categories,
         'category_modes' => Category::get_modes(),
         'errors' => $errors,
         'prev' => $prev,
      ]);
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
         return $this->showTournamentConfiguration($request, $response, $args, $err, $prev);
      }

      $tournament = $request->getAttribute('tournament');
      $tournament->name = $data['name'];
      $tournament->date = $data['date'];
      $tournament->notes = $data['notes'] ?? null;
      $this->repo->updateTournament($tournament);

      return $this->sendToTournamentConfiguration($request, $response, $args);
   }

   public function changeTournamentStatus(Request $request, Response $response, array $args): Response
   {
      $data = $request->getParsedBody();
      $new_state = TournamentStatus::load($data['status']);

      $tournament = $request->getAttribute('tournament');

      if( $this->policy->canTransition($tournament, $new_state) )
      {
         $this->repo->updateState($tournament->id, $new_state);
      }
      else
      {
         $err = ['status' => 'not allowed'];
         return $this->showTournamentConfiguration($request, $response, $args, $err);
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
         return $this->showTournamentConfiguration($request, $response, $args, $err, $prev);
      }

      $area = new Area(null, $request->getAttribute('tournament')->id, $data['name']);
      $this->repo->createArea($area);

      return $this->sendToTournamentConfiguration($request, $response, $args);
   }

   /**
    * Update an existing area for the tournament
    */
   public function updateArea(Request $request, Response $response, array $args): Response
   {
      $data = $request->getParsedBody();
      $errors = Validator::validate($data, Area::getValidationRules());

      $area = $request->getAttribute('area');

      if (count($errors) > 0)
      {
         $prev = ['areas' => [$area->id => $data]];
         $err = ['areas' => [$area->id => $errors]];
         return $this->showTournamentConfiguration($request, $response, $args, $err, $prev);
      }

      $area->name = $data['name'];
      $this->repo->updateArea($area);

      return $this->sendToTournamentConfiguration($request, $response, $args);
   }

   /**
    * Delete an area from the tournament
    */
   public function deleteArea(Request $request, Response $response, array $args): Response
   {
      $this->repo->deleteArea($request->getAttribute('area')->id);
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
         return $this->showTournamentConfiguration($request, $response, $args, $err, $prev);
      }

      $this->repo->createCategory($request->getAttribute('tournament')->id, $data['name'], $data['mode']);

      return $this->sendToTournamentConfiguration($request, $response, $args);
   }

   /**
    * Update an existing category for the tournament
    */
   public function updateCategory(Request $request, Response $response, array $args): Response
   {
      $data = $request->getParsedBody();
      $errors = Validator::validate($data, Category::getValidationRules());

      $category = $request->getAttribute('category');

      if (count($errors) > 0)
      {
         $prev = ['categories' => [$category->id => $data]];
         $err = ['categories' => [$category->id => $errors]];
         return $this->showTournamentConfiguration($request, $response, $args, $err, $prev);
      }

      $data['id'] = $category->id;
      if (!$this->repo->updateCategory($data))
      {
         return $this->showTournamentConfiguration($request, $response, $args, ['category' => ['update' => 'Failed to update category']], $data);
      }

      return $this->sendToTournamentConfiguration($request, $response, $args);
   }

   /**
    * Delete a category from the tournament
    */
   public function deleteCategory(Request $request, Response $response, array $args): Response
   {
      $this->repo->deleteCategory($request->getAttribute('category')->id);
      return $this->sendToTournamentConfiguration($request, $response, $args);
   }

   /**
    * Render the form to configure a category
    */
   public function showCategoryConfiguration(
      Request $request,
      Response $response,
      array $args,
      array $errors = [],
      array $prev = []
   ): Response
   {
      $tournament = $request->getAttribute('tournament');
      $category = $request->getAttribute('category');
      $data = ($request->getMethod() === 'POST') ? $request->getParsedBody() : $request->getQueryParams();

      /* verify return_to parameter */
      if (empty($data['return_to']??''))
      {
         $return_to = RouteContext::fromRequest($request)->getRouteParser()
            ->urlFor('show_category', ['tournamentId' => $tournament->id, 'categoryId' => $category->id]);
      }
      else
      {
         $return_to = $data['return_to'];
      }

      return $this->view->render($response, 'category/configure.twig', [
         'config' => $category->config,
         'errors' => $errors,
         'prev' => $prev,
         'return_to' => $return_to,
         'category_modes' => Category::get_modes(),
         'category_seedings' => Category::get_seedings(),
      ]);
   }

   /**
    * Update the category configuration
    */
   public function updateCategoryConfiguration(Request $request, Response $response, array $args): Response
   {
      // parse input
      $data = $request->getParsedBody();
      $rules = Category::getValidationRules('details');
      $errors = Validator::validate($data, $rules);

      // return form if there are errors
      if (count($errors) > 0)
      {
         $prev = ['config' => $data];
         $err = ['config' => $errors];
         return $this->showCategoryConfiguration($request, $response, $args, $err, $prev);
      }

      // update the Category accordingly
      $category = $request->getAttribute('category');
      $category->mode = $data['mode'];
      $category->config = CategoryConfiguration::fromArray($data);

      /* store the new category configuration */
      $this->repo->updateCategoryDetails([
         'id' => $category->id,
         'mode' => $category->mode,
         'config' => $category->config->toArray()
      ]);

      /* reshuffle the participants into the new configuration */
      $this->structureLoadService->populate($category);

      /* forward to category page */
      return $response->withHeader(
         'Location',
         RouteContext::fromRequest($request)->getRouteParser()->urlFor('show_category', $args)
      )->withStatus(302);
   }
}
