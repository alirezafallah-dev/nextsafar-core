<?php

namespace NextSafar\Taxonomies;

class Tourism {
    const TAXONOMY = 'tourism';

    public static function register() {
        $labels = [
            'name'              => 'شهرهای توریستی',
            'singular_name'     => 'شهر توریستی',
            'search_items'      => 'جستجوی شهر',
            'all_items'         => 'همه شهرها',
            'parent_item'       => 'کشور والد:',
            'parent_item_colon' => 'کشور والد:',
            'edit_item'         => 'ویرایش شهر',
            'update_item'       => 'به‌روزرسانی شهر',
            'add_new_item'      => 'افزودن شهر جدید',
            'new_item_name'     => 'نام شهر جدید',
            'menu_name'         => 'شهرهای توریستی',
            'not_found'         => 'شهری پیدا نشد',
        ];

        register_taxonomy(self::TAXONOMY, self::get_post_types(), [
            'labels'            => $labels,
            'hierarchical'      => true,
            'public'            => true,
            'show_ui'           => true,
            'show_in_menu'      => true,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => [
                'slug'         => 'tourism',
                'with_front'   => false,
                'hierarchical' => true,
            ],
        ]);
    }

    public static function get_post_types() {
        return [
            'hotel',
            'restaurant',
            'airport',
            'hospital',
            'destination',
            'tour',
            'travelguide',
            'post',
            'visa',
        ];
    }
}