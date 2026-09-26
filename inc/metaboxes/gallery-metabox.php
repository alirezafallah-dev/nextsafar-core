<?php

namespace NextSafar\MetaBoxes;

/**
 * Generic Gallery MetaBox for all post types
 * With ImageManager support (manual/API lock)
 */
class GalleryMetaBox {
    /**
     * Post types that have a gallery
     */
    public static function get_supported_post_types() {
        return apply_filters('nextsafar_gallery_post_types', [
            'hotel', 'destination', 'flight', 'restaurant', 'tour',
            'visa', 'airport', 'hospital', 'travelnews', 'post',
        ]);
    }

    public static function register() {
        foreach (self::get_supported_post_types() as $pt) {
            add_meta_box(
                'nextsafar_entity_gallery',
                '🖼️ گالری تصاویر',
                [__CLASS__, 'render'],
                $pt,
                'normal',
                'high'
            );
        }
    }

    public static function register_hooks() {
        // Save
        add_action('save_post', [__CLASS__, 'save'], 10, 2);

        // Styles and scripts
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        // Migrate legacy data (once)
        add_action('admin_init', [__CLASS__, 'maybe_migrate_legacy']);
    }

    public static function enqueue_assets($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'])) return;

        $screen = get_current_screen();

        if (!$screen || !in_array($screen->post_type, self::get_supported_post_types())) {
            return;
        }

        wp_enqueue_media();

        wp_enqueue_style(
            'nextsafar-gallery',
            NEXTSAFAR_URL . 'assets/gallery.css',
            [],
            NEXTSAFAR_VERSION
        );

        wp_enqueue_script(
            'nextsafar-gallery',
            NEXTSAFAR_URL . 'assets/gallery.js',
            ['jquery', 'jquery-ui-sortable'],
            NEXTSAFAR_VERSION,
            true
        );

        wp_localize_script('nextsafar-gallery', 'NextSafarGallery', [
            'media_title'  => __('انتخاب تصاویر گالری', 'nextsafar'),
            'media_button' => __('افزودن به گالری', 'nextsafar'),
            'confirm_del'  => __('مطمئنی؟', 'nextsafar'),
        ]);
    }

    public static function render($post) {
        wp_nonce_field('nextsafar_gallery', 'nextsafar_gallery_nonce');

        require NEXTSAFAR_PATH . 'views/metaboxes/entity-gallery.php';
    }

    public static function save($post_id, $post) {
        // Only for our post types
        if (!in_array($post->post_type, self::get_supported_post_types())) return;

        if (!isset($_POST['nextsafar_gallery_nonce']) ||
            !wp_verify_nonce($_POST['nextsafar_gallery_nonce'], 'nextsafar_gallery')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        /* ===== Main gallery ===== */
        $gallery_ids = [];

        if (!empty($_POST['nextsafar_gallery_ids'])) {
            $raw = sanitize_text_field($_POST['nextsafar_gallery_ids']);

            $gallery_ids = array_values(array_filter(
                array_map('intval', explode(',', $raw))
            ));
        }

        update_post_meta($post_id, '_entity_gallery', $gallery_ids);

        /* ===== Gallery source (manual vs api) ===== */
        $touched = !empty($_POST['nextsafar_gallery_touched'])
            && $_POST['nextsafar_gallery_touched'] === '1';

        if ($touched) {
            if (!empty($gallery_ids)) {
                update_post_meta($post_id, '_entity_gallery_source', 'manual');
            } else {
                delete_post_meta($post_id, '_entity_gallery_source');
            }
        }

        /* ===== Featured image source ===== */
        $featured_touched = !empty($_POST['nextsafar_featured_touched'])
            && $_POST['nextsafar_featured_touched'] === '1';

        if ($featured_touched) {
            if (has_post_thumbnail($post_id)) {
                update_post_meta($post_id, '_featured_image_source', 'manual');
            } else {
                delete_post_meta($post_id, '_featured_image_source');
            }
        }
    }

    /**
     * Automatic migration from _posttype_gallery (legacy file) to _entity_gallery
     * Runs only once
     */
    public static function maybe_migrate_legacy() {
        if (get_option('nextsafar_gallery_migrated_v1')) return;

        global $wpdb;

        $legacy_rows = $wpdb->get_results("
            SELECT post_id, meta_value
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_posttype_gallery'
            AND meta_value != ''
            LIMIT 500
        ");

        foreach ($legacy_rows as $row) {
            $ids = array_values(array_filter(array_map('intval', explode(',', $row->meta_value))));

            if (!empty($ids)) {
                // Only migrate if _entity_gallery is empty
                $existing = get_post_meta($row->post_id, '_entity_gallery', true);

                if (empty($existing)) {
                    update_post_meta($row->post_id, '_entity_gallery', $ids);
                    update_post_meta($row->post_id, '_entity_gallery_source', 'manual');
                }
            }
        }

        update_option('nextsafar_gallery_migrated_v1', time());
    }

    /**
     * Helper for getting the gallery on the frontend
     */
    public static function get_gallery($post_id) {
        $ids = get_post_meta($post_id, '_entity_gallery', true);

        if (!is_array($ids)) return [];

        return array_filter(array_map(function ($id) {
            $url = wp_get_attachment_image_url($id, 'large');
            $thumb = wp_get_attachment_image_url($id, 'thumbnail');
            $alt = get_post_meta($id, '_wp_attachment_image_alt', true);

            return $url ? [
                'id'    => $id,
                'url'   => $url,
                'thumb' => $thumb ?: $url,
                'alt'   => $alt ?: get_the_title($id),
            ] : null;
        }, $ids));
    }
}