<?php

use Slim\Routing\RouteCollectorProxy;
use Tournament\Controller\ParticipantsController;
use Tournament\Controller\TournamentController;
use Tournament\Controller\NavigationController;
use Tournament\Controller\CategoryController;
use Tournament\Controller\AuthController;
use Tournament\Controller\UserController;

use Tournament\Policy\TournamentAction;

use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;
use Tournament\Model\Data\Tournament;

return function (\Slim\App $app)
{
   $container = $app->getContainer();

   /**********************
    * Global MiddleWare Setup
    */

   // twig middleware to support various extensions in templates
   $app->add(TwigMiddleware::create($app, $container->get(Twig::class)));

   // Add CurrentUserMiddleware
   $app->add(new Base\Middleware\CurrentUserMiddleware($container->get(Base\Service\AuthService::class), $container->get(Slim\Views\Twig::class)));

   $app->addRoutingMiddleware();

   // Add Error Handling Middleware
   $app->addErrorMiddleware(true, false, false);

   /**********************
    * Local MiddleWare Initialization
    */
   // Add AuthMiddleware - enforce login
   $authMW = new Base\Middleware\AuthMiddleware(
      $container->get(Base\Service\AuthService::class),
      $container->get(Base\Service\SessionService::class),
      'login'
   );

   $statusGuardMW = new \Tournament\Middleware\TournamentStatusGuardMiddleware(
      $container->get(\Tournament\Repository\TournamentRepository::class),
      $container->get(\Tournament\Policy\TournamentPolicy::class)
   );

   /**********************
    * Route setup
    */

   /* navigation */
   $app->get('/', [NavigationController::class, 'index'])->setName('home');

   /* login/logout/pw reset */
   $app->get('/login',   [AuthController::class, 'showLogin'])->setName('login');
   $app->post('/login',  [AuthController::class, 'login'])->setName('login_post');
   $app->get('/logout',  [AuthController::class, 'logout'])->setName('logout');
   $app->get('/forgot',  [AuthController::class, 'showForgotPassword'])->setName('pw_forgot');
   $app->post('/forgot', [AuthController::class, 'requestPasswordReset'])->setName('pw_forgot_post');
   $app->get('/reset',   [AuthController::class, 'showResetPassword'])->setName('pw_reset');
   $app->post('/reset',  [AuthController::class, 'resetPassword'])->setName('pw_reset_post');

   /***
    * Routes that need an active login
    */
   $app->group('', function (RouteCollectorProxy $auth_grp) use ($statusGuardMW)
   {
      /* create tournament */
      $auth_grp->get('/tournament/create', [TournamentController::class, 'showFormNewTournament'])->setName('new_tournament_form');
      $auth_grp->post('/tournament/create', [TournamentController::class, 'createTournament'])->setName('create_tournament');

      /**
       * Tournament routes
       */
      $auth_grp->group('/tournament/{id:\d+}', function (RouteCollectorProxy $tgrp) use ($statusGuardMW)
      {
         /* tournament overview */
         $tgrp->get('[/]',      [NavigationController::class, 'showTournament'])->setName('show_tournament');
         $tgrp->get('/control', [NavigationController::class, 'showControlPanel'])->setName('tournament_control');

         /* tournament configuration pages */
         $tgrp->get( '/configure', [TournamentController::class, 'showTournamentConfiguration'])->setName('show_tournament_config');
         $tgrp->group('', function (RouteCollectorProxy $cgrp)
         {
            $cgrp->post('/configure', [TournamentController::class, 'updateTournament'])->setName('update_tournament_config');
            $cgrp->post('/area/create', [TournamentController::class, 'createArea'])->setName('create_area');
            $cgrp->post('/area/{areaId:\d+}/update', [TournamentController::class, 'updateArea'])->setName('update_area');
            $cgrp->post('/area/{areaId:\d+}/delete', [TournamentController::class, 'deleteArea'])->setName('delete_area');
            $cgrp->post('/category/create', [TournamentController::class, 'createCategory'])->setName('create_category');
            $cgrp->post('/category/{categoryId:\d+}/update', [TournamentController::class, 'updateCategory'])->setName('update_category');
            $cgrp->post('/category/{categoryId:\d+}/delete', [TournamentController::class, 'deleteCategory'])->setName('delete_category');
         }
         )->add( $statusGuardMW->for(TournamentAction::ManageSetup) );

         /* participants */
         $tgrp->get( '/participants', [ParticipantsController::class, 'showParticipantList'])->setName('show_participant_list');
         $tgrp->get('/participants/{participantId:\d+}', [ParticipantsController::class, 'showParticipant'])->setName('show_participant');
         $tgrp->group('', function (RouteCollectorProxy $pgrp)
         {
            $pgrp->post('/participants', [ParticipantsController::class, 'updateParticipantList'])->setName('update_participant_list');
            $pgrp->post('/participants/{participantId:\d+}', [ParticipantsController::class, 'updateParticipant'])->setName('update_participant');
            $pgrp->post('/participants/{participantId:\d+}/delete', [ParticipantsController::class, 'deleteParticipant'])->setName('delete_participant');
            $pgrp->post('/participants/import', [ParticipantsController::class, 'importParticipantList'])->setName('import_participants');
         }
         )->add( $statusGuardMW->for(TournamentAction::ManageParticipants) );

         /* category management */
         $tgrp->get( '/category/{categoryId:\d+}', [CategoryController::class, 'showCategory'])->setName('show_category');
         $tgrp->get( '/category/{categoryId:\d+}/configure', [CategoryController::class, 'showCategoryConfiguration'])->setName('show_category_cfg');
         $tgrp->get( '/category/{categoryId:\d+}/area/ko/{chunk}', [CategoryController::class, 'showKoArea'])->setName('show_ko_area');
         $tgrp->get( '/category/{categoryId:\d+}/area/pool/{areaid:\d+}', [CategoryController::class, 'showPoolArea'])->setName('show_pool_area');

         $tgrp->post('/category/{categoryId:\d+}/configure', [CategoryController::class, 'updateCategoryConfiguration'])->setName('update_category_cfg')
              ->add( $statusGuardMW->for(TournamentAction::ManageSetup) );
      });

      $auth_grp->get('/user/account', [UserController::class, 'showAccount'])->setName('user_account');
      $auth_grp->post('/user/account', [UserController::class, 'updateAccount'])->setName('user_account_post');
   })
   ->add($authMW);
};

