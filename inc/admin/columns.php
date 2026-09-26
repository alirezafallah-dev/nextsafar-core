<?php

namespace NextSafar\Admin;

class Columns {
    public static function init() {
        // Custom columns.
        add_filter('manage_hotel_posts_columns', [__CLASS__, 'add_columns']);
        add_action('manage_hotel_posts_custom_column', [__CLASS__, 'render_column'], 10, 2);

        // Sorting.
        add_filter('manage_edit-hotel_sortable_columns', [__CLASS__, 'sortable_columns']);
        add_action('pre_get_posts', [__CLASS__, 'orderby_handler']);

        // Filters.
        add_action('restrict_manage_posts', [__CLASS__, 'add_filters']);
        add_filter('parse_query', [__CLASS__, 'filter_handler']);
    }

    public static function add_columns($columns) {
        $new_columns = [];

        $new_columns['cb'] = $columns['cb'];
        $new_columns['thumbnail'] = __('تصویر', 'nextsafar');
        $new_columns['title'] = $columns['title'];
        $new_columns['stars'] = __('ستاره', 'nextsafar');
        $new_columns['rating'] = __('امتیاز', 'nextsafar');
        $new_columns['data_source'] = __('منبع داده', 'nextsafar');
        $new_columns['amenities_count'] = __('امکانات', 'nextsafar');
        $new_columns['date'] = $columns['date'];

        return $new_columns;
    }

    public static function render_column($column, $post_id) {
        switch ($column) {
            case 'thumbnail':
                if (has_post_thumbnail($post_id)) {
                    echo get_the_post_thumbnail($post_id, [50, 50], ['class' => 'ns-hotel-thumb']);
                } else {
                    echo '<span class="ns-no-thumb">🏨</span>';
                }
                break;

            case 'stars':
                $stars = get_post_meta($post_id, '_hotel_stars', true);

                if ($stars) {
                    echo self::render_stars($stars);
                } else {
                    echo '<span class="ns-muted">—</span>';
                }
                break;

            case 'rating':
                $rating = get_post_meta($post_id, '_hotel_rating', true);

                if ($rating) {
                    echo self::render_rating($rating);
                } else {
                    echo '<span class="ns-muted">—</span>';
                }
                break;

            case 'data_source':
                $source = get_post_meta($post_id, '_hotel_data_source', true);
                echo self::render_source_badge($source);
                break;

            case 'amenities_count':
                $count = self::count_amenities($post_id);
                echo '<span class="ns-amenity-count">' . $count . '</span>';
                break;
        }
    }

    private static function render_stars($count) {
        $count = intval($count);

        $html = '<span class="ns-stars">';

        for ($i = 1; $i <= 5; $i++) {
            $html .= $i <= $count ? '⭐' : '<span class="ns-star-empty">☆</span>';
        }

        $html .= '</span>';

        return $html;
    }

    private static function render_rating($rating) {
        $rating = floatval($rating);
        $color = self::get_rating_color($rating);

        return '<span class="ns-rating" style="background:' . $color . '; color:#fff; padding:2px 8px; border-radius:4px; font-weight:bold;">'
            . number_format($rating, 1) . '</span>';
    }

    private static function get_rating_color($rating) {
        if ($rating >= 4.5) return '#2e7d32'; // Excellent green
        if ($rating >= 3.5) return '#689f38'; // Good green
        if ($rating >= 2.5) return '#f9a825'; // Average yellow

        return '#c62828'; // Poor red
    }

    private static function render_source_badge($source) {
        $badges = [
            'serpapi'    => ['label' => 'SerpApi', 'color' => '#4285f4'],
            'searchapi'  => ['label' => 'SearchApi', 'color' => '#34a853'],
            'dataforseo' => ['label' => 'DataForSEO', 'color' => '#fbbc05'],
            'manual'     => ['label' => 'دستی', 'color' => '#9e9e9e'],
        ];

        $badge = $badges[$source] ?? $badges['manual'];

        return '<span class="ns-source-badge" style="background:' . $badge['color'] . '; color:#fff; padding:2px 8px; border-radius:10px; font-size:11px;">'
            . $badge['label'] . '</span>';
    }

    private static function count_amenities($post_id) {
        $all_keys = \NextSafar\MetaBoxes\HotelMetaBox::get_all_amenity_keys();
        $count = 0;

        foreach ($all_keys as $key) {
            if (get_post_meta($post_id, '_' . $key, true) === 'yes') {
                $count++;
            }
        }

        return $count;
    }

    public static function sortable_columns($columns) {
        $columns['stars'] = 'stars';
        $columns['rating'] = 'rating';
        $columns['amenities_count'] = 'amenities_count';

        return $columns;
    }

    public static function orderby_handler($query) {
        if (!is_admin() || !$query->is_main_query()) return;

        $orderby = $query->get('orderby');

        if ($orderby === 'stars') {
            $query->set('meta_key', '_hotel_stars');
            $query->set('orderby', 'meta_value_num');
        } elseif ($orderby === 'rating') {
            $query->set('meta_key', '_hotel_rating');
            $query->set('orderby', 'meta_value_num');
        }
    }

    public static function add_filters() {
        global $typenow, $pagenow;

        if ($typenow !== 'hotel') return;

        //_filter_stars();
        self::filter_source();
    }

    private static function filter_stars() {
        $current = isset($_GET['stars_filter']) ? $_GET['stars_filter'] : '';
        ?>
        <select class="alignleft actions">
        <select name="stars_filter" name="stars_filter">
            <option value="">همه ستاره‌ها</option>
            <?php for ($i = 1; $i <= 5; $i++) : ?>
                <option value="<?= $i; ?>" <?= selected($current, (string)$i, false); ?>>
                    <?= $i; ?> ستاره
                </option>
            <?php endfor; ?>
        </select>
        </label>
        <?php
    }

    private static function filter_source() {
        $current = isset($_GET['source_filter']) ? $_GET['source_filter'] : '';

        $sources = [
            'serpapi'    => 'SerpApi',
            'searchapi'  => 'SearchApi',
            'dataforseo' => 'DataForSEO',
            'manual'     => 'دستی',
        ];
        ?>
        <select name="source_filter" class="alignleft actions" style="margin-left: 6px;">
            <option value="">همه منابع</option>
            <?php foreach ($sources as $key => $label) : ?>
                <option value="<?= esc_attr($key); ?>" <?= selected($current, $key, false); ?>>
                    <?= esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    public static function filter_handler($query) {
        global $pagenow, $typenow;

        if ($typenow !== 'hotel' || $pagenow !== 'edit.php') return;

        if (!empty($_GET['stars_filter'])) {
            $query->query_vars['meta_query'][] = [
                'key'     => '_hotel_stars',
                'value'     => intval($_GET['stars_filter']),
                'compare' => '=',
            ];
        }

        if (!empty($_GET['source_filter'])) {
            $query->query_vars['meta_query'][] = [
                'key'     => '_hotel_data_source',
                'value'     => sanitize_text_field($_GET['source_filter']),
                'compare' => '=',
            ];
        }
    }
}

// Admin styles.
add_action('admin_head', function() {
    global $typenow;

    if ($typenow !== 'hotel') return;
    ?>
    <style>
        .ns-hotel-thumb {
            border-radius: 4px;
            object-fit: cover;
        }

        .ns-no-thumb {
            display: inline-flex;
            width: 50px;
            height: 50px;
            align-items: center;
            justify-content: center;
            background: #f0f0f1;
            border-radius: 4px;
            font-size: 24px;
        }

        .ns-stars {
            font-size: 14px;
            letter-spacing: 2px;
        }

        .ns-star-empty {
            color: #ddd;
        }

        .ns-muted {
            color: #999;
        }

        .ns-amenity-count {
            display: inline-block;
            padding: 2px 10px;
            background: #2271b1;
            color: #fff;
            border-radius: 10px;
            font-weight: bold;
        }

        .column-thumbnail {
            width: 80px;
        }

        .column-stars,
        .column-rating,
        .column-amenities_count {
            width: 120px;
        }

        .column-data_source {
            width: 130px;
        }
    </style>
    <?php
});