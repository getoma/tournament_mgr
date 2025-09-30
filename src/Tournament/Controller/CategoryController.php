<?php

namespace Tournament\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use Slim\Routing\RouteContext;

use Tournament\Model\Data\Category;

use Tournament\Repository\CategoryRepository;
use Tournament\Repository\ParticipantRepository;
use Tournament\Service\TournamentStructureService;

use Base\Service\Validator;
use Respect\Validation\Validator as v;
use Tournament\Exception\EntityNotFoundException;
use Tournament\Model\Data\CategoryConfiguration;

class CategoryController
{
   public function __construct(
      private Twig $view,
      private CategoryRepository $repo,
      private ParticipantRepository $participantRepo,
      private TournamentStructureService $structureLoadService,
   )
   {
   }

   /**
    * Show a specific category
    */
   public function showCategory(Request $request, Response $response, array $args): Response
   {
      // Load the tournament structure for this category
      $structure = $this->structureLoadService->load($request->getAttribute('category'));

      /* filter pool/ko display if we have a very large structure */
      if( $structure->ko )
      {
         $ko = $structure->ko->getRounds(-($structure->finale_rounds_cnt??0));
      }

      return $this->view->render($response, 'category/home.twig', [
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
      // Load the tournament structure for this category and fetch the specific chunk
      $structure = $this->structureLoadService->load($request->getAttribute('category'));
      $chunk = $structure->chunks[$args['chunk']] ?? throw new EntityNotFoundException('Chunk not found');

      return $this->view->render($response, 'category/area_ko.twig', [
         'ko'         => $chunk->root->getRounds(),
         'chunk'      => $chunk,
      ]);
   }

   /**
    * Show the pool view assigned to a specific area and chunk for a specific category
    */
   public function showPoolArea(Request $request, Response $response, array $args): Response
   {
      // Load the tournament structure for this category
      $structure = $this->structureLoadService->load($request->getAttribute('category'));
      $area_pools = $structure->getPoolsByArea($request->getAttribute('area')->id);
      return $this->view->render($response, 'category/area_pool.twig', [
         'pools' => $area_pools
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
      $tournament = $request->getAttribute('tournament');
      $category = $request->getAttribute('category');
      $data = ($request->getMethod() === 'POST') ? $request->getParsedBody() : $request->getQueryParams();

      /* verify return_to parameter */
      if (!v::stringType()->regex('@/tournament/\d+/[a-zA-Z0-9_-]+$@')->isValid($data['return_to']))
      {
         $return_to = RouteContext::fromRequest($request)->getRouteParser()
            ->urlFor('show_category', ['tournamentId' => $tournament->id, 'categoryId' => $category->id]);
      }
      else
      {
         $return_to = $data['return_to'] ?? '';
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
      $structure = $this->structureLoadService->initialize($category);
      $participants = $this->participantRepo->getParticipantsByCategoryId($category->id);
      $slot_assignment = $structure->shuffleParticipants($participants);
      $this->participantRepo->updateAllParticipantSlots($category->id, $slot_assignment);

      /* forward to category page */
      return $response->withHeader(
         'Location',
         RouteContext::fromRequest($request)->getRouteParser()->urlFor('show_category', $args)
      )->withStatus(302);
   }
}
