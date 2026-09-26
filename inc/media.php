<?php

/**
 * Image Management: Sizing is only Next.js's responsibility
 * WordPress only keeps the full version (with its own 2560px ceiling)
 */

if (!defined('ABSPATH')) exit;

/* Remove all intermediate sizes for new uploads */
add_filter('intermediate_image_sizes_advanced', '__return_empty_array');

/* Keep 2560 pixel ceiling for giant images (WordPress default, explicitly maintained) */
add_filter('big_image_size_threshold', function () {
    return 2560;
});

/* Slightly higher quality for full version (WordPress default is 82) */
add_filter('wp_editor_set_quality', function () {
    return 90;
});