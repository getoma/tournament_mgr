<?php

namespace Base\Service;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

/**
 * a service to support implementation of a PRG (POST-REDIRECT-GET) pattern
 * for CRUD interfaces
 */
class PrgService
{
   public const KEY = 'prg_message';

   public function __construct(
      private SessionService $session
   )
   {
   }

   /**
    * Create a REDIRECT response, store the status message in the session for the GET request
    */
   public function redirect(
      ServerRequestInterface $request,
      ResponseInterface $response,
      string $route,
      array $args = [],
      mixed $prgMessage = true
   ): ResponseInterface
   {
      $this->session->set(static::KEY, $prgMessage);
      return $response->withHeader('Location', RouteContext::fromRequest($request)->getRouteParser()
         ->urlFor($route, $args))->withStatus(303);
   }

   /**
    * Create a REDIRECT response back to the page this request came from
    * store the status message in the session for the GET request
    */
   public function redirectBack(
      ServerRequestInterface $request,
      ResponseInterface $response,
      mixed $prgMessage = true
   ): ResponseInterface
   {
      $this->session->set(static::KEY, $prgMessage);
      $params = $request->getServerParams();
      return $response->withHeader('Location', $params['HTTP_REFERER'])->withStatus(303);
   }

   /**
    * Retrieve any stored status message from PRG handling
    */
   public function getStatusMessage(): mixed
   {
      return $this->session->get(static::KEY);
   }

   /**
    * Clean up
    */
   public function clean(): void
   {
      $this->session->remove(static::KEY);
   }

}