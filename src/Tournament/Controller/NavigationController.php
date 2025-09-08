<?php

namespace Tournament\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

use Tournament\Repository\TournamentRepository;
use Tournament\Repository\CategoryRepository;

class NavigationController
{
   public function __construct(
      private Twig $view,
      private TournamentRepository $repo,
      private CategoryRepository $categoryRepo,
   ) {
   }

   /**
    * Root page - show a list of all tournaments
    */
   public function index(Request $request, Response $response, array $args): Response
   {
      return $this->view->render($response, 'home.twig', [
         'tournaments' => $this->repo->getAllTournaments()
      ]);
   }

   /**
    * Show a specific tournament
    */
   public function showTournament(Request $request, Response $response, array $args): Response
   {
      $tournament = $this->repo->getTournamentById($args['id']);
      if (!$tournament)
      {
         $response->getBody()->write('Tournament not found');
         return $response->withStatus(404);
      }

      $categories = $this->categoryRepo->getCategoriesByTournamentId($args['id']);

      return $this->view->render($response, 'tournament/home.twig', [
         'tournament' => $tournament,
         'categories' => $categories,
      ]);
   }

   /**
    * Show a tournament control panel
    */
    public function showControlPanel(Request $request, Response $response, array $args): Response
    {
       $tournament = $this->repo->getTournamentById($args['id']);
       if (!$tournament)
       {
          $response->getBody()->write('Tournament not found');
          return $response->withStatus(404);
       }

       $categories = $this->categoryRepo->getCategoriesByTournamentId($args['id']);

       return $this->view->render($response, 'tournament/controlpanel.twig', [
          'tournament' => $tournament,
          'categories' => $categories,
       ]);
    }
}