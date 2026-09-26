<?php

namespace NextSafar\PostTypes;

class Airport {
    const POST_TYPE = 'airport';

    public static function register() {
        $labels = [
            'name'               => 'فرودگاه',
            'singular_name'      => 'فرودگاه',
            'menu_name'          => 'فرودگاه',
            'name_admin_bar'     => 'airport',
            'add_new'            => 'افزودن فرودگاه جدید',
            'add_new_item'       => 'افزودن فرودگاه جدید',
            'new_item'           => 'فرودگاه جدید',
            'edit_item'          => 'ویرایش فرودگاه',
            'view_item'          => 'مشاهده فرودگاه',
            'all_items'          => 'همه فرودگاه‌ها',
            'search_items'       => 'جستجوی فرودگاه',
            'parent_item_colon'  => 'فرودگاه والد:',
            'not_found'          => 'فرودگاهی پیدا نشد.',
            'not_found_in_trash' => 'فرودگاهی در زباله‌دان پیدا نشد.',
            'featured_image'     => 'تصویر شاخص',
            'set_featured_image' => 'تنظیم تصویر شاخص',
            'remove_featured_image'=> 'حذف تصویر شاخص',
            'use_featured_image' => 'استفاده به عنوان تصویر شاخص',
            'archives'           => 'آرشیو فرودگاه‌ها',
            'insert_into_item'   => 'افزودن به فرودگاه',
            'uploaded_to_this_item'=> 'آپلود شده برای این فرودگاه',
            'item_list_navigation'=> 'پیمایش لیست',
            'item_list'          => 'لیست فرودگاه‌ها',
        ];

        $args = [
            'labels'             => $labels,
            'public'             => true,
            'exclude_from_search'=> false,
            'hierarchical'       => false,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_rest'       => true,
            'menu_position'      => 14,
            'menu_icon'          => 'dashicons-airplane',
            'supports'           => ['title', 'editor', 'author', 'thumbnail', 'excerpt', 'comments'],
            'has_archive'        => true,
            'rewrite'            => ['slug' => 'airport', 'with_front' => false],
        ];

        register_post_type(self::POST_TYPE, $args);
    }
}