<?php

namespace Base\Middleware;

use Base\Service\PrgService;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

use Slim\Views\Twig;

/**
 * A middleware to retrieve any previously stored PRG status message and inject
 * it into the request itself, as well as twig
 */
class PrgMiddleware implements MiddlewareInterface
{
   public function __construct(
      private PrgService $service,
      private Twig $twig
   )
   {
   }

   public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
   {
      /* retrieve any possible prg status message via the dedicated service */
      $msg = $this->service->getStatusMessage();
      if( $msg )
      {
         /* inject status message into both the request and twig */
         $request = $request->withAttribute(PrgService::KEY, $msg);
         $this->twig?->getEnvironment()->addGlobal(PrgService::KEY, $msg);
      }
      /* process the request */
      $result = $handler->handle($request);

      /* clean up the stored message
       * (done afterwards so that Controller can optionally retrieve the message from the service again)
       */
      if( $msg )
      {
         $this->service->clean();
      }

      /* done */
      return $result;
   }

   /**
    * factory method to create the middleware with dependencies from the container
    */
   static public function create(
      \Slim\App $app,
      ?PrgService $service = null,
      ?Twig $twig = null
   ): self
   {
      $container = $app->getContainer();
      $service ??= $container->get(PrgService::class);
      if (!isset($twig) && $container->has(Twig::class)) $twig = $container->get(Twig::class);
      return new self($service, $twig);
   }

}