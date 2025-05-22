<?php

use App\Controller\ParticipantsController;
use App\Controller\TournamentController;
use App\Controller\NavigationController;
use App\Controller\CategoryController;

return [
   'home'               => ['GET', '/', [NavigationController::class, 'index'] ],

   /* create tournament */
   'new_tournament_form' => [ 'GET',  '/tournament/create', [TournamentController::class, 'showFormNewTournament'] ],
   'create_tournament'   => [ 'POST', '/tournament/create', [TournamentController::class, 'createTournament']      ],

   /* tournament overview */
   'show_tournament'    => ['GET', '/tournament/{id:\d+}',         [NavigationController::class, 'showTournament']],
   'tournament_control' => ['GET', '/tournament/{id:\d+}/control', [NavigationController::class, 'showControlPanel']],

   /* tournament configuration pages */
   'show_tournament_config'   => ['GET',  '/tournament/{id:\d+}/configure',            [TournamentController::class, 'showTournamentConfiguration']  ],
   'update_tournament_config' => ['POST', '/tournament/{id:\d+}/configure',            [TournamentController::class, 'updateTournament']],
   'create_area'              => ['POST', '/tournament/{id:\d+}/area/create',          [TournamentController::class, 'createArea'] ],
   'update_area'              => ['POST', '/tournament/{id:\d+}/area/{areaId:\d+}/update', [TournamentController::class, 'updateArea'] ],
   'delete_area'              => ['POST', '/tournament/{id:\d+}/area/{areaId:\d+}/delete', [TournamentController::class, 'deleteArea'] ],
   'create_category'          => ['POST', '/tournament/{id:\d+}/category/create',          [TournamentController::class, 'createCategory'] ],
   'update_category'          => ['POST', '/tournament/{id:\d+}/category/{categoryId:\d+}/update', [TournamentController::class, 'updateCategory'] ],
   'delete_category'          => ['POST', '/tournament/{id:\d+}/category/{categoryId:\d+}/delete', [TournamentController::class, 'deleteCategory'] ],

   /* participants */
   'show_participant_list'   => ['GET',  '/tournament/{id:\d+}/participants',                            [ParticipantsController::class, 'showParticipantList'] ],
   'update_participant_list' => ['POST', '/tournament/{id:\d+}/participants',                            [ParticipantsController::class, 'updateParticipantList'] ],
   'show_participant'        => ['GET',  '/tournament/{id:\d+}/participants/{participantId:\d+}',        [ParticipantsController::class, 'showParticipant']],
   'update_participant'      => ['POST', '/tournament/{id:\d+}/participants/{participantId:\d+}',        [ParticipantsController::class, 'updateParticipant'] ],
   'delete_participant'      => ['POST', '/tournament/{id:\d+}/participants/{participantId:\d+}/delete', [ParticipantsController::class, 'deleteParticipant'] ],
   'import_participants'     => ['POST', '/tournament/{id:\d+}/participants/import',                     [ParticipantsController::class, 'importParticipantList'] ],

   /* category management */
   'show_category'             => ['GET',  '/tournament/{id:\d+}/category/{categoryId:\d+}',              [CategoryController::class, 'showCategory'] ],
   'show_category_cfg'         => ['GET',  '/tournament/{id:\d+}/category/{categoryId:\d+}/configure',    [CategoryController::class, 'showCategoryConfiguration'] ],
   'update_category_cfg'       => ['POST', '/tournament/{id:\d+}/category/{categoryId:\d+}/configure',    [CategoryController::class, 'updateCategoryConfiguration'] ],
   'show_ko_area'              => ['GET',  '/tournament/{id:\d+}/category/{categoryId:\d+}/area/ko/{chunk}',    [CategoryController::class, 'showKoArea']],
   'show_pool_area'            => ['GET',  '/tournament/{id:\d+}/category/{categoryId:\d+}/area/pool/{areaid:\d+}', [CategoryController::class, 'showPoolArea']],
];
