<?php

namespace NextSafar\Taxonomies;

class TourCategory {
    public static function register() {
        register_taxonomy('tour_category', 'tour', [
            'labels' => [
                'name'              => 'دسته بندی تور',
                'singular_name'     => 'دسته بندی',
                'search_items'      => 'جستجو',
                'all_items'         => 'همه دسته‌بندی‌ها',
                'parent_item'       => 'دسته والد:',
                'parent_item_colon' => 'دسته والد:',
                'edit_item'         => 'ویرایش دسته‌بندی',
                'update_item'       => 'به‌روزرسانی دسته‌بندی',
                'add_new_item'      => 'افزودن دسته‌بندی جدید',
                'new_item_name'     => 'نام دسته‌بندی جدید',
                'menu_name'         => 'دسته‌بندی تور',
            ],
            'public'            => true,
            'hierarchical'      => true,
            'show_ui'           => true,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'rewrite'           => ['slug' => 'tour-category', 'with_front' => false],
        ]);
    }
}