<?php

namespace NextSafar\PostTypes;

class Hospital {
    const POST_TYPE = 'hospital';

    public static function register() {
        $labels = [
            'name'               => 'بیمارستان',
            'singular_name'      => 'بیمارستان',
            'menu_name'          => 'بیمارستان',
            'name_admin_bar'     => 'hospital',
            'add_new'            => 'افزودن بیمارستان جدید',
            'add_new_item'       => 'افزودن بیمارستان جدید',
            'new_item'           => 'بیمارستان جدید',
            'edit_item'          => 'ویرایش بیمارستان',
            'view_item'          => 'مشاهده بیمارستان',
            'all_items'          => 'همه بیمارستان‌ها',
            'search_items'       => 'جستجوی بیمارستان',
            'not_found'          => 'بیمارستانی پیدا نشد.',
            'not_found_in_trash' => 'بیمارستانی در زباله‌دان پیدا نشد.',
            'featured_image'     => 'تصویر شاخص',
            'set_featured_image' => 'تنظیم تصویر شاخص',
            'remove_featured_image'=> 'حذف تصویر شاخص',
            'archives'           => 'آرشیو بیمارستان‌ها',
            'item_list'          => 'لیست بیمارستان‌ها',
        ];

        $args = [
            'labels'             => $labels,
            'public'             => true,
            'exclude_from_search'=> false,
            'hierarchical'       => false,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_rest'       => true,
            'menu_position'      => 11,
            'menu_icon'          => 'dashicons-heart',
            'supports'           => ['title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments'],
            'has_archive'        => true,
            'rewrite'            => ['slug' => 'hospital', 'with_front' => false],
        ];

        register_post_type(self::POST_TYPE, $args);
    }
}