<?php

/* ==========================================================================
   NextSafar — Lead Registration Endpoint (Price Alert / Newsletter)
   Simple storage in wp_options with duplicate removal + limit
   ========================================================================== */

add_action('rest_api_init', function () {
    register_rest_route('nextsafar/v1', '/leads', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => function (WP_REST_Request $req) {
            $contact = sanitize_text_field((string) $req->get_param('contact'));
            $type    = in_array($req->get_param('type'), ['email', 'mobile'], true)
                ? $req->get_param('type') : 'email';
            $source  = sanitize_text_field((string) ($req->get_param('source') ?? 'general'));

            if ($contact === '') {
                return new WP_REST_Response(['ok' => false, 'message' => 'مقدار نامعتبر'], 422);
            }

            $leads = get_option('ns_leads', []);

            if (!is_array($leads)) $leads = [];

            /* Remove duplicates */
            foreach ($leads as $l) {
                if (($l['contact'] ?? '') === $contact) {
                    return new WP_REST_Response(['ok' => true, 'duplicate' => true], 200);
                }
            }

            $leads[] = [
                'contact' => $contact,
                'type'    => $type,
                'source'  => $source,
                'date'    => current_time('mysql'),
            ];

            /* Limit of 5000 records */
            if (count($leads) > 5000) {
                $leads = array_slice($leads, -5000);
            }

            update_option('ns_leads', $leads, false);

            return new WP_REST_Response(['ok' => true], 200);
        },
    ]);
});