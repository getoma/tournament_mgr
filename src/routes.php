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

   // Add Error Handling Middleware
   $errMW = $app->addErrorMiddleware(config::$debug ?? false, true, false);
   // Add custom handler for EntityNotFoundException
   $errMW->setErrorHandler(EntityNotFoundException::class, EntityNotFoundHandler::create($app));

   /**********************
    * Local MiddleWare Initialization
    */

   // create AuthGuard - enforce login on specific routes
   $authGuard = \Base\Middleware\AuthMiddleware::create($app, 'login');

   // Add TournamentStatusGuardMiddleware - enforce tournament status based permissions
   $policyGuard = \Tournament\Middleware\TournamentPolicyGuardMiddleware::create($app);

   // navigation key route handler, to inject a specific navigation identifier
   $navGuard = \Tournament\Middleware\NavigationKeyMiddleware::create($app);

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
   $app->group('', function (RouteCollectorProxy $auth_grp) use ($policyGuard, $navGuard)
   {
      /* navigation */
      $auth_grp->get('/', [IndexPageController::class, 'index'])->setName('home');

      /* create tournament */
      $auth_grp->group('/tournament/create', function (RouteCollectorProxy $tgrp)
      {
         $tgrp->get('', [TournamentSettingsController::class, 'showFormNewTournament'])->setName('new_tournament_form');
         $tgrp->post('', [TournamentSettingsController::class, 'createTournament'])->setName('create_tournament');
      })
      ->add( $policyGuard->for(TournamentAction::CreateTournaments) );

      /**
       * Tournament routes
       */
      $auth_grp->group('/tournament/{tournamentId:\d+}', function (RouteCollectorProxy $tgrp) use ($policyGuard, $navGuard)
      {
         /* tournament overview */
         $tgrp->get('[/]',      [TournamentSettingsController::class, 'showTournament'])->setName('show_tournament');

         /* state transition */
         $tgrp->post('/setstatus', [TournamentSettingsController::class, 'changeTournamentStatus'])->setName('update_tournament_status')
            ->add( $policyGuard->for(TournamentAction::TransitionState));

         /* tournament configuration pages */
         $tgrp->group('/configure', function (RouteCollectorProxy $tcfg) use ( $policyGuard, $navGuard)
         {
            $tcfg->get( '[/]', [TournamentSettingsController::class, 'showTournamentConfiguration'])->setName('show_tournament_config');
            $tcfg->post('[/]', [TournamentSettingsController::class, 'updateTournament'])->setName('update_tournament_config')
               ->add(  $policyGuard->for(TournamentAction::ManageDetails) );

            $tcfg->post('/add_owner', [TournamentSettingsController::class, 'addOwner'])->setName('tournament.owner.add')
               ->add( $policyGuard->for(TournamentAction::ManageOwners));
            $tcfg->post('/drop_owner', [TournamentSettingsController::class, 'removeOwner'])->setName('tournament.owner.remove')
               ->add($policyGuard->for(TournamentAction::ManageOwners));

            $tcfg->group('/category/{categoryId:\d+}', function (RouteCollectorProxy $ccfg) use ( $policyGuard)
            {
               $ccfg->get('',  [TournamentSettingsController::class, 'showCategoryConfiguration'])->setName('show_tournament_category_cfg');
               $ccfg->post('', [TournamentSettingsController::class, 'updateCategoryConfiguration'])->setName('update_tournament_category_cfg')
                  ->add( $policyGuard->for(TournamentAction::ManageSetup));
            })
            ->add( $navGuard->navkey('show_tournament_category_cfg') );
         });

         $tgrp->group('', function (RouteCollectorProxy $cgrp)
         {
            $cgrp->post('/area/create', [TournamentSettingsController::class, 'createArea'])->setName('create_area');
            $cgrp->post('/area/{areaId:\d+}/update', [TournamentSettingsController::class, 'updateArea'])->setName('update_area');
            $cgrp->post('/area/{areaId:\d+}/delete', [TournamentSettingsController::class, 'deleteArea'])->setName('delete_area');
            $cgrp->post('/category/create', [TournamentSettingsController::class, 'createCategory'])->setName('create_category');
            $cgrp->post('/category/{categoryId:\d+}/update', [TournamentSettingsController::class, 'updateCategory'])->setName('update_category');
            $cgrp->post('/category/{categoryId:\d+}/delete', [TournamentSettingsController::class, 'deleteCategory'])->setName('delete_category');
         }
         )->add( $policyGuard->for(TournamentAction::ManageSetup) );

         /* participants */
         $tgrp->get('/participants[/]', [ParticipantsDataController::class, 'showParticipantList'])->setName('show_participant_list');
         $tgrp->get('/participants/{participantId:\d+}', [ParticipantsDataController::class, 'showParticipant'])->setName('show_participant');
         $tgrp->get('/participants/add', [RedirectHandler::class, 'show_participant_list']);
         $tgrp->get('/participants/upload', [RedirectHandler::class, 'show_participant_list']);
         $tgrp->group('', function (RouteCollectorProxy $pgrp)
         {
            $pgrp->post('/participants', [ParticipantsDataController::class, 'updateParticipantList'])->setName('update_participant_list');
            $pgrp->post('/participants/{participantId:\d+}', [ParticipantsDataController::class, 'updateParticipant'])->setName('update_participant');
            $pgrp->post('/participants/{participantId:\d+}/delete', [ParticipantsDataController::class, 'deleteParticipant'])->setName('delete_participant');
            $pgrp->post('/participants/add',    [ParticipantsDataController::class, 'addParticipants'])->setName('add_participants');
            $pgrp->post('/participants/upload', [ParticipantsDataController::class, 'uploadParticipantFile'])->setName('upload_participants');
            $pgrp->get('/participants/upload/confirm', [ParticipantsDataController::class, 'confirmUpload'])->setName('upload_participants_confirm');
            $pgrp->post('/participants/upload/confirm', [ParticipantsDataController::class, 'confirmUpload'])->setName('upload_participants_confirm_update');
         }
         )->add( $policyGuard->for(TournamentAction::ManageParticipants) );

         /* category routes */
         $tgrp->group('/category/{categoryId:\d+}', function (RouteCollectorProxy $cgrp) use ($policyGuard)
         {
            /* category management */
            $cgrp->get('/configure', [TournamentSettingsController::class, 'showCategoryConfiguration'])->setName('show_category_cfg');
            $cgrp->post('/configure', [TournamentSettingsController::class, 'updateCategoryConfiguration'])->setName('update_category_cfg')
               ->add($policyGuard->for(TournamentAction::ManageSetup));

            $cgrp->post('/repopulate', [TournamentTreeController::class, 'repopulate'])->setName('repopulate_category')
               ->add($policyGuard->for(TournamentAction::ManageSetup));

            /* Tournament tree navigation */
            $cgrp->get('/category', [TournamentTreeController::class, 'showCategoryHome'])->setName('show_category_home');
            $cgrp->get('/pool', [TournamentTreeController::class, 'showCategoryPool'])->setName('show_category_pools');
            $cgrp->get('/pool/{pool}', [TournamentTreeController::class, 'showPool'])->setName('show_pool');
            $cgrp->get('/area/ko/{chunk}', [TournamentTreeController::class, 'showKoArea'])->setName('show_ko_area');
            $cgrp->get('/ko', [TournamentTreeController::class, 'showCategorytree'])->setName('show_category_ko');

            /* Match browsing */
            $cgrp->get('/ko/{matchName}', [TournamentTreeController::class, 'showMatch'])->setName('show_ko_match');
            $cgrp->get('/pool/{pool}/show/{matchName}', [TournamentTreeController::class, 'showMatch'])->setName('show_pool_match');
            $cgrp->get('/pool/{pool}/addTieBreak', [RedirectHandler::class, 'show_pool']);
            $cgrp->get('/pool/{pool}/delete/{decision_round}', [RedirectHandler::class, 'show_pool']);

            /* Match Result recording */
            $cgrp->group('', function (RouteCollectorProxy $mgrp) use ($policyGuard)
            {
               $mgrp->post('/ko/{matchName}', [TournamentTreeController::class, 'updateMatch'])->setName('update_ko_match');
               $mgrp->post('/pool/{pool}/show/{matchName}', [TournamentTreeController::class, 'updateMatch'])->setName('update_pool_match');
               $mgrp->post('/pool/{pool}/addTieBreak', [TournamentTreeController::class, 'addPoolTieBreak'])->setName('add_pool_tiebreak');
               $mgrp->post('/pool/{pool}/delete/{decision_round}', [TournamentTreeController::class, 'deletePoolDecisionRound'])->setName('delete_pool_tiebreak');
               $mgrp->post('resetResults', [TournamentTreeController::class, 'resetMatchRecords'])->setName('reset_category_results');
            })
            ->add($policyGuard->for(TournamentAction::RecordResults));
         });
      })
      ->add($policyGuard->for(TournamentAction::BrowseTournament));

      /* user management */
      $auth_grp->group('/users', function (RouteCollectorProxy $ugrp)
      {
         $ugrp->get( '[/]',            [UserManagementController::class, 'listUsers'])->setName('list_users');
         $ugrp->get( '/create',        [UserManagementController::class, 'showCreateUser'])->setName('show_create_user');
         $ugrp->post('/create',        [UserManagementController::class, 'createUser'])->setName('do_create_user');
         $ugrp->get( '/{userId:\d+}',  [UserManagementController::class, 'showUser'])->setName('show_user');
         $ugrp->post('/{userId:\d+}',  [UserManagementController::class, 'updateUser'])->setName('update_user');
         $ugrp->get( '/{userId:\d+}/delete', [UserManagementController::class, 'deleteUser'])->setName('delete_user');
         $ugrp->get( '/{userId:\d+}/welcome_mail', [UserManagementController::class, 'sendNewUserMail'])->setName('welcome_user');
      })
      ->add($policyGuard->for(TournamentAction::ManageUsers));

      $auth_grp->group('/account', function (RouteCollectorProxy $agrp)
      {
         $agrp->get('', [AccountController::class, 'showAccount'])->setName('user_account');
         $agrp->post('', [AccountController::class, 'updateAccount'])->setName('user_account_post');
      })
      ->add($policyGuard->for(TournamentAction::ManageAccount));

      /* db migration during development, only */
      if( config::$test_interfaces ?? false )
      {
         $auth_grp->get('/dbmigrate', [TestController::class, 'showDbMigrationList'])->setName('show_db_migrate');
         $auth_grp->post('/dbmigrate', [TestController::class, 'setDbMigration'])->setName('do_db_migrate');
      }
   })
   ->add($authGuard);
};

