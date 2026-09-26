<?php
namespace NextSafar\Taxonomies;

class RestaurantCategory {
    public static function register() {
        register_taxonomy('restaurant_category', 'restaurant', [
            'labels' => [
                'name'              => 'دسته بندی رستوران',
                'singular_name'     => 'دسته بندی',
                'search_items'      => 'جستجو',
                'all_items'         => 'همه دسته‌بندی‌ها',
                'parent_item'       => 'دسته والد:',
                'parent_item_colon' => 'دسته والد:',
                'edit_item'         => 'ویرایش دسته‌بندی',
                'update_item'       => 'به‌روزرسانی دسته‌بندی',
                'add_new_item'      => 'افزودن دسته‌بندی جدید',
                'new_item_name'     => 'نام دسته‌بندی جدید',
                'menu_name'         => 'دسته‌بندی رستوران',
            ],
            'public'            => true,
            'hierarchical'      => true,
            'show_ui'           => true,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'rewrite'           => ['slug' => 'restaurant-category', 'with_front' => false],
        ]);
    }
}