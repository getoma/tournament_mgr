<?php

use Tournament\Controller\ParticipantsDataController;
use Tournament\Controller\TournamentSettingsController;
use Tournament\Controller\IndexPageController;
use Tournament\Controller\AuthController;
use Tournament\Controller\AccountController;
use Tournament\Controller\TestController;
use Tournament\Controller\TournamentTreeController;
use Tournament\Controller\UserManagementController;

use Tournament\Policy\TournamentAction;
use Tournament\Exception\EntityNotFoundException;
use Tournament\Middleware\EntityNotFoundHandler;

use Base\Service\RedirectHandler;
use Slim\Middleware\MethodOverrideMiddleware;
use Slim\Routing\RouteCollectorProxy;

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

   // Add AuthContextMiddleWare
   $app->add(\Tournament\Middleware\AuthContextMiddleware::create($app));

   // twig middleware to support various extensions in templates
   $app->add(Slim\Views\TwigMiddleware::create($app, $container->get(Slim\Views\Twig::class)));

   // method override: support overriding the HTTP method - needed for html clients that only support GET/POST
   $app->add(new MethodOverrideMiddleware());

   // Add Error Handling Middleware
   $errMW = $app->addErrorMiddleware(config::$debug ?? false, true, false);
   // Add custom handler for EntityNotFoundException
   $errMW->setErrorHandler(EntityNotFoundException::class, EntityNotFoundHandler::create($app));

   /**********************
    * Local MiddleWare Initialization
    */

   // create AuthGuard - enforce login on specific routes
   $authGuard = \Base\Middleware\AuthMiddleware::create($app, 'auth.login.form');

   // Add TournamentStatusGuardMiddleware - enforce tournament status based permissions
   $policyGuard = \Tournament\Middleware\TournamentPolicyGuardMiddleware::create($app);

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
    * Routes that need an active login
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
         $tgrp->get('/participants/add', [RedirectHandler::class, 'tournaments.participants.index']);
         $tgrp->get('/participants/upload', [RedirectHandler::class, 'tournaments.participants.index']);
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

            /* Tournament tree navigation */
            $cgrp->get('/category', [TournamentTreeController::class, 'showCategoryHome'])->setName('tournaments.categories.show');
            $cgrp->get('/pool', [TournamentTreeController::class, 'showCategoryPool'])->setName('tournaments.categories.pools.index');
            $cgrp->get('/pool/{pool}', [TournamentTreeController::class, 'showPool'])->setName('tournaments.categories.pools.show');
            $cgrp->get('/area/ko/{chunk}', [TournamentTreeController::class, 'showKoArea'])->setName('tournaments.categories.ko.chunks.show');
            $cgrp->get('/ko', [TournamentTreeController::class, 'showCategorytree'])->setName('tournaments.categories.ko.show');

            /* Match browsing */
            $cgrp->get('/ko/{matchName}', [TournamentTreeController::class, 'showMatch'])->setName('tournaments.categories.ko.matches.show');
            $cgrp->get('/pool/{pool}/show/{matchName}', [TournamentTreeController::class, 'showMatch'])->setName('tournaments.categories.pools.matches.show');
            $cgrp->get('/pool/{pool}/addTieBreak', [RedirectHandler::class, 'tournaments.categories.pools.show']);
            $cgrp->get('/pool/{pool}/delete/{decision_round}', [RedirectHandler::class, 'tournaments.categories.pools.show']);

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
   ->add($authGuard);
};

