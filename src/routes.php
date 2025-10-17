<?php

use Tournament\Controller\ParticipantsController;
use Tournament\Controller\TournamentController;
use Tournament\Controller\IndexPageController;
use Tournament\Controller\CategoryController;
use Tournament\Controller\AuthController;
use Tournament\Controller\UserController;
use Tournament\Controller\TestController;

use Tournament\Policy\TournamentAction;

use Slim\Routing\RouteCollectorProxy;
use Tournament\Controller\MatchRecordController;
use Tournament\Exception\EntityNotFoundException;
use Tournament\Middleware\EntityNotFoundHandler;

return function (\Slim\App $app)
{
   $container = $app->getContainer();

   /**********************
    * Global MiddleWare Injection
    */

   // Add CurrentUserMiddleware
   $app->add(Base\Middleware\CurrentUserMiddleware::create($app));

   // Add TournamentPolicyMiddleware
   $app->add(\Tournament\Middleware\TournamentPolicyMiddleware::create($app));

   // Add RouteArgsResolverMiddleware
   $app->add(\Tournament\Middleware\RouteArgsResolverMiddleware::create($app));

   // Add Routing Middleware
   $app->addRoutingMiddleware();

   // twig middleware to support various extensions in templates
   $app->add(Slim\Views\TwigMiddleware::create($app, $container->get(Slim\Views\Twig::class)));

   // Add Error Handling Middleware
   $errMW = $app->addErrorMiddleware(config::$debug ?? false, true, false);
   // Add custom handler for EntityNotFoundException
   $errMW->setErrorHandler(EntityNotFoundException::class, EntityNotFoundHandler::create($app));

   /**********************
    * Local MiddleWare Initialization
    */

   // Add AuthMiddleware - enforce login
   $authMW = new Base\Middleware\AuthMiddleware(
      $container->get(Base\Service\AuthService::class),
      $container->get(Base\Service\SessionService::class),
      'login'
   );

   // Add TournamentStatusGuardMiddleware - enforce tournament status based permissions
   $statusGuardMW = \Tournament\Middleware\TournamentStatusGuardMiddleware::create($app);

   /**********************
    * Route setup
    */

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
      /* navigation */
      $auth_grp->get('/', [IndexPageController::class, 'index'])->setName('home');

      /* create tournament */
      $auth_grp->get('/tournament/create', [TournamentController::class, 'showFormNewTournament'])->setName('new_tournament_form');
      $auth_grp->post('/tournament/create', [TournamentController::class, 'createTournament'])->setName('create_tournament');

      /**
       * Tournament routes
       */
      $auth_grp->group('/tournament/{tournamentId:\d+}', function (RouteCollectorProxy $tgrp) use ($statusGuardMW)
      {
         /* tournament overview */
         $tgrp->get('[/]',      [TournamentController::class, 'showTournament'])->setName('show_tournament');
         $tgrp->get('/control', [TournamentController::class, 'showControlPanel'])->setName('tournament_control');

         /* state transition */
         $tgrp->post('/setstatus', [TournamentController::class, 'changeTournamentStatus'])->setName('update_tournament_status');

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

         /* category routes */
         $tgrp->group('/category/{categoryId:\d+}', function (RouteCollectorProxy $cgrp) use ($statusGuardMW)
         {
            /* category management */
            $cgrp->get( '', [CategoryController::class, 'showCategory'])->setName('show_category');
            $cgrp->get( '/configure', [CategoryController::class, 'showCategoryConfiguration'])->setName('show_category_cfg');
            $cgrp->get( '/area/ko/{chunk}', [CategoryController::class, 'showKoArea'])->setName('show_ko_area');
            $cgrp->get( '/area/pool/{areaid:\d+}', [CategoryController::class, 'showPoolArea'])->setName('show_pool_area');

            $cgrp->post('/configure', [CategoryController::class, 'updateCategoryConfiguration'])->setName('update_category_cfg')
               ->add( $statusGuardMW->for(TournamentAction::ManageSetup) );

            $cgrp->get('/ko/{matchName}', [MatchRecordController::class, 'showKoMatch'])->setName('show_ko_match');
            $cgrp->post('/ko/{matchName}', [MatchRecordController::class, 'updateKoMatch'])->setName('update_ko_match')
               ->add( $statusGuardMW->for(TournamentAction::RecordResults) );

            $cgrp->post('resetResults', [CategoryController::class, 'resetMatchRecords'])->setName('reset_category_results')
               ->add( $statusGuardMW->for(TournamentAction::RecordResults) );
         });
      });

      $auth_grp->get('/user/account', [UserController::class, 'showAccount'])->setName('user_account');
      $auth_grp->post('/user/account', [UserController::class, 'updateAccount'])->setName('user_account_post');

      /* db migration during development, only */
      if( config::$test_interfaces ?? false )
      {
         $auth_grp->get('/dbmigrate', [TestController::class, 'showDbMigrationList'])->setName('show_db_migrate');
         $auth_grp->post('/dbmigrate', [TestController::class, 'setDbMigration'])->setName('do_db_migrate');
      }
   })
   ->add($authMW);
};

