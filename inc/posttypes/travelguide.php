<?php

namespace NextSafar\PostTypes;

class TravelGuide {
    const POST_TYPE = 'travelguide';

    public static function register() {
        $labels = [
            'name'               => 'راهنمای سفر',
            'singular_name'      => 'راهنمای سفر',
            'menu_name'          => 'راهنمای سفر',
            'name_admin_bar'     => 'travelguide',
            'add_new'            => 'افزودن راهنمای سفر جدید',
            'add_new_item'       => 'افزودن راهنمای سفر جدید',
            'new_item'           => 'راهنمای سفر جدید',
            'edit_item'          => 'ویرایش راهنمای سفر',
            'view_item'          => 'مشاهده راهنمای سفر',
            'all_items'          => 'همه راهنمای سفرها',
            'search_items'       => 'جستجوی راهنمای سفر',
            'parent_item_colon'  => 'راهنمای سفر والد:',
            'not_found'          => 'راهنمای سفری پیدا نشد.',
            'not_found_in_trash' => 'راهنمای سفری در زباله‌دان پیدا نشد.',
            'featured_image'     => 'تصویر شاخص',
            'set_featured_image' => 'تنظیم تصویر شاخص',
            'remove_featured_image'=> 'حذف تصویر شاخص',
            'use_featured_image' => 'استفاده به عنوان تصویر شاخص',
            'archives'           => 'آرشیو راهنمای سفرها',
            'item_list'          => 'لیست راهنمای سفرها',
        ];

        $args = [
            'labels'             => $labels,
            'public'             => true,
            'exclude_from_search'=> false,
            'hierarchical'       => false,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_rest'       => true,
            'menu_position'      => 6,
            'menu_icon'          => 'dashicons-flag',
            'supports'           => ['title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments'],
            'has_archive'        => true,
            'rewrite'            => ['slug' => 'travelguide', 'with_front' => false],
        ];

        register_post_type(self::POST_TYPE, $args);
    }
}