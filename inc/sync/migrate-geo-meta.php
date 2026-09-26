<?php

namespace NextSafar\Sync;

if (!defined('ABSPATH')) exit;

/**
 * Migrate/Clean Up: Legacy Keys → _geo_* and Remove Legacy Keys
 */
class MigrateGeoMeta {

    /**
     * Run the migration.
     */
    public static function run(): array {
        $stats = ['posts' => 0, 'copied' => 0, 'deleted' => 0, 'coords' => 0];

        foreach (GeoSchema::GEO_POST_TYPES as $type) {
            $ids = get_posts([
                'post_type'   => $type,
                'post_status' => 'any',
                'numberposts' => -1,
                'fields'      => 'ids',
            ]);

            foreach ($ids as $pid) {
                $stats['posts']++;

                /* Copy legacy → new (if new is empty) + delete legacy */
                foreach (GeoSchema::LEGACY_MAP[$type] as $old => $field) {
                    $v = get_post_meta($pid, $old, true);

                    if ($v !== '' && $v !== null) {
                        if (GeoSchema::get($pid, $field) === '') {
                            GeoSchema::set($pid, $field, $v);
                            $stats['copied']++;
                        }
                    }

                    if (delete_post_meta($pid, $old)) $stats['deleted']++;
                }

                /* Coordinates → _geo_lat/_geo_lng */
                $coords = get_post_meta($pid, '_location_coords', true);

                if ($coords !== '' && $coords !== null) {
                    if (GeoSchema::get($pid, 'lat') === '') {
                        $p = array_map('trim', explode(',', $coords));

                        if (count($p) >= 2 && $p[0] !== '') {
                            GeoSchema::set_latlng($pid, $p[0], $p[1]);
                            $stats['coords']++;
                        }
                    }

                    delete_post_meta($pid, '_location_coords');
                    $stats['deleted']++;
                }

                if (GeoSchema::get($pid, 'lat') === '') {
                    $gmc = get_post_meta($pid, '_google_map_coords', true);

                    if ($gmc) {
                        $p = array_map('trim', explode(',', $gmc));

                        if (count($p) >= 2) GeoSchema::set_latlng($pid, $p[0], $p[1]);
                    }
                }

                delete_post_meta($pid, '_google_map_coords');

                /* Legacy *_source keys */
                foreach (array_keys(GeoSchema::LEGACY_MAP[$type]) as $old) {
                    if (delete_post_meta($pid, $old . '_source')) $stats['deleted']++;
                }

                delete_post_meta($pid, '_location_coords_source');
            }
        }

        update_option('ns_geo_migrated_v2', current_time('mysql'));
        update_option('ns_geo_migrate_stats', $stats);

        return $stats;
    }
}

/* WP-CLI: wp nextsafar migrate-geo */
if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('nextsafar migrate-geo', function () {
        $s = MigrateGeoMeta::run();

        \WP_CLI::success(sprintf(
            'posts=%d copied=%d deleted=%d coords=%d',
            $s['posts'],
            $s['copied'],
            $s['deleted'],
            $s['coords']
        ));
    });
}

/* Admin execution + notice */
add_action('admin_post_ns_migrate_geo', function () {
    if (!current_user_can('manage_options')) wp_die('دسترسی غیرمجاز');

    check_admin_referer('ns_migrate_geo');

    MigrateGeoMeta::run();

    wp_redirect(add_query_arg('ns_migrated', 1, wp_get_referer() ?: admin_url()));
    exit;
});

add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;

    if (isset($_GET['ns_migrated'])) {
        $s = get_option('ns_geo_migrate_stats', []);

        echo '<div class="notice notice-success"><p>✅ پاکسازی: '
            . esc_html(sprintf(
                '%d پست، %d کپی، %d حذف، %d مختصات',
                $s['posts'] ?? 0,
                $s['copied'] ?? 0,
                $s['deleted'] ?? 0,
                $s['coords'] ?? 0
            ))
            . '</p></div>';

        return;
    }

    global $wpdb;

    $left = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key IN ('_location_coords','_hotel_name_en','_airport_name_en','_destination_name_en','_restaurant_name_en','_hospital_name_en','_airport_city','_destination_wikipedia_url')"
    );

    if ($left > 0) {
        $url = wp_nonce_url(admin_url('admin-post.php?action=ns_migrate_geo'), 'ns_migrate_geo');

        echo '<div class="notice notice-warning"><p>🔄 ' . esc_html($left) . ' متای قدیمی باقی مانده. <a href="' . esc_url($url) . '" class="button button-primary">پاکسازی و مهاجرت</a></p></div>';
    }
});