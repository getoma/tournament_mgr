<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Slim\Routing\RouteContext;

use App\Model\Data\Category;

use App\Repository\TournamentRepository;
use App\Repository\CategoryRepository;
use App\Repository\ParticipantRepository;
use App\Repository\AreaRepository;

use App\Model\TournamentStructure\TournamentStructure;
use App\Service\Validator;
use Respect\Validation\Validator as v;

class CategoryController
{
   public function __construct(
      private Twig $view,
      private CategoryRepository $repo,
      private TournamentRepository $tournamentRepo,
      private ParticipantRepository $participantRepo,
      private AreaRepository $areaRepo
   )
   {
   }

   /**
    * fetch tournamen/category from $args
    * @throws \Exception if tournament or category not found
    */
   private function readRoute(array $args): ?array
   {
      $tournament = $this->tournamentRepo->getTournamentById($args['id']);
      if (!$tournament) throw new \Exception('Tournament not found');
      $category = $this->repo->getCategoryById($args['categoryId']);
      if (!$category) throw new \Exception('Category not found');

      return [$tournament, $category];
   }

   /**
    * Show a specific category
    */
   public function showCategory(Request $request, Response $response, array $args): Response
   {
      try
      {
         [$tournament, $category] = $this->readRoute($args);
      }
      catch (\Exception $e)
      {
         return $response->withStatus(404)->write($e->getMessage());
      }

      // Load the tournament structure for this category
      $participants = $this->participantRepo->getParticipantsWithSlotByCategoryId($category->id);
      $areas = $this->areaRepo->getAreasByTournamentId($args['id']);
      $structure = new TournamentStructure($category, $areas, $participants);

      /* filter pool/ko display if we have a very large structure */
      if( $structure->ko )
      {
         $ko = $structure->ko->getRounds(-($structure->finale_rounds_cnt??0));
      }

      return $this->view->render($response, 'category/home.twig', [
         'tournament' => $tournament,
         'category'   => $category,
         'pools'      => $structure->pools,
         'ko'         => $ko,
         'chunks'     => $structure->chunks,
         'unmapped_participants' => $participants[null] ?? [],
      ]);
   }

   /**
    * Show the KO-tree assigned to a specific area and chunk for a specific category
    */
   public function showKoArea(Request $request, Response $response, array $args): Response
   {
      try
      {
         [$tournament, $category] = $this->readRoute($args);
      }
      catch (\Exception $e)
      {
         return $response->withStatus(404)->write($e->getMessage());
      }

      // Load the tournament structure for this category and fetch the specific chunk
      $participants = $this->participantRepo->getParticipantsWithSlotByCategoryId($category->id);
      $areas = $this->areaRepo->getAreasByTournamentId($args['id']);
      $structure = new TournamentStructure($category, $areas, $participants);
      $chunk = $structure->chunks[$args['chunk']];

      if (!isset($chunk))
      {
         return $response->withStatus(404)->write('area not found');
      }

      return $this->view->render($response, 'category/area_ko.twig', [
         'tournament' => $tournament,
         'category'   => $category,
         'ko'         => $chunk->root->getRounds(),
         'chunk'      => $chunk,
      ]);
   }

   /**
    * Show the KO-tree assigned to a specific area and chunk for a specific category
    */
   public function showPoolArea(Request $request, Response $response, array $args): Response
   {
      try
      {
         [$tournament, $category] = $this->readRoute($args);
      }
      catch (\Exception $e)
      {
         return $response->withStatus(404)->write($e->getMessage());
      }

      $areas = $this->areaRepo->getAreasByTournamentId($args['id']);
      $area = $areas[$args['areaid']] ?? null;

      if (!isset($area))
      {
         return $response->withStatus(404)->write('area not found');
      }

      // Load the tournament structure for this category
      $participants = $this->participantRepo->getParticipantsWithSlotByCategoryId($category->id);

      $structure = new TournamentStructure($category, $areas, $participants);
      $area_pools = $structure->getPoolsByArea($area);

      return $this->view->render($response, 'category/area_pool.twig', [
         'tournament' => $tournament,
         'category'   => $category,
         'pools'      => $area_pools,
         'area'       => $area,
      ]);
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
      try
      {
         [$tournament, $category] = $this->readRoute($args);
      }
      catch (\Exception $e)
      {
         return $response->withStatus(404)->write($e->getMessage());
      }

      $data = ($request->getMethod() === 'POST') ? $request->getParsedBody() : $request->getQueryParams();

      /* verify return_to parameter */
      if (!v::stringType()->regex('@/tournament/\d+/[a-zA-Z0-9_-]+$@')->isValid($data['return_to']))
      {
         $return_to = RouteContext::fromRequest($request)->getRouteParser()
            ->relativeUrlFor('show_category', ['id' => $args['id'], 'categoryId' => $args['categoryId']]);
      }
      else
      {
         $return_to = $data['return_to'] ?? '';
      }

      return $this->view->render($response, 'category/configure.twig', [
         'tournament' => $tournament,
         'category' => $category,
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

      /* preprocess config - remove unsupported values, remove null values */
      $cfg_filtered = array_intersect_key($data, Category::getValidationRules('details_only'));
      $config = array_filter($cfg_filtered, fn($v) => !empty($v));

      /* store the new category configuration */
      $this->repo->updateCategoryDetails([
         'id' => $args['categoryId'],
         'mode' => $data['mode'],
         'config' => $config
      ]);

      /* reshuffle the participants into the new configuration */
      $category = new Category(id: $args['categoryId'], tournament_id: $args['id'], name: '', mode: $data['mode'], config: $config);
      $areas = $this->areaRepo->getAreasByTournamentId($args['id']);
      $participants = $this->participantRepo->getParticipantsByCategoryId($category->id);

      $structure = new TournamentStructure($category, $areas);
      $slot_assignment = $structure->shuffleParticipants($participants);

      $this->participantRepo->updateAllParticipantSlots($category->id, $slot_assignment);

      /* forward to category page */
      return $response->withHeader(
         'Location',
         RouteContext::fromRequest($request)->getRouteParser()->urlFor('show_category', $args)
      )->withStatus(302);
   }
}
