<?php

namespace NextSafar\PostTypes;

class Hotel {
    const POST_TYPE = 'hotel';

    public static function register() {
        $labels = [
            'name'               => 'هتل',
            'singular_name'      => 'هتل',
            'menu_name'          => 'هتل',
            'add_new'            => 'افزودن هتل جدید',
            'add_new_item'       => 'افزودن هتل جدید',
            'edit_item'          => 'ویرایش هتل',
            'view_item'          => 'مشاهده هتل',
            'all_items'          => 'همه هتل‌ها',
            'search_items'       => 'جستجوی هتل',
            'not_found'          => 'هتلی پیدا نشد.',
            'featured_image'     => 'تصویر شاخص',
            'set_featured_image' => 'تنظیم تصویر شاخص',
        ];

        $args = [
            'labels'             => $labels,
            'public'             => true,
            'exclude_from_search'=> false,
            'hierarchical'       => false,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_rest'       => true,
            'menu_position'      => 4,
            'menu_icon'          => 'dashicons-building',
            'supports'           => ['title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments'],
            'has_archive'        => true,
            'rewrite'            => ['slug' => 'hotel', 'with_front' => false],
        ];

        register_post_type(self::POST_TYPE, $args);
    }
}