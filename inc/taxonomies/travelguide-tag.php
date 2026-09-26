<?php
namespace NextSafar\Taxonomies;

class TravelGuideTag {
    public static function register() {
        register_taxonomy('travelguide_tag', 'travelguide', [
            'labels' => [
                'name'              => 'برچسب‌های راهنمای سفر',
                'singular_name'     => 'برچسب',
                'search_items'      => 'جستجوی برچسب',
                'all_items'         => 'همه برچسب‌ها',
                'edit_item'         => 'ویرایش برچسب',
                'update_item'       => 'به‌روزرسانی برچسب',
                'add_new_item'      => 'افزودن برچسب جدید',
                'new_item_name'     => 'نام برچسب جدید',
                'menu_name'         => 'برچسب راهنمای سفر',
            ],
            'hierarchical'      => false,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'rewrite'           => ['slug' => 'travelguide-tag', 'with_front' => false],
        ]);
    }
}