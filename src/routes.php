<?php

use Tournament\Controller\ParticipantsDataController;
use Tournament\Controller\TournamentSettingsController;
use Tournament\Controller\IndexPageController;
use Tournament\Controller\AuthController;
use Tournament\Controller\UserDataController;
use Tournament\Controller\TestController;
use Tournament\Controller\TournamentTreeController;

use Tournament\Policy\TournamentAction;
use Tournament\Exception\EntityNotFoundException;
use Tournament\Middleware\EntityNotFoundHandler;

use Slim\Routing\RouteCollectorProxy;


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
      $auth_grp->get('/tournament/create', [TournamentSettingsController::class, 'showFormNewTournament'])->setName('new_tournament_form');
      $auth_grp->post('/tournament/create', [TournamentSettingsController::class, 'createTournament'])->setName('create_tournament');

      /**
       * Tournament routes
       */
      $auth_grp->group('/tournament/{tournamentId:\d+}', function (RouteCollectorProxy $tgrp) use ($statusGuardMW)
      {
         /* tournament overview */
         $tgrp->get('[/]',      [TournamentSettingsController::class, 'showTournament'])->setName('show_tournament');
         $tgrp->get('/control', [TournamentSettingsController::class, 'showControlPanel'])->setName('tournament_control');

         /* state transition */
         $tgrp->post('/setstatus', [TournamentSettingsController::class, 'changeTournamentStatus'])->setName('update_tournament_status');

         /* tournament configuration pages */
         $tgrp->get( '/configure', [TournamentSettingsController::class, 'showTournamentConfiguration'])->setName('show_tournament_config');
         $tgrp->post('/configure', [TournamentSettingsController::class, 'updateTournament'])->setName('update_tournament_config')
            ->add( $statusGuardMW->for(TournamentAction::ManageDetails) );

         $tgrp->group('', function (RouteCollectorProxy $cgrp)
         {
            $cgrp->post('/area/create', [TournamentSettingsController::class, 'createArea'])->setName('create_area');
            $cgrp->post('/area/{areaId:\d+}/update', [TournamentSettingsController::class, 'updateArea'])->setName('update_area');
            $cgrp->post('/area/{areaId:\d+}/delete', [TournamentSettingsController::class, 'deleteArea'])->setName('delete_area');
            $cgrp->post('/category/create', [TournamentSettingsController::class, 'createCategory'])->setName('create_category');
            $cgrp->post('/category/{categoryId:\d+}/update', [TournamentSettingsController::class, 'updateCategory'])->setName('update_category');
            $cgrp->post('/category/{categoryId:\d+}/delete', [TournamentSettingsController::class, 'deleteCategory'])->setName('delete_category');
         }
         )->add( $statusGuardMW->for(TournamentAction::ManageSetup) );

         /* participants */
         $tgrp->get( '/participants', [ParticipantsDataController::class, 'showParticipantList'])->setName('show_participant_list');
         $tgrp->get('/participants/{participantId:\d+}', [ParticipantsDataController::class, 'showParticipant'])->setName('show_participant');
         $tgrp->group('', function (RouteCollectorProxy $pgrp)
         {
            $pgrp->post('/participants', [ParticipantsDataController::class, 'updateParticipantList'])->setName('update_participant_list');
            $pgrp->post('/participants/{participantId:\d+}', [ParticipantsDataController::class, 'updateParticipant'])->setName('update_participant');
            $pgrp->post('/participants/{participantId:\d+}/delete', [ParticipantsDataController::class, 'deleteParticipant'])->setName('delete_participant');
            $pgrp->post('/participants/import', [ParticipantsDataController::class, 'importParticipantList'])->setName('import_participants');
         }
         )->add( $statusGuardMW->for(TournamentAction::ManageParticipants) );

         /* category routes */
         $tgrp->group('/category/{categoryId:\d+}', function (RouteCollectorProxy $cgrp) use ($statusGuardMW)
         {
            /* category management */
            $cgrp->get('/configure', [TournamentSettingsController::class, 'showCategoryConfiguration'])->setName('show_category_cfg');
            $cgrp->post('/configure', [TournamentSettingsController::class, 'updateCategoryConfiguration'])->setName('update_category_cfg')
               ->add($statusGuardMW->for(TournamentAction::ManageSetup));

            $cgrp->post('/repopulate', [TournamentTreeController::class, 'repopulate'])->setName('repopulate_category');

            /* Tournament tree navigation */
            $cgrp->get( '/tree', [TournamentTreeController::class, 'showCategoryTree'])->setName('show_category');
            $cgrp->get( '/area/ko/{chunk}', [TournamentTreeController::class, 'showKoArea'])->setName('show_ko_area');
            $cgrp->get( '/pool/{pool}', [TournamentTreeController::class, 'showPool'])->setName('show_pool');

            /* Match updating */
            $cgrp->get('/ko/{matchName}', [TournamentTreeController::class, 'showMatch'])->setName('show_ko_match');
            $cgrp->post('/ko/{matchName}', [TournamentTreeController::class, 'updateKoMatch'])->setName('update_ko_match')
               ->add( $statusGuardMW->for(TournamentAction::RecordResults) );

            $cgrp->get('/pool/{pool}/{matchName}', [TournamentTreeController::class, 'showMatch'])->setName('show_pool_match');
            $cgrp->post('/pool/{pool}/{matchName}', [TournamentTreeController::class, 'updateMatch'])->setName('update_pool_match')
               ->add($statusGuardMW->for(TournamentAction::RecordResults));

            $cgrp->post('resetResults', [TournamentTreeController::class, 'resetMatchRecords'])->setName('reset_category_results')
               ->add( $statusGuardMW->for(TournamentAction::RecordResults) );
         });
      });

      $auth_grp->get('/user/account', [UserDataController::class, 'showAccount'])->setName('user_account');
      $auth_grp->post('/user/account', [UserDataController::class, 'updateAccount'])->setName('user_account_post');

      /* db migration during development, only */
      if( config::$test_interfaces ?? false )
      {
         $auth_grp->get('/dbmigrate', [TestController::class, 'showDbMigrationList'])->setName('show_db_migrate');
         $auth_grp->post('/dbmigrate', [TestController::class, 'setDbMigration'])->setName('do_db_migrate');
      }
   })
   ->add($authMW);
};

