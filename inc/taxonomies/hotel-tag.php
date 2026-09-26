<?php
namespace NextSafar\Taxonomies;

class HotelTag {
    public static function register() {
        register_taxonomy('hotel_tag', 'hotel', [
            'hierarchical'      => false,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'labels'            => [
                'name'          => 'برچسب‌های هتل',
                'singular_name' => 'برچسب',
                'menu_name'     => 'برچسب هتل',
            ],
            'rewrite'           => ['slug' => 'hotel-tag', 'with_front' => false],
        ]);
    }
}