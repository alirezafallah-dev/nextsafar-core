<?php
namespace NextSafar\Taxonomies;

class DestinationCategory {
    public static function register() {
        register_taxonomy('destination_category', 'destination', [
            'labels' => [
                'name'              => 'دسته بندی مقاصد',
                'singular_name'     => 'دسته بندی',
                'search_items'      => 'جستجو',
                'all_items'         => 'همه دسته‌بندی‌ها',
                'parent_item'       => 'دسته والد:',
                'parent_item_colon' => 'دسته والد:',
                'edit_item'         => 'ویرایش دسته‌بندی',
                'update_item'       => 'به‌روزرسانی دسته‌بندی',
                'add_new_item'      => 'افزودن دسته‌بندی جدید',
                'new_item_name'     => 'نام دسته‌بندی جدید',
                'menu_name'         => 'دسته‌بندی مقاصد',
            ],
            'public'            => true,
            'hierarchical'      => true,
            'show_ui'           => true,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'rewrite'           => ['slug' => 'destination-category', 'with_front' => false],
        ]);
    }
}