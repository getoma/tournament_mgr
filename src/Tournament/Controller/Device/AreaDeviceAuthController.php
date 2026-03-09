<?php

namespace Tournament\Controller\Device;

use Base\Service\PrgService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

use Tournament\Service\AreaDeviceService;

class AreaDeviceAuthController
{
   public function __construct(
      private AreaDeviceService $service,
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
         return $this->prgService->redirect($request, $response, 'device.categories.index');
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
      return $this->prgService->redirect($request, $response, 'auth.login');
   }
}