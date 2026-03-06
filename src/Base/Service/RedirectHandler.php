<?php

namespace Base\Service;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Routing\RouteContext;

final class RedirectHandler
{
   public static function to($route): callable
   {
      return function(ServerRequestInterface $request, ResponseInterface $response, array $routeArgs) use ($route): ResponseInterface
      {
         $routeParser = RouteContext::fromRequest($request)->getRouteParser();
         return $response
            ->withHeader('Location', $routeParser->urlFor($route, $routeArgs))
            ->withStatus(302);
      };
   }
}