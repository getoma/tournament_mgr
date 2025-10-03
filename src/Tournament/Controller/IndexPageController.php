<?php

namespace Tournament\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

use Tournament\Repository\TournamentRepository;
use Tournament\Model\Tournament\TournamentStatus;
use Base\Service\DbUpdateService;

class IndexPageController
{
   public function __construct(
      private Twig $view,
      private TournamentRepository $repo,
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

      /* provide a second list where tournaments are separated by their state */
      $tournaments_by_state = [];
      foreach ($tournament_list as $tournament)
      {
         $tournaments_by_state[$tournament->status->value] ??= [];
         $tournaments_by_state[$tournament->status->value][] = $tournament;
      }
      /* sort the list in logical order of the states */
      $order = array_flip( array_map( fn($c) => $c->value, TournamentStatus::cases() ) );
      uksort($tournaments_by_state, fn($a,$b) => ($order[$a] <=> $order[$b]) );

      return $this->view->render($response, 'home.twig', [
         'all_tournaments'      => $tournament_list,
         'tournaments_by_state' => $tournaments_by_state
      ]);
   }


}