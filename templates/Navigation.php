<?php

namespace Templates;

use Tournament\Service\RouteArgsContext;

class Navigation
{
   public const MAIN_MENU_DEPTH = 2;

   static public function structure(?RouteArgsContext $ctx = null): array
   {
      return [
         [  'label' => 'Turniere',
            'route' => 'home'
         ],

         [  'label' => 'Benutzerverwaltung',
            'route' => 'list_users'
         ],

         [  'label' => 'DB-Migration',
            'route' => 'show_db_migrate',
            'visible_if' => \config::$test_interfaces,
         ],

         [  'label' => $ctx?->tournament?->name,
            'route' => 'show_tournament',
            'class' => 'separated',
            'children' => [
               [  'foreach'   => $ctx?->tournament?->categories,
                  'route'     => 'show_category_home',
                  'label'     => fn($c) => $c->name,
                  'active_if' => fn($c) => $ctx?->category === $c,
                  'args'      => fn($c) => [ 'categoryId' => $c->id ],
                  'children'  => [
                     [  'label' => 'Pools',
                        'route' => 'show_category_pools',
                        'children' => [
                           [  'label' => 'Pool ' . $ctx?->pool_name,
                              'route' => 'show_pool',
                              'children' => [
                                 [  'label' => 'Kampf ' . $ctx?->match_name,
                                    'route' => 'show_pool_match'
                                 ]
                              ]
                           ]
                        ]
                     ],

                     [  'label'    => 'KO-Baum',
                        'route'    => 'show_category_ko',
                        'children' => [
                           [  'label' => 'Kampf '.$ctx?->match_name,
                              'route' => 'show_ko_match'
                           ]
                        ]
                     ],

                     [  'label' => 'Konfiguration',
                        'route' => 'show_category_cfg'
                     ],
                  ],
               ],

               [  'label' => 'Anmeldungen',
                  'route' => 'show_participant_list'
               ],

               [ 'label' => 'Konfiguration',
                  'route' => 'show_tournament_config',
                  'children' => [
                     [  'label' => $ctx?->category?->name,
                        'route' => 'show_tournament_category_cfg'
                     ]
                  ]
               ],
            ],
         ],
      ];
   }
}