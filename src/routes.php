<?php

use Tournament\Controller\App\ParticipantsDataController;
use Tournament\Controller\App\TournamentSettingsController;
use Tournament\Controller\App\IndexPageController;
use Tournament\Controller\App\AuthController;
use Tournament\Controller\App\AccountController;
use Tournament\Controller\App\TestController;
use Tournament\Controller\App\TournamentTreeController;
use Tournament\Controller\App\UserManagementController;

use Tournament\Controller\Device\AreaDeviceAuthController;

use Tournament\Policy\TournamentAction;

use Base\Service\SessionValidationIssue;
use Base\Service\RedirectHandler;

use Slim\Routing\RouteCollectorProxy;
use Tournament\Policy\AuthType;

return function (\Slim\App $app)
{
   $container = $app->getContainer();

   /**********************
    * Global MiddleWare Injection
    */

   // Add PRG Middleware - support POST-REDIRECT-GET pattern with status messages
   $app->add(Base\Middleware\PrgMiddleware::create($app));

   // Add TournamentPolicyMiddleware - requires AuthContext and RouteArgsContext
   $app->add(\Tournament\Middleware\TournamentPolicyMiddleware::create($app));

   // Add RouteArgsResolverMiddleware - requires routing middleware, creates RouteArgsContext
   $app->add(\Tournament\Middleware\RouteArgsResolverMiddleware::create($app));

   // Add Routing Middleware
   $app->addRoutingMiddleware();

   // Add Activity tracking
   $app->add(\Tournament\Middleware\ActivityTrackingMiddleware::create($app));

   // Add AuthContextMiddleWare
   $app->add(\Tournament\Middleware\AuthContextMiddleware::create($app));

   // Add Session Validation
   $app->add(\Base\Middleware\SessionValidationMiddleware::create($app));

   // twig middleware to support various extensions in templates
   $app->add(Slim\Views\TwigMiddleware::create($app, $container->get(Slim\Views\Twig::class)));

   // method override: support overriding the HTTP method - needed for html clients that only support GET/POST
   $app->add(new Slim\Middleware\MethodOverrideMiddleware());

   // Add Error Handling Middleware, and register our error page renderer
   $errMW = $app->addErrorMiddleware(config::$debug ?? false, true, false);
   $errMW->getDefaultErrorHandler()
         ->registerErrorRenderer('text/html', \Base\Middleware\ErrorPageRenderer::create($app, 'special_pages/error_page.twig'));
   // also correctly handle SessionValidationIssues by just showing an error page about the occurred logout */
   $errMW->setErrorHandler(SessionValidationIssue::class, SessionValidationIssue::getSlimErrorHandler($app, 'special_pages/forced_logout.twig'));


   /**********************
    * Local MiddleWare Initialization
    */
   // Add TournamentStatusGuardMiddleware - enforce tournament status based permissions
   $policyGuard = \Tournament\Middleware\TournamentPolicyGuardMiddleware::create($app);

   // General authorization checks
   $authGuard = \Base\Middleware\AuthMiddleware::create($app, 'auth.login.form');

   /**********************
    * Route setup
    */

   /* login/logout/pw reset */
   $app->get('/login',   [AuthController::class, 'showLogin'])->setName('auth.login.form');
   $app->post('/login',  [AuthController::class, 'login'])->setName('auth.login.attempt');
   $app->get('/logout',  [AuthController::class, 'logout'])->setName('auth.logout');
   $app->get('/forgot',  [AuthController::class, 'showForgotPassword'])->setName('auth.password.request.form');
   $app->post('/forgot', [AuthController::class, 'requestPasswordReset'])->setName('auth.password.request.send');
   $app->get('/reset',   [AuthController::class, 'showResetPassword'])->setName('auth.password.reset.form');
   $app->post('/reset',  [AuthController::class, 'resetPassword'])->setName('auth.password.reset.update');

   /***
    * Routes that need an active user login
    */
   $app->group('', function (RouteCollectorProxy $auth_grp) use ($policyGuard)
   {
      /* navigation */
      $auth_grp->get('/', [IndexPageController::class, 'index'])->setName('tournaments.index');

      /* create tournament */
      $auth_grp->group('/tournament/create', function (RouteCollectorProxy $tgrp)
      {
         $tgrp->get('', [TournamentSettingsController::class, 'showFormNewTournament'])->setName('tournaments.create');
         $tgrp->post('', [TournamentSettingsController::class, 'createTournament'])->setName('tournaments.store');
      })
      ->add( $policyGuard->for(TournamentAction::CreateTournaments) );

      /**
       * Tournament routes
       */
      $auth_grp->group('/tournament/{tournamentId:\d+}', function (RouteCollectorProxy $tgrp) use ($policyGuard)
      {
         /* tournament overview */
         $tgrp->get('[/]',      [TournamentSettingsController::class, 'showTournament'])->setName('tournaments.show');

         /* state transition */
         $tgrp->patch('/setstatus', [TournamentSettingsController::class, 'changeTournamentStatus'])->setName('tournaments.setState')
            ->add( $policyGuard->for(TournamentAction::TransitionState));

         /* delete an tournament */
         $tgrp->delete('/delete', [TournamentSettingsController::class, 'deleteTournament'])->setName('tournaments.delete')
            ->add( $policyGuard->for(TournamentAction::DeleteTournament));

         /* tournament configuration pages */
         $tgrp->group('/configure', function (RouteCollectorProxy $tcfg) use ( $policyGuard)
         {
            $tcfg->get( '[/]', [TournamentSettingsController::class, 'showTournamentConfiguration'])->setName('tournaments.edit');
            $tcfg->patch('[/]', [TournamentSettingsController::class, 'updateTournament'])->setName('tournaments.update')
               ->add(  $policyGuard->for(TournamentAction::ManageDetails) );

            $tcfg->patch('/add_owner', [TournamentSettingsController::class, 'addOwner'])->setName('tournaments.addOwner')
               ->add( $policyGuard->for(TournamentAction::ManageOwners));
            $tcfg->patch('/drop_owner', [TournamentSettingsController::class, 'removeOwner'])->setName('tournaments.removeOwner')
               ->add($policyGuard->for(TournamentAction::ManageOwners));
         });

         $tgrp->group('', function (RouteCollectorProxy $cgrp)
         {
            $cgrp->post('/area/create', [TournamentSettingsController::class, 'createArea'])->setName('tournaments.areas.store');
            $cgrp->patch('/area/{areaId:\d+}/update', [TournamentSettingsController::class, 'updateArea'])->setName('tournaments.areas.update');
            $cgrp->delete('/area/{areaId:\d+}/delete', [TournamentSettingsController::class, 'deleteArea'])->setName('tournaments.areas.delete');

            $cgrp->post('/category/create', [TournamentSettingsController::class, 'createCategory'])->setName('tournaments.categories.store');
         }
         )->add( $policyGuard->for(TournamentAction::ManageSetup) );

         /* participants */
         $tgrp->get('/participants[/]', [ParticipantsDataController::class, 'showParticipantList'])->setName('tournaments.participants.index');
         $tgrp->get('/participants/{participantId:\d+}', [ParticipantsDataController::class, 'showParticipant'])->setName('tournaments.participants.show');
         $tgrp->get('/participants/add', RedirectHandler::to('tournaments.participants.index'));
         $tgrp->get('/participants/upload', RedirectHandler::to('tournaments.participants.index'));
         $tgrp->group('', function (RouteCollectorProxy $pgrp)
         {
            $pgrp->patch('/participants', [ParticipantsDataController::class, 'updateParticipantList'])->setName('tournaments.participants.bulk.update');
            $pgrp->patch('/participants/{participantId:\d+}', [ParticipantsDataController::class, 'updateParticipant'])->setName('tournaments.participants.update');
            $pgrp->delete('/participants/{participantId:\d+}/delete', [ParticipantsDataController::class, 'deleteParticipant'])->setName('tournaments.participants.delete');
            $pgrp->post('/participants/add',    [ParticipantsDataController::class, 'addParticipants'])->setName('tournaments.participants.bulk.store');
            $pgrp->post('/participants/import', [ParticipantsDataController::class, 'uploadParticipantFile'])->setName('tournaments.participants.import.store');
            $pgrp->get('/participants/import', [ParticipantsDataController::class, 'handleImport'])->setName('tournaments.participants.import.show');
            $pgrp->post('/participants/import/commit', [ParticipantsDataController::class, 'handleImport'])->setName('tournaments.participants.import.commit');
            $pgrp->delete('/participants/import', [ParticipantsDataController::class, 'abortUpload'])->setName('tournaments.participants.import.delete');
         }
         )->add( $policyGuard->for(TournamentAction::ManageParticipants) );

         /* area device account settings */
         $tgrp->group('/areas/devices', function (RouteCollectorProxy $agrp)
         {
            $agrp->get('[/]', [AreaDeviceAuthController::class, 'showAreaDeviceStatus'])->setName('tournaments.areas.devices.index');
            $agrp->post('/{areaId:\d+}/create_code', [AreaDeviceAuthController::class, 'createLoginCode'])->setName('tournaments.areas.devices.createLogin');
            $agrp->post('/{areaId:\d+}/invalidate_code',[AreaDeviceAuthController::class, 'invalidateLoginCode'])->setName('tournaments.areas.devices.invalidateLogin');
            $agrp->post('/{areaId:\d+}/disable',     [AreaDeviceAuthController::class, 'disableDevice'])->setName('tournaments.areas.devices.disable');
         }
         )->add( $policyGuard->for(TournamentAction::ManageAreaDevices) );

         /* category routes */
         $tgrp->group('/category/{categoryId:\d+}', function (RouteCollectorProxy $cgrp) use ($policyGuard)
         {
            /* category management */
            $cgrp->get('/configure', [TournamentSettingsController::class, 'showCategoryConfiguration'])->setName('tournaments.categories.edit');
            $cgrp->patch('/configure', [TournamentSettingsController::class, 'updateCategoryConfiguration'])->setName('tournaments.categories.update')
               ->add($policyGuard->for(TournamentAction::ManageSetup));
            $cgrp->delete('/delete', [TournamentSettingsController::class, 'deleteCategory'])->setName('tournaments.categories.delete')
               ->add($policyGuard->for(TournamentAction::ManageSetup));

            $cgrp->post('/repopulate', [TournamentTreeController::class, 'repopulate'])->setName('tournaments.categories.shuffleParticipants')
               ->add($policyGuard->for(TournamentAction::ManageSetup));

            $cgrp->post('/addNewParticipants', [TournamentTreeController::class, 'addUnslottedParticipants'])->setName('tournaments.categories.assignParticipants')
               ->add($policyGuard->for(TournamentAction::ManageParticipants));

            /* Tournament tree navigation */
            $cgrp->get('/category', [TournamentTreeController::class, 'showCategoryHome'])->setName('tournaments.categories.show');
            $cgrp->get('/pool', [TournamentTreeController::class, 'showCategoryPool'])->setName('tournaments.categories.pools.index');
            $cgrp->get('/pool/{pool}', [TournamentTreeController::class, 'showPool'])->setName('tournaments.categories.pools.show');
            $cgrp->get('/area/ko/{chunk}', [TournamentTreeController::class, 'showKoArea'])->setName('tournaments.categories.ko.chunks.show');
            $cgrp->get('/ko', [TournamentTreeController::class, 'showCategorytree'])->setName('tournaments.categories.ko.show');

            /* Match browsing */
            $cgrp->get('/ko/{matchName}', [TournamentTreeController::class, 'showMatch'])->setName('tournaments.categories.ko.matches.show');
            $cgrp->get('/pool/{pool}/show/{matchName}', [TournamentTreeController::class, 'showMatch'])->setName('tournaments.categories.pools.matches.show');
            $cgrp->get('/pool/{pool}/addTieBreak', RedirectHandler::to('tournaments.categories.pools.show'));
            $cgrp->get('/pool/{pool}/delete/{decision_round}', RedirectHandler::to('tournaments.categories.pools.show'));

            /* Match Result recording */
            $cgrp->group('', function (RouteCollectorProxy $mgrp) use ($policyGuard)
            {
               $mgrp->patch('/ko/{matchName}', [TournamentTreeController::class, 'updateMatch'])->setName('tournaments.categories.ko.matches.update');
               $mgrp->patch('/pool/{pool}/show/{matchName}', [TournamentTreeController::class, 'updateMatch'])->setName('tournaments.categories.pools.matches.update');
               $mgrp->post('/pool/{pool}/addTieBreak', [TournamentTreeController::class, 'addPoolTieBreak'])->setName('tournaments.categories.pools.decision.add');
               $mgrp->delete('/pool/{pool}/delete/{decision_round}', [TournamentTreeController::class, 'deletePoolDecisionRound'])->setName('tournaments.categories.pools.decision.delete');
               $mgrp->post('resetResults', [TournamentTreeController::class, 'resetMatchRecords'])->setName('tournaments.categories.resetMatchRecords');
            })
            ->add($policyGuard->for(TournamentAction::RecordResults));
         });
      })
      ->add($policyGuard->for(TournamentAction::BrowseTournament));

      /* user management */
      $auth_grp->group('/users', function (RouteCollectorProxy $ugrp)
      {
         $ugrp->get( '[/]',            [UserManagementController::class, 'listUsers'])->setName('users.index');
         $ugrp->get( '/create',        [UserManagementController::class, 'showCreateUser'])->setName('users.create');
         $ugrp->post('/create',        [UserManagementController::class, 'createUser'])->setName('users.store');
         $ugrp->get( '/{userId:\d+}',  [UserManagementController::class, 'showUser'])->setName('users.show');
         $ugrp->patch('/{userId:\d+}', [UserManagementController::class, 'updateUser'])->setName('users.update');
         $ugrp->delete( '/{userId:\d+}/delete', [UserManagementController::class, 'deleteUser'])->setName('users.delete');
         $ugrp->post('/{userId:\d+}/welcome_mail', [UserManagementController::class, 'sendNewUserMail'])->setName('users.sendWelcome');
      })
      ->add($policyGuard->for(TournamentAction::ManageUsers));

      $auth_grp->group('/account', function (RouteCollectorProxy $agrp)
      {
         $agrp->get('', [AccountController::class, 'showAccount'])->setName('account.show');
         $agrp->patch('', [AccountController::class, 'updateAccount'])->setName('account.update');
      })
      ->add($policyGuard->for(TournamentAction::ManageAccount));

      /* db migration during development, only */
      if( config::$test_interfaces ?? false )
      {
         $auth_grp->get('/dbmigrate', [TestController::class, 'showDbMigrationList'])->setName('dbmigration.show');
         $auth_grp->patch('/dbmigrate', [TestController::class, 'setDbMigration'])->setName('dbmigration.update');
      }
   })
   ->add($policyGuard->as(AuthType::USER))
   ->add($authGuard);

   /**
    * area device routes
    */

   /* device login via one-time-code */
   $app->get('/device/login', [AreaDeviceAuthController::class, 'showLogin'])->setName('device.login.form');
   $app->post('/device/login', [AreaDeviceAuthController::class, 'login'])->setName('device.login.attempt');
   $app->get('/device/logout', [AreaDeviceAuthController::class, 'logout'])->setName('device.logout');

   $app->group('/device', function (RouteCollectorProxy $device_grp) use ($policyGuard)
   {
      $device_grp->get('/dashboard', [TournamentTreeController::class, 'showAreaDashboard'])->setName('device.dashboard.show');
   })
   ->add($policyGuard->as(AuthType::DEVICE));
};

