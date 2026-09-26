<?php

namespace NextSafar\Metaboxes;

if (!defined('ABSPATH')) exit;

/**
 * Metabox: Featured Hotel
 * Admin can mark hotels as "NextSafar Featured"
 */
class HotelFeaturedMeta {
    public static function init() {
        add_action('add_meta_boxes', [__CLASS__, 'add_meta_box']);
        add_action('save_post_hotel', [__CLASS__, 'save']);

        /* Column in hotel list */
        add_filter('manage_hotel_posts_columns', [__CLASS__, 'add_column']);
        add_action('manage_hotel_posts_custom_column', [__CLASS__, 'render_column'], 10, 2);

        /* REST API */
        add_action('rest_api_init', [__CLASS__, 'register_rest_field']);
    }

    public static function add_meta_box() {
        add_meta_box(
            'hotel_featured_box',
            'هتل برگزیده',
            [__CLASS__, 'render'],
            'hotel',
            'side',
            'high'
        );
    }

    public static function render($post) {
        wp_nonce_field('hotel_featured_nonce', 'hotel_featured_nonce_field');

        $featured = get_post_meta($post->ID, '_hotel_featured', true);
        ?>
        <div style="padding: 10px 0;">
            <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                <input
                    type="checkbox"
                    name="_hotel_featured"
                    value="1"
                    <?php checked($featured, '1'); ?>
                    style="width: 18px; height: 18px;"
                >
                <span style="font-weight: 600;">
                    نمایش در بخش «هتل‌های برگزیده»
                </span>
            </label>
            <p style="color: #646970; font-size: 12px; margin-top: 8px; line-height: 1.6;">
                هتل‌های علامت‌گذاری‌شده در صفحه اصلی به‌عنوان «منتخب سفر بعدی» نمایش داده می‌شوند.
            </p>
        </div>
        <?php
    }

    public static function save($post_id) {
        if (!isset($_POST['hotel_featured_nonce_field']) ||
            !wp_verify_nonce($_POST['hotel_featured_nonce_field'], 'hotel_featured_nonce')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

        if (!current_user_can('edit_post', $post_id)) return;

        $featured = isset($_POST['_hotel_featured']) ? '1' : '';

        if ($featured) {
            update_post_meta($post_id, '_hotel_featured', '1');
        } else {
            delete_post_meta($post_id, '_hotel_featured');
        }
    }

    /* Column in hotel list */
    public static function add_column($columns) {
        $new = [];

        foreach ($columns as $key => $val) {
            $new[$key] = $val;

            if ($key === 'title') {
                $new['featured'] = 'برگزیده';
            }
        }

        return $new;
    }

    public static function render_column($column, $post_id) {
        if ($column === 'featured') {
            $featured = get_post_meta($post_id, '_hotel_featured', true);

            if ($featured === '1') {
                echo '<span style="color: #f59e0b; font-size: 18px;">⭐</span>';
            } else {
                echo '—';
            }
        }
    }

    /* REST API */
    public static function register_rest_field() {
        register_rest_field('hotel', 'featured', [
            'get_callback' => function ($post) {
                return get_post_meta($post['id'], '_hotel_featured', true) === '1';
            },
            'schema' => ['type' => 'boolean'],
        ]);
    }
}