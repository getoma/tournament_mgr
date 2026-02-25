<?php

namespace Tournament\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

use Tournament\Repository\TournamentRepository;
use Tournament\Repository\UserRepository;

use Tournament\Model\Area\Area;
use Tournament\Model\Category\Category;
use Tournament\Model\Category\CategoryMode;
use Tournament\Model\Tournament\Tournament;
use Tournament\Model\Tournament\TournamentStatus;

use Tournament\Service\RouteArgsContext;
use Tournament\Service\TournamentStructureService;

use Base\Service\PrgService;

use Base\Service\DataValidationService;
use Respect\Validation\Validator as v;

class TournamentSettingsController
{
   public function __construct(
      private Twig $view,
      private TournamentRepository $repo,
      private UserRepository $user_repo,
      private TournamentStructureService $structureLoadService,
      private PrgService $prgService,
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
      $tournament->owners[] = $request->getAttribute('auth_context')->user; // add current user as owner
      $this->repo->saveTournament($tournament);

      return $this->prgService->redirect($request, $response, 'tournaments.edit', ['tournamentId' => $tournament->id], 'tournament_created');
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

      /* get list of owners that can be added */
      $available_owners = $this->user_repo->getAllUsers()
                           ->filter(fn($u) => !$ctx->tournament->owners->contains($u))
                           ->column('display_name', 'id');

      return $this->view->render($response, 'tournament/settings/tournament.twig', [
         'areas' => $areas,
         'categories' => $categories,
         'category_modes' => CategoryMode::cases(),
         'available_owners' => $available_owners,
         'errors' => $errors,
         'prev' => $prev,
      ]);
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

      return $this->prgService->redirect($request, $response, 'tournaments.edit', $args, 'tournament_updated');
   }

   /**
    * add an owner
    */
   public function addOwner(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      /* get list of user ids */
      $available_owners = $this->user_repo->getAllUsers();

      /* generate the validator */
      $rules = ['user_id' => v::intVal()->in($available_owners->column('id'))->setTemplate('Unbekannter Nutzer') ];

      /* retrieve and validate the input */
      $data = $request->getParsedBody();
      $errors = DataValidationService::validate($data, $rules);

      if( $errors )
      {
         return $this->showTournamentConfiguration($request, $response, $args, $errors, $data);
      }

      $ctx->tournament->owners[] = $available_owners[$data['user_id']];
      $this->repo->saveTournament($ctx->tournament);

      return $this->prgService->redirect($request, $response, 'tournaments.edit', $args, 'owner_updated');
   }

   /**
    * remove an owner
    */
   public function removeOwner(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      /* generate the validator */
      $rules = ['user_id' => v::intVal()->in($ctx->tournament->owners->column('id'))->setTemplate('Unbekannter Nutzer')];

      /* retrieve and validate the input */
      $data = $request->getParsedBody();
      $errors = DataValidationService::validate($data, $rules);

      if ($errors)
      {
         return $this->showTournamentConfiguration($request, $response, $args, $errors, $data);
      }

      unset($ctx->tournament->owners[$data['user_id']]);
      $this->repo->saveTournament($ctx->tournament);

      return $this->prgService->redirect($request, $response, 'tournaments.edit', $args, 'owner_updated');
   }

   public function changeTournamentStatus(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      $data = $request->getParsedBody();
      $new_state = TournamentStatus::tryFrom($data['status']);

      if( $new_state && $ctx->tournament->getStateHandler()->canTransition($new_state) )
      {
         $ctx->tournament->status = $new_state;
         $this->repo->saveTournament($ctx->tournament);
      }
      else
      {
         $err = ['status' => 'not allowed'];
         return $this->showTournamentConfiguration($request, $response, $args, $err);
      }

      return $this->prgService->redirect($request, $response, 'tournaments.edit', $args, 'tournament_status');
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

      return $this->prgService->redirect($request, $response, 'tournaments.edit', $args, 'area_created');
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

      return $this->prgService->redirect($request, $response, 'tournaments.edit', $args, 'area_updated');
   }

   /**
    * Delete an area from the tournament
    */
   public function deleteArea(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $this->repo->deleteArea($ctx->area->id);
      return $this->prgService->redirect($request, $response, 'tournaments.edit', $args, 'area_deleted');
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

      return $this->prgService->redirect($request, $response, 'tournaments.edit', $args, 'category_created');
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
    * Update the detailed category configuration and regenerate the structure
    */
   public function updateCategoryConfiguration(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      /* parse input */
      $data = $request->getParsedBody();
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
      return $this->prgService->redirect($request, $response, 'tournaments.categories.edit', $args);
   }

   /**
    * Delete a category from the tournament
    */
   public function deleteCategory(Request $request, Response $response, array $args): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $this->repo->deleteCategory($ctx->category->id);
      return $this->prgService->redirect($request, $response, 'tournaments.edit', $args, 'category_deleted');
   }
}
