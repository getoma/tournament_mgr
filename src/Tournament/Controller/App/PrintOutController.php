<?php declare(strict_types=1);

namespace Tournament\Controller\App;


use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

use Tournament\Service\PrintOutService;

use Base\Service\DataValidationService;

use Respect\Validation\Validator as v;
use Tournament\Service\RouteArgsContext;

class PrintOutController
{
   public function __construct(
      private Twig $view,
      private PrintOutService $service,
   )
   {
   }

   /**
    * show the printout selection menu
    */
   public function showMenu(Request $request, Response $response): Response
   {
      return $this->view->render($response, 'printouts/index.twig');
   }

   /**
    * create print out for name sheets
    */
   public function showNamesheets(Request $request, Response $response): Response
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $query = $request->getQueryParams();
      $order_options = ['match' => 'Nach Kämpfen', 'single' => 'Einzeln'];
      $query_rules = [
         'order'    => v::optional(v::in(array_keys($order_options))),
         'fontSize' => v::optional(v::intVal()->between(10, 128)),
      ];
      $errors = DataValidationService::validate($query, $query_rules);
      /* in case of errors, just ignore input and revert to default */
      $options = [
         'order'    => ($errors || !$query['order'])? 'match' : $query['order'],
         'fontSize' => ($errors || !$query['fontSize'])? null : $query['fontSize']
      ];
      $pages = $this->service->getNameSheetsData($ctx->category, $options);
      return $this->view->render($response, 'printouts/namesheets.twig', [
         'pages'   => $pages,
         'options' => $options,
         'order_options' => $order_options,
      ]);

   }

}
