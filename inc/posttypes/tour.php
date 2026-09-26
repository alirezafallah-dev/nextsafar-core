<?php

namespace NextSafar\PostTypes;

class Tour {
    const POST_TYPE = 'tour';

    public static function register() {
        $labels = [
            'name'               => 'تور',
            'singular_name'      => 'تور',
            'menu_name'          => 'تور',
            'name_admin_bar'     => 'tour',
            'add_new'            => 'افزودن تور جدید',
            'add_new_item'       => 'افزودن تور جدید',
            'new_item'           => 'تور جدید',
            'edit_item'          => 'ویرایش تور',
            'view_item'          => 'مشاهده تور',
            'all_items'          => 'همه تورها',
            'search_items'       => 'جستجوی تور',
            'parent_item_colon'  => 'تور والد:',
            'not_found'          => 'توری پیدا نشد.',
            'not_found_in_trash' => 'توری در زباله‌دان پیدا نشد.',
            'featured_image'     => 'تصویر شاخص',
            'set_featured_image' => 'تنظیم تصویر شاخص',
            'remove_featured_image'=> 'حذف تصویر شاخص',
            'archives'           => 'آرشیو تورها',
            'item_list'          => 'لیست تورها',
        ];

        $args = [
            'labels'             => $labels,
            'public'             => true,
            'exclude_from_search'=> false,
            'hierarchical'       => false,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_rest'       => true,
            'menu_position'      => 16,
            'menu_icon'          => 'dashicons-palmtree',
            'supports'           => ['title', 'editor', 'author', 'excerpt', 'comments'],
            'has_archive'        => true,
            'rewrite'            => ['slug' => 'tour', 'with_front' => false],
        ];

        register_post_type(self::POST_TYPE, $args);
    }
}