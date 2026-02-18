<?php

namespace Base\Service;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;
use Slim\Psr7\Response;

class RedirectHandler
{
   public function __call($name, $arguments)
   {
      /** @var ServerRequestInterface $request */
      /** @var ResponseInterface $response */
      [$request, $response] = $arguments;
      $routeContext = RouteContext::fromRequest($request);
      $routeParser = $routeContext->getRouteParser();
      $routeArgs = $routeContext->getRoute()->getArguments();

      $response = new Response();
      return $response
         ->withHeader('Location', $routeParser->urlFor($name, $routeArgs))
         ->withStatus(302);
   }
}