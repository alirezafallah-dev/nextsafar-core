<?php

namespace NextSafar\PostTypes;

class Destination {
    const POST_TYPE = 'destination';

    public static function register() {
        $labels = [
            'name'               => 'مقاصد گردشگری',
            'singular_name'      => 'مقصد گردشگری',
            'menu_name'          => 'مقاصد گردشگری',
            'name_admin_bar'     => 'destination',
            'add_new'            => 'افزودن مقصد جدید',
            'add_new_item'       => 'افزودن مقصد جدید',
            'new_item'           => 'مقصد جدید',
            'edit_item'          => 'ویرایش مقصد',
            'view_item'          => 'مشاهده مقصد',
            'all_items'          => 'همه مقاصد',
            'search_items'       => 'جستجوی مقصد',
            'parent_item_colon'  => 'مقصد والد:',
            'not_found'          => 'مقصدی پیدا نشد.',
            'not_found_in_trash' => 'مقصدی در زباله‌دان پیدا نشد.',
            'featured_image'     => 'تصویر شاخص',
            'set_featured_image' => 'تنظیم تصویر شاخص',
            'remove_featured_image'=> 'حذف تصویر شاخص',
            'use_featured_image' => 'استفاده به عنوان تصویر شاخص',
            'archives'           => 'آرشیو مقاصد',
            'insert_into_item'   => 'افزودن به مقصد',
            'uploaded_to_this_item'=> 'آپلود شده برای این مقصد',
            'item_list_navigation'=> 'پیمایش لیست',
            'item_list'          => 'لیست مقاصد',
        ];

        $args = [
            'labels'             => $labels,
            'public'             => true,
            'exclude_from_search'=> false,
            'hierarchical'       => false,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_rest'       => true,
            'menu_position'      => 5,
            'menu_icon'          => 'dashicons-location',
            'supports'           => ['title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments'],
            'has_archive'        => true,
            'rewrite'            => ['slug' => 'destination', 'with_front' => false],
        ];

        register_post_type(self::POST_TYPE, $args);
    }
}