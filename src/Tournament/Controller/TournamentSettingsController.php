<?php

namespace Tournament\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Slim\Routing\RouteContext;

use Tournament\Repository\TournamentRepository;

use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\Category\CategoryMode;
use Tournament\Model\Tournament\Tournament;
use Tournament\Model\Tournament\TournamentStatus;

use Tournament\Policy\TournamentPolicy;
use Tournament\Service\RouteArgsContext;
use Tournament\Service\TournamentStructureService;


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
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $categories = $this->repo->getCategoriesByTournamentId($ctx->tournament->id);

      return $this->view->render($response, 'tournament/navigation/tournament_home.twig', [
         'categories' => $categories,
      ]);
   }

   /**
    * Show the form to create a new tournament
    */
   public function showFormNewTournament(Request $request, Response $response, array $args, array $errors = [], array $prev = []): Response
   {
      return $this->view->render($response, 'tournament/settings/tournament_new.twig', [
         'errors' => ['tournament' => $errors],
         'prev'   => ['tournament' => $prev]
      ]);
   }

   /**
    * Create a new tournament
    */
   public function createTournament(Request $request, Response $response, array $args): Response
   {
      $data = $request->getParsedBody();
      $errors = Tournament::validateArray($data);

      // return form if there are errors
      if (count($errors) > 0)
      {
         return $this->showFormNewTournament($request, $response, $args, $errors, $data);
      }

      // everything is ok, create the tournament
      $data['id'] = null;
      $tournament = new Tournament(...$data);
      $this->repo->saveTournament($tournament);

      return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()
         ->urlFor('show_tournament_config', ['tournamentId' => $tournament->id]))->withStatus(302);
   }

   /**
    * Show a tournament control panel
    */
   public function showControlPanel(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $categories = $this->repo->getCategoriesByTournamentId($ctx->tournament->id);

      return $this->view->render($response, 'tournament/navigation/controlpanel.twig', [
         'categories' => $categories,
      ]);
   }

   /**
    * Show the configuration page of a tournament
    */
   public function showTournamentConfiguration(Request $request, Response $response, array $args, array $errors = [], array $prev = []): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $categories = $this->repo->getCategoriesByTournamentId($ctx->tournament->id);
      $areas = $this->repo->getAreasByTournamentId($ctx->tournament->id);

      return $this->view->render($response, 'tournament/settings/tournament.twig', [
         'areas' => $areas,
         'categories' => $categories,
         'category_modes' => CategoryMode::cases(),
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
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      $data = $request->getParsedBody();
      $errors = Tournament::validateArray($data);

      // return form if there are errors
      if (count($errors) > 0)
      {
         $prev = ['tournament' => $data];
         $err = ['tournament' => $errors];
         return $this->showTournamentConfiguration($request, $response, $args, $err, $prev);
      }

      $ctx->tournament->updateFromArray($data);
      $this->repo->saveTournament($ctx->tournament);

      return $this->sendToTournamentConfiguration($request, $response, $args);
   }

   public function changeTournamentStatus(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      $data = $request->getParsedBody();
      $new_state = TournamentStatus::tryFrom($data['status']);

      if( $new_state && $this->policy->canTransition($ctx->tournament, $new_state) )
      {
         $ctx->tournament->status = $new_state;
         $this->repo->saveTournament($ctx->tournament);
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
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      $data = $request->getParsedBody();
      $errors = Area::validateArray($data);

      if (count($errors) > 0)
      {
         $prev = ['areas' => ['new' => $data]];
         $err = ['areas' => ['new' => $errors]];
         return $this->showTournamentConfiguration($request, $response, $args, $err, $prev);
      }

      $area = new Area(null, $ctx->tournament->id, $data['name']);
      $this->repo->saveArea($area);

      return $this->sendToTournamentConfiguration($request, $response, $args);
   }

   /**
    * Update an existing area for the tournament
    */
   public function updateArea(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      $data = $request->getParsedBody();
      $errors = Area::validateArray($data);

      if (count($errors) > 0)
      {
         $prev = ['areas' => [$ctx->area->id => $data]];
         $err = ['areas' => [$ctx->area->id => $errors]];
         return $this->showTournamentConfiguration($request, $response, $args, $err, $prev);
      }

      $ctx->area->updateFromArray($data);
      $this->repo->saveArea($ctx->area);

      return $this->sendToTournamentConfiguration($request, $response, $args);
   }

   /**
    * Delete an area from the tournament
    */
   public function deleteArea(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $this->repo->deleteArea($ctx->area->id);
      return $this->sendToTournamentConfiguration($request, $response, $args);
   }

   /**
    * Create a new category for the tournament
    */
   public function createCategory(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      $data = $request->getParsedBody();
      $errors = Category::validateArray($data);

      if (count($errors) > 0)
      {
         $prev = ['categories' => ['new' => $data]];
         $err = ['categories' => ['new' => $errors]];
         return $this->showTournamentConfiguration($request, $response, $args, $err, $prev);
      }

      $category = new Category(
         id: null,
         tournament_id: $ctx->tournament->id,
         name: $data['name'],
         mode: $data['mode']
      );

      if( !$this->repo->saveCategory($category) )
      {
         $prev = ['categories' => ['new' => $data]];
         $err = ['categories' => ['new' => [['msg' => 'Failed to create category']]]];
         return $this->showTournamentConfiguration($request, $response, $args, $err, $prev);
      }

      return $this->sendToTournamentConfiguration($request, $response, $args);
   }

   /**
    * Update an existing category for the tournament from the list of categories
    */
   public function updateCategory(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      $data = $request->getParsedBody();
      $errors = Category::validateArray($data);

      if (count($errors) > 0)
      {
         $prev = ['categories' => [$ctx->category->id => $data]];
         $err = ['categories' => [$ctx->category->id => $errors]];
         return $this->showTournamentConfiguration($request, $response, $args, $err, $prev);
      }

      $ctx->category->updateFromArray($data);
      if (!$this->repo->saveCategory($ctx->category))
      {
         return $this->showTournamentConfiguration($request, $response, $args, ['category' => ['update' => 'Failed to update category']], $data);
      }

      return $this->sendToTournamentConfiguration($request, $response, $args);
   }

   /**
    * Render the form to configure a category in detail
    */
   public function showCategoryConfiguration(Request $request, Response $response, array $args, array $errors = [], array $prev = []): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      return $this->view->render($response, 'tournament/settings/category.twig', [
         'category'  => $ctx->category,
         'errors'    => $errors,
         'prev'      => $prev,
         'category_modes' => CategoryMode::cases(),
      ]);
   }

   /**
    * Update the detailled category configuration and regenerate the structure
    */
   public function updateCategoryConfiguration(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      /* parse input */
      $data = $request->getParsedBody();
      $data['name'] = $ctx->category->name; // name is not part of the form, just take it over from the DB
      $errors = Category::validateArray($data);

      /* return form if there are errors */
      if (count($errors) > 0)
      {
         return $this->showCategoryConfiguration($request, $response, $args, $errors, $data);
      }

      /* update the data base */
      $ctx->category->updateFromArray($data);
      $this->repo->saveCategory($ctx->category);

      /* reshuffle the participants into the new configuration */
      $this->structureLoadService->populate($ctx->category);

      /* forward to category page */
      return $this->showCategoryConfiguration($request, $response, $args);
   }

   /**
    * Delete a category from the tournament
    */
   public function deleteCategory(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $this->repo->deleteCategory($ctx->category->id);
      return $this->sendToTournamentConfiguration($request, $response, $args);
   }
}
