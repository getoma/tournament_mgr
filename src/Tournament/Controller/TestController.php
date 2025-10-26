<?php

namespace Tournament\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

use Base\Service\DbUpdateService;

/****
 * controller for pages that shall only be here for test purposes during development
 */
class TestController
{
   public function __construct(
      private Twig $view,
      private DbUpdateService $dbUpdateService
   )
   {
   }

   /**
    * Root page - show a list of all tournaments
    */
   public function showDbMigrationList(Request $request, Response $response, array $args, string $message = ''): Response
   {
      $versions = array_reverse($this->dbUpdateService->getMigrations());
      return $this->view->render($response, 'special_pages/db_selection.twig', [
         'current'  => $this->dbUpdateService->getCurrentVersion(),
         'versions' => array_combine($versions, $versions),
         'message' => $message
      ]);
   }

   public function setDbMigration(Request $request, Response $response, array $args): Response
   {
      $data = $request->getParsedBody();
      /* skip validation for this dev-only route, directly attempt to perform it */
      $message = $this->dbUpdateService->migrateTo((int)$data['version']);
      /* show the normal page again */
      return $this->showDbMigrationList($request, $response, $args, $message);
   }
}
