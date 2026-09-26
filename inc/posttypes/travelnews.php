<?php

namespace NextSafar\PostTypes;

class TravelNews {
    const POST_TYPE = 'travelnews';

    public static function register() {
        $labels = [
            'name'               => 'اخبار گردشگری',
            'singular_name'      => 'اخبار گردشگری',
            'menu_name'          => 'اخبار گردشگری',
            'name_admin_bar'     => 'travelnews',
            'add_new'            => 'افزودن خبر جدید',
            'add_new_item'       => 'افزودن خبر جدید',
            'new_item'           => 'خبر جدید',
            'edit_item'          => 'ویرایش خبر',
            'view_item'          => 'مشاهده خبر',
            'all_items'          => 'همه اخبار',
            'search_items'       => 'جستجوی خبر',
            'not_found'          => 'خبری پیدا نشد.',
            'not_found_in_trash' => 'خبری در زباله‌دان پیدا نشد.',
            'featured_image'     => 'تصویر شاخص',
            'set_featured_image' => 'تنظیم تصویر شاخص',
            'remove_featured_image'=> 'حذف تصویر شاخص',
            'archives'           => 'آرشیو اخبار',
            'item_list'          => 'لیست اخبار',
        ];

        $args = [
            'labels'             => $labels,
            'public'             => true,
            'exclude_from_search'=> false,
            'hierarchical'       => false,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_rest'       => true,
            'menu_position'      => 8,
            'menu_icon'          => 'dashicons-admin-site-alt3',
            'supports'           => ['title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments'],
            'has_archive'        => true,
            'rewrite'            => ['slug' => 'travelnews', 'with_front' => false],
        ];

        register_post_type(self::POST_TYPE, $args);
    }
}