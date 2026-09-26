<?php

namespace NextSafar\Account;

/**
 * Hotel Favorites - Stored in user meta.
 * Dedicated route to avoid conflicting with the legacy favorites system.
 */
add_action('rest_api_init', function () {
    register_rest_route('nextsafar/v1', '/account/hotel-favorites', [
        [
            'methods'             => 'GET',
            'permission_callback' => function () {
                return is_user_logged_in();
            },
            'callback'            => function () {
                $items = get_user_meta(get_current_user_id(), 'ns_fav_hotels', true);

                return rest_ensure_response([
                    'ok'    => true,
                    'items' => is_array($items) ? $items : [],
                ]);
            },
        ],
        [
            'methods'             => 'POST',
            'permission_callback' => function () {
                return is_user_logged_in();
            },
            'callback'            => function (\WP_REST_Request $req) {
                $items = $req->get_param('items');

                if (!is_array($items)) {
                    return new \WP_Error('bad_request', 'داده نامعتبر', ['status' => 400]);
                }

                $clean = [];

                foreach (array_slice($items, 0, 200) as $it) {
                    $key = sanitize_text_field($it['key'] ?? '');

                    if ($key === '') continue;

                    $clean[] = [
                        'key'    => $key,
                        'source' => sanitize_text_field($it['source'] ?? ''),
                        'title'  => sanitize_text_field($it['title'] ?? ''),
                        'image'  => esc_url_raw($it['image'] ?? ''),
                        'url'    => sanitize_text_field($it['url'] ?? ''),
                        'ts'     => intval($it['ts'] ?? 0),
                    ];
                }

                update_user_meta(get_current_user_id(), 'ns_fav_hotels', $clean);

                return rest_ensure_response(['ok' => true, 'count' => count($clean)]);
            },
        ],
    ]);
});