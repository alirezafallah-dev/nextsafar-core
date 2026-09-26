<?php

namespace NextSafar\PostTypes;

class Restaurant {
    const POST_TYPE = 'restaurant';

    public static function register() {
        $labels = [
            'name'               => 'رستوران',
            'singular_name'      => 'رستوران',
            'menu_name'          => 'رستوران',
            'name_admin_bar'     => 'restaurant',
            'add_new'            => 'افزودن رستوران جدید',
            'add_new_item'       => 'افزودن رستوران جدید',
            'new_item'           => 'رستوران جدید',
            'edit_item'          => 'ویرایش رستوران',
            'view_item'          => 'مشاهده رستوران',
            'all_items'          => 'همه رستوران‌ها',
            'search_items'       => 'جستجوی رستوران',
            'not_found'          => 'رستورانی پیدا نشد.',
            'not_found_in_trash' => 'رستورانی در زباله‌دان پیدا نشد.',
            'featured_image'     => 'تصویر شاخص',
            'set_featured_image' => 'تنظیم تصویر شاخص',
            'remove_featured_image'=> 'حذف تصویر شاخص',
            'archives'           => 'آرشیو رستوران‌ها',
            'item_list'          => 'لیست رستوران‌ها',
        ];

        $args = [
            'labels'             => $labels,
            'public'             => true,
            'exclude_from_search'=> false,
            'hierarchical'       => false,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_rest'       => true,
            'menu_position'      => 10,
            'menu_icon'          => 'dashicons-food',
            'supports'           => ['title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments'],
            'has_archive'        => true,
            'rewrite'            => ['slug' => 'restaurant', 'with_front' => false],
        ];

        register_post_type(self::POST_TYPE, $args);
    }
}