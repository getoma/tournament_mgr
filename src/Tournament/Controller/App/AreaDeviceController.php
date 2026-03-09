<?php

namespace Tournament\Controller\App;

use Base\Service\PrgService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

use Tournament\Repository\AreaDeviceAccountRepository;
use Tournament\Repository\TournamentRepository;
use Tournament\Service\AreaDeviceService;
use Tournament\Service\RouteArgsContext;

class AreaDeviceController
{
   const LOGIN_CODE_LEN = 8;

   public function __construct(
      private AreaDeviceService $service,
      private AreaDeviceAccountRepository $repo,
      private TournamentRepository $tournament_repo,
      private PrgService $prgService,
      private Twig $twig,
   )
   {
   }

   public function showAreaDeviceStatus(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      /* get the latest sessions and login codes */
      $all_codes = $this->repo->getCurrentLoginCodesByTournamentId($ctx->tournament->id)->column_map('area_id');
      $all_sessions = $this->repo->getCurrentSessionsPerTournamentId($ctx->tournament->id)->column_map('area_id');

      /* only forward the most recent one for each area */
      $sessions = $all_sessions->filter(fn($s) => !$all_codes->keyExists($s->area_id) || ($s->created_at > $all_codes[$s->area_id]->created_at));
      $codes = $all_codes->filter(fn($c) => !$sessions->keyExists($c->area_id));

      return $this->twig->render($response, 'tournament/settings/area_devices.twig', [
         'areas'       => $this->tournament_repo->getAreasByTournamentId($ctx->tournament->id),
         'login_codes' => $codes,
         'sessions'    => $sessions,
      ]);
   }

   public function createLoginCode(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $this->service->createLoginCode($ctx->area, self::LOGIN_CODE_LEN);
      return $this->prgService->redirectBack($request, $response);
   }

   public function invalidateLoginCode(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $this->service->invalidateLoginCode($ctx->area);
      return $this->prgService->redirectBack($request, $response);
   }

   public function disableDevice(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $this->service->invalidateSession($ctx->area);
      return $this->prgService->redirectBack($request, $response);
   }
}
