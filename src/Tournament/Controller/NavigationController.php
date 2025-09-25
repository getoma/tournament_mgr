<?php

namespace Tournament\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

use Tournament\Repository\TournamentRepository;
use Tournament\Repository\CategoryRepository;

use Base\Service\DbUpdateService;

class NavigationController
{
   public function __construct(
      private Twig $view,
      private TournamentRepository $repo,
      private CategoryRepository $categoryRepo,
      private DbUpdateService $dbUpdateService
   ) {
   }

   /**
    * Root page - show a list of all tournaments
    */
   public function index(Request $request, Response $response, array $args): Response
   {
      try
      {
         $tournament_list = $this->repo->getAllTournaments();
      }
      catch( \PDOException $e )
      {
         // likely cause: database is not yet initialized.
         // call the db update service to initialize the database
         // and write the update output to the log
         // any exception thrown here just let it fall through to the normal error handler
         $out = $this->dbUpdateService->update();
         error_log("Database initialization output:\n" . $out);
         $tournament_list = [];
      }

      return $this->view->render($response, 'home.twig', [
         'tournaments' => $tournament_list
      ]);
   }

   /**
    * Show a specific tournament
    */
   public function showTournament(Request $request, Response $response, array $args): Response
   {
      $tournament = $this->repo->getTournamentById($args['tournamentId']);
      if (!$tournament)
      {
         $response->getBody()->write('Tournament not found');
         return $response->withStatus(404);
      }

      $categories = $this->categoryRepo->getCategoriesByTournamentId($args['tournamentId']);

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
       $tournament = $this->repo->getTournamentById($args['tournamentId']);
       if (!$tournament)
       {
          $response->getBody()->write('Tournament not found');
          return $response->withStatus(404);
       }

       $categories = $this->categoryRepo->getCategoriesByTournamentId($args['tournamentId']);

       return $this->view->render($response, 'tournament/controlpanel.twig', [
          'tournament' => $tournament,
          'categories' => $categories,
       ]);
    }
}