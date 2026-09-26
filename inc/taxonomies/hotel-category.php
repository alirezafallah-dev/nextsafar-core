<?php
namespace NextSafar\Taxonomies;

class HotelCategory {
    public static function register() {
        register_taxonomy('hotel_category', 'hotel', [
            'labels' => [
                'name'          => 'دسته بندی هتل',
                'singular_name' => 'دسته بندی',
                'search_items'  => 'جستجو',
                'all_items'     => 'همه دسته‌بندی‌ها',
                'edit_item'     => 'ویرایش دسته‌بندی',
                'add_new_item'  => 'افزودن دسته‌بندی جدید',
            ],
            'public'            => true,
            'hierarchical'      => true,
            'show_ui'           => true,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'rewrite'           => ['slug' => 'hotel-category', 'with_front' => false],
        ]);
    }
}