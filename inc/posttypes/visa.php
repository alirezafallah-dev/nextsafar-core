<?php

namespace NextSafar\PostTypes;

class Visa {
    const POST_TYPE = 'visa';

    public static function register() {
        $labels = [
            'name'               => 'ویزا',
            'singular_name'      => 'ویزا',
            'menu_name'          => 'ویزا',
            'name_admin_bar'     => 'visa',
            'add_new'            => 'افزودن ویزا جدید',
            'add_new_item'       => 'افزودن ویزا جدید',
            'new_item'           => 'ویزا جدید',
            'edit_item'          => 'ویرایش ویزا',
            'view_item'          => 'مشاهده ویزا',
            'all_items'          => 'همه ویزاها',
            'search_items'       => 'جستجوی ویزا',
            'parent_item_colon'  => 'ویزا والد:',
            'not_found'          => 'ویزایی پیدا نشد.',
            'not_found_in_trash' => 'ویزایی در زباله‌دان پیدا نشد.',
            'featured_image'     => 'تصویر شاخص',
            'set_featured_image' => 'تنظیم تصویر شاخص',
            'remove_featured_image'=> 'حذف تصویر شاخص',
            'use_featured_image' => 'استفاده به عنوان تصویر شاخص',
            'archives'           => 'آرشیو ویزاها',
            'item_list'          => 'لیست ویزاها',
        ];

        $args = [
            'labels'             => $labels,
            'public'             => true,
            'exclude_from_search'=> false,
            'hierarchical'       => false,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_rest'       => true,
            'menu_position'      => 15,
            'menu_icon'          => 'dashicons-text-page',
            'supports'           => ['title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments'],
            'has_archive'        => true,
            'rewrite'            => ['slug' => 'visa', 'with_front' => false],
        ];

        register_post_type(self::POST_TYPE, $args);
    }
}