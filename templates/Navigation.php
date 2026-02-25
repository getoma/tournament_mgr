<?php

namespace Templates;

use Tournament\Policy\TournamentAction;
use Tournament\Policy\TournamentPolicy;
use Tournament\Service\RouteArgsContext;

class Navigation
{
   public const MAIN_MENU_DEPTH = 2;

   static public function structure(TournamentPolicy $policy, ?RouteArgsContext $ctx = null): array
   {
      return [
         [  'label' => 'Turniere',
            'route' => 'tournaments.index'
         ],

         [  'label'      => 'Benutzerverwaltung',
            'route'      => 'users.index',
            'visible_if' => $policy->isActionAllowed(TournamentAction::ManageUsers),
         ],

         [  'label' => 'DB-Migration',
            'route' => 'dbmigration.show',
            'visible_if' => \config::$test_interfaces,
         ],

         [  'label' => $ctx?->tournament?->name,
            'route' => 'tournaments.show',
            'class' => 'separated',
            'children' => [
               [  'foreach'   => $ctx?->tournament?->categories,
                  'route'     => 'tournaments.categories.show',
                  'label'     => fn($c) => $c->name,
                  'active_if' => fn($c) => $ctx?->category === $c,
                  'args'      => fn($c) => [ 'categoryId' => $c->id ],
                  'children'  => [
                     [  'label' => 'Pools',
                        'route' => 'tournaments.categories.pools.index',
                        'children' => [
                           [  'label' => 'Pool ' . $ctx?->pool_name,
                              'route' => 'tournaments.categories.pools.show',
                              'children' => [
                                 [  'label' => 'Kampf ' . $ctx?->match_name,
                                    'route' => 'tournaments.categories.pools.matches.show'
                                 ]
                              ]
                           ]
                        ]
                     ],

                     [  'label'    => 'KO-Baum',
                        'route'    => 'tournaments.categories.ko.show',
                        'children' => [
                           [  'label' => 'Kampf '.$ctx?->match_name,
                              'route' => 'tournaments.categories.ko.matches.show'
                           ]
                        ]
                     ],

                     [  'label' => 'Konfiguration',
                        'route' => 'tournaments.categories.edit'
                     ],
                  ],
               ],

               [  'label' => 'Anmeldungen',
                  'route' => 'tournaments.participants.index'
               ],

               [  'label' => 'Konfiguration',
                  'route' => 'tournaments.edit',
               ],
            ],
         ],
      ];
   }
}