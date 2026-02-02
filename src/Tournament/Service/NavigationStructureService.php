<?php

namespace Tournament\Service;

use Templates\Navigation;
use Tournament\Model\User\AuthContext;
use Tournament\Policy\TournamentPolicy;

class NavigationStructureService
{
   public function __construct(
      private Navigation $nav
   )
   {
   }

   /**
    * Service may be called from a place where the central TournamentPolicy object is not yet created via
    * the corresponding middleware - therefore we create our own Policy object from AuthContext and RouteArgsContext
    */
   public function build(AuthContext $auth, ?RouteArgsContext $ctx, string $active_route = ''): array
   {
      $policy = new TournamentPolicy($auth, $ctx);
      $structure = static::preprocess($this->nav->structure($policy, $ctx));
      $args = $ctx?->args ?? [];
      $path = static::derivePath($structure, $active_route, $args);
      $active_routes = array_column($path, 'route');
      $tree = static::deriveNavTree($structure, $active_routes, $args, $this->nav::MAIN_MENU_DEPTH);
      return [
         'breadcrumbs' => $path,
         'pagemenu'    => $tree,
      ];
   }

   /**
    * preprocess the whole structure:
    * - resolve foreach (and contained callables)
    * - explicitly add default settings for skipped elements
    */
   static private function preprocess(array $structure): array
   {
      $result = [];
      foreach ($structure as $node )
      {
         if( isset($node['foreach']) )
         {
            $result = array_merge($result, static::resolveForeach($node));
         }
         else
         {
            // if no active_if/visible_if conditions set, default to whether node has an actual label
            $node['active_if']  ??= !empty($node['label']);
            $node['visible_if'] ??= !empty($node['label']);
            // preprocess any children as well
            if (isset($node['children'])) $node['children'] = static::preprocess($node['children']);
            // done
            $result[] = $node;
         }
      }
      return $result;
   }

   /**
    * subfunction to resolve foreach node attribute and explicitly trigger
    * the preprocessing (again) on all resolved nodes
    */
   static private function resolveForeach(array $node): array
   {
      $result = [];
      $foreach = $node['foreach'];
      unset($node['foreach']);
      foreach ($foreach as $entry)
      {
         $resolve_node = [];
         foreach ($node as $key => $value)
         {
            $resolve_node[$key] = is_callable($value)? $value($entry) : $value;
         }
         $result[] = $resolve_node;
      }
      return static::preprocess($result);
   }

   /**
    * find the current location in the navigation tree and store the full path as list of navigation structure nodes
    */
   static private function derivePath(array $structure, string $active_route, array $args, array $path = []): array
   {
      foreach ($structure as $node)
      {
         if (!$node['visible_if'] || !$node['active_if']) continue;

         // extract children
         $children = $node['children'] ?? [];
         unset($node['children']);

         // extend the current path
         $node['args'] = array_merge($args, $node['args'] ?? []);
         $current_path = array_merge($path, [$node]);

         if ($node['route'] === $active_route)
         {
            return $current_path; // current route found, return current path
         }
         else
         {
            if ($children)
            {
               // try to find the current node further below
               $result = static::derivePath($children, $active_route, $node['args'], $current_path);
               // if any found, return it upwards
               if ($result) return $result;
            }
         }
      }
      return [];
   }

   /**
    * derive the full main menu down to its max depth, mark the currently selected entries
    */
   static private function deriveNavTree(array $structure, array $nav_route, array $args, int $max_depth, int $depth = 1): array
   {
      if ($depth > $max_depth) return [];

      $result = [];
      foreach ($structure as $node)
      {
         if (!$node['visible_if']) continue;

         $node['args'] = array_merge($args, $node['args'] ?? []);
         $node['is_active'] = $node['active_if'] && in_array($node['route'], $nav_route);
         if (isset($node['children']) && $node['is_active'])
         {
            $node['children'] = static::deriveNavTree($node['children'], $nav_route, $node['args'], $max_depth, $depth + 1);
         }
         else
         {
            unset($node['children']);
         }
         $result[] = $node;
      }
      return $result;
   }
}