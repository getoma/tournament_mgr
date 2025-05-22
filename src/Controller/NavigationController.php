<?php

namespace App\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

use App\Repository\TournamentRepository;
use App\Repository\CategoryRepository;

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
      return $this->view->render($response, 'home.twigg', [
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
         return $response->withStatus(404)->write('Tournament not found');
      }

      $categories = $this->categoryRepo->getCategoriesByTournamentId($args['id']);

      return $this->view->render($response, 'tournament/home.twigg', [
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
          return $response->withStatus(404)->write('Tournament not found');
       }
 
       $categories = $this->categoryRepo->getCategoriesByTournamentId($args['id']);
 
       return $this->view->render($response, 'tournament/controlpanel.twigg', [
          'tournament' => $tournament,
          'categories' => $categories,
       ]);
    }
}