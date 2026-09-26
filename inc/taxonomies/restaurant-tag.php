<?php
namespace NextSafar\Taxonomies;

class RestaurantTag {
    public static function register() {
        register_taxonomy('restaurant_tag', 'restaurant', [
            'labels' => [
                'name'          => 'برچسب‌های رستوران',
                'singular_name' => 'برچسب',
                'search_items'  => 'جستجوی برچسب',
                'all_items'     => 'همه برچسب‌ها',
                'edit_item'     => 'ویرایش برچسب',
                'update_item'   => 'به‌روزرسانی برچسب',
                'add_new_item'  => 'افزودن برچسب جدید',
                'new_item_name' => 'نام برچسب جدید',
                'menu_name'     => 'برچسب رستوران',
            ],
            'hierarchical'      => false,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'rewrite'           => ['slug' => 'restaurant-tag', 'with_front' => false],
        ]);
    }
}