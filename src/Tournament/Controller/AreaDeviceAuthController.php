<?php

namespace Tournament\Controller;

use Base\Service\PrgService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use Tournament\Repository\AreaDeviceAccountRepository;
use Tournament\Repository\TournamentRepository;
use Tournament\Service\AreaDeviceService;
use Tournament\Service\RouteArgsContext;

class AreaDeviceAuthController
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

   public function showLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
   {
      return $this->twig->render($response, 'auth/code_login.twig');
   }

   public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
   {
      $data = (array)$request->getParsedBody();

      /* login code may be entered with hyphens, remove those. Also trim and enforce to capital letters */
      $login_code = preg_replace('/-/', '', strtoupper(trim($data['login_code'])));

      if( $this->service->login($login_code) )
      {
         return $this->prgService->redirect($request, $response, 'device.dashboard.show');
      }
      else
      {
         return $this->twig->render($response, 'auth/code_login.twig', [
            'error' => 'invalid',
            'prev' => $data,
         ]);
      }
   }

   public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
   {
      $this->service->logout();
      return $this->prgService->redirect($request, $response, 'tournaments.index');
   }

   public function showAreaDeviceStatus(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');

      /* get the latest sessions and login codes */
      $all_codes = $this->repo->getCurrentLoginCodesByTournamentId($ctx->tournament->id)->column_map('area_id');
      $all_sessions = $this->repo->getCurrentSessionsPerTournamentId($ctx->tournament->id)->column_map('area_id');

      /* only forward the most recent one for each area
       * There is always an (expired) login code existing if there is a session, so we don't need to check for existance when filtering sessions
       * For filtering codes, we then can just keep any code we didn't keep a session for in the previous step
       */
      $sessions = $all_sessions->filter( fn($s) => $s->created_at > $all_codes[$s->area_id]->created_at);
      $codes = $all_codes->filter( fn($c) => !$sessions->keyExists($c->area_id));

      return $this->twig->render($response, 'tournament/device_accounts/show.twig', [
         'areas'       => $this->tournament_repo->getAreasByTournamentId($ctx->tournament->id),
         'login_codes' => $codes,
         'sessions'    => $sessions,
      ]);
   }

   public function createLoginCode(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $this->service->createLoginCode($ctx->area, self::LOGIN_CODE_LEN);
      return $this->prgService->redirect($request, $response, 'tournaments.areas.devices.index', $args);
   }

   public function invalidateLoginCode(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $this->service->invalidateLoginCode($ctx->area);
      return $this->prgService->redirect($request, $response, 'tournaments.areas.devices.index', $args);
   }

   public function disableDevice(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
   {
      /** @var RouteArgsContext $ctx */
      $ctx = $request->getAttribute('route_context');
      $this->service->invalidateSession($ctx->area);
      return $this->prgService->redirect($request, $response, 'tournaments.areas.devices.index', $args);
   }
}