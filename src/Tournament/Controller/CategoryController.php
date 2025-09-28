<?php

namespace Tournament\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Slim\Routing\RouteContext;

use Tournament\Model\Data\Category;

use Tournament\Repository\TournamentRepository;
use Tournament\Repository\CategoryRepository;
use Tournament\Repository\ParticipantRepository;
use Tournament\Repository\AreaRepository;
use Tournament\Repository\MatchDataRepository;

use Tournament\Model\TournamentStructure\TournamentStructure;
use Tournament\Model\TournamentStructure\KoNode;
use Base\Service\Validator;
use Respect\Validation\Validator as v;

class CategoryController
{
   public function __construct(
      private Twig $view,
      private CategoryRepository $repo,
      private TournamentRepository $tournamentRepo,
      private ParticipantRepository $participantRepo,
      private AreaRepository $areaRepo,
      private MatchDataRepository $matchDataRepo
   )
   {
   }

   /**
    * fetch tournamen/category from $args
    * @throws \Exception if tournament or category not found
    */
   private function readRoute(array $args): ?array
   {
      $tournament = $this->tournamentRepo->getTournamentById($args['tournamentId']);
      if (!$tournament) throw new \Exception('Tournament not found');
      $category = $this->repo->getCategoryById($args['categoryId']);
      if (!$category) throw new \Exception('Category not found');

      return [$tournament, $category];
   }

   /**
    * load a tournament structure from the repository
    */
   private function loadStructure(Category $category): TournamentStructure
   {
      $areas = $this->areaRepo->getAreasByTournamentId($category->tournament_id);
      $participants = $this->participantRepo->getParticipantsWithSlotByCategoryId($category->id);
      $matchRecords = $this->matchDataRepo->getMatchRecordsByCategoryId($category->id);

      return new TournamentStructure($category, $areas, $participants, $matchRecords);
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
         $response->getBody()->write($e->getMessage());
         return $response->withStatus(404);
      }

      // Load the tournament structure for this category
      $structure = $this->loadStructure($category);

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
         'unmapped_participants' => $structure->unmapped_participants,
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
         $response->getBody()->write($e->getMessage());
         return $response->withStatus(404);
      }

      // Load the tournament structure for this category and fetch the specific chunk
      $structure = $this->loadStructure($category);
      $chunk = $structure->chunks[$args['chunk']];

      if (!isset($chunk))
      {
         $response->getBody()->write('area not found');
         return $response->withStatus(404);
      }

      return $this->view->render($response, 'category/area_ko.twig', [
         'tournament' => $tournament,
         'category'   => $category,
         'ko'         => $chunk->root->getRounds(),
         'chunk'      => $chunk,
      ]);
   }

   /**
    * Show the pool view assigned to a specific area and chunk for a specific category
    */
   public function showPoolArea(Request $request, Response $response, array $args): Response
   {
      try
      {
         [$tournament, $category] = $this->readRoute($args);
      }
      catch (\Exception $e)
      {
         $response->getBody()->write($e->getMessage());
         return $response->withStatus(404);
      }

      $area = $this->areaRepo->getAreaById($args['areaid']);
      if (!$area)
      {
         $response->getBody()->write('area not found');
         return $response->withStatus(404);
      }

      // Load the tournament structure for this category
      $structure = $this->loadStructure($category);
      $area_pools = $structure->getPoolsByArea($area->id);

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
         $response->getBody()->write($e->getMessage());
         return $response->withStatus(404);
      }

      $data = ($request->getMethod() === 'POST') ? $request->getParsedBody() : $request->getQueryParams();

      /* verify return_to parameter */
      if (!v::stringType()->regex('@/tournament/\d+/[a-zA-Z0-9_-]+$@')->isValid($data['return_to']))
      {
         $return_to = RouteContext::fromRequest($request)->getRouteParser()
            ->urlFor('show_category', ['tournamentId' => $args['tournamentId'], 'categoryId' => $args['categoryId']]);
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
      $category = new Category(id: $args['categoryId'], tournament_id: $args['tournamentId'], name: '', mode: $data['mode'], config: $config);
      $areas = $this->areaRepo->getAreasByTournamentId($args['tournamentId']);
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
