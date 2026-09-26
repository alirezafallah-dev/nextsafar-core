<?php

namespace NextSafar\API;

class MenuEndpoint {
    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    /* ========================================================================
       Register Routes
       ======================================================================== */
    public static function register_routes() {
        register_rest_route('nextsafar/v1', '/menus/(?P<location>[a-zA-Z0-9_-]+)', [
            'methods'             => 'GET',
            'callback'            => [__CLASS__, 'get_menu'],
            'permission_callback' => '__return_true',
            'args'                => [
                'location' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ]);
    }

    /* ========================================================================
       Get Menu by Location
       ======================================================================== */
    public static function get_menu($request) {
        $location  = $request->get_param('location');
        $locations = get_nav_menu_locations();

        /* If location doesn't exist, return empty array (not WP_Error) */
        if (!isset($locations[$location])) {
            return ['location' => $location, 'items' => []];
        }

        $menu_id    = $locations[$location];
        $menu_items = wp_get_nav_menu_items($menu_id);

        if (!$menu_items || !is_array($menu_items)) {
            return ['location' => $location, 'items' => []];
        }

        /* Build mapping of all nodes */
        $by_id = [];

        foreach ($menu_items as $item) {
            $by_id[(int) $item->ID] = [
                'id'       => (int) $item->ID,
                'parent'   => (int) $item->menu_item_parent,
                'title'    => $item->title,
                'url'      => $item->url,
                'target'   => $item->target ?: '_self',
                'classes'  => is_array($item->classes) ? implode(' ', array_filter($item->classes)) : '',
                'object'   => $item->object,
                'object_id'=> (int) $item->object_id,
                'children' => [],
            ];
        }

        /* Connect children to parents (by reference) */
        foreach ($by_id as $id => &$node) {
            $parent = $node['parent'];

            if ($parent && isset($by_id[$parent])) {
                $by_id[$parent]['children'][] = &$node;
            }
        }

        unset($node); // Clear reference

        /* Extract roots */
        $tree = [];

        foreach ($by_id as $id => $node) {
            if (!$node['parent'] || !isset($by_id[$node['parent']])) {
                $tree[] = $node;
            }
        }

        /* Clean output for JSON (without internal fields) */
        $clean = function ($list) use (&$clean) {
            $out = [];

            foreach ($list as $n) {
                $out[] = [
                    'id'       => $n['id'],
                    'title'    => $n['title'],
                    'url'      => $n['url'],
                    'target'   => $n['target'],
                    'classes'  => $n['classes'],
                    'object'   => $n['object'],
                    'object_id'=> $n['object_id'],
                    'children' => $clean($n['children']),
                ];
            }

            return $out;
        };

        return [
            'location' => $location,
            'items'    => $clean($tree),
        ];
    }
}