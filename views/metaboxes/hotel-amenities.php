<?php
if (!defined('ABSPATH')) exit;

$sections = \NextSafar\MetaBoxes\HotelMetaBox::get_amenity_sections();
$special_hours_keys = ['hotel_Indoor_pool', 'hotel_outdoor_pool', 'hotel_restaurant'];

// English IDs for each section.
// Internal section keys remain Persian to preserve compatibility with HotelMetaBox.
$section_ids = [
    'حمام'              => 'sec-bath',
    'امکانات اتاق'      => 'sec-room',
    'فضای باز'          => 'sec-outdoor',
    'آشپزخانه'          => 'sec-kitchen',
    'فعالیت‌ها'         => 'sec-activities',
    'رسانه و فناوری'    => 'sec-media',
    'امکانات رفاهی'     => 'sec-facilities',
    'غذا و نوشیدنی'     => 'sec-food',
    'خدمات پذیرش'       => 'sec-reception',
    'خدمات نظافتی'      => 'sec-cleaning',
    'حیوانات خانگی'     => 'sec-pets',
    'امکانات تجاری'     => 'sec-business',
    'ایمنی و امنیت'     => 'sec-safety',
    'استخر'             => 'sec-pool',
    'پارکینگ'           => 'sec-parking',
    'حمل و نقل'         => 'sec-transport',
    'عمومی'             => 'sec-general',
    'خدمات اسپا'        => 'sec-spa',
    'انواع اتاق'        => 'sec-roomtype',
    'زبان کارکنان'      => 'sec-language',
    'دسترسی'            => 'sec-access',
];

// English display labels for section titles.
$section_labels = [
    'حمام'              => 'Bathroom',
    'امکانات اتاق'      => 'Room Amenities',
    'فضای باز'          => 'Outdoor',
    'آشپزخانه'          => 'Kitchen',
    'فعالیت‌ها'         => 'Activities',
    'رسانه و فناوری'    => 'Media & Technology',
    'امکانات رفاهی'     => 'Wellness Facilities',
    'غذا و نوشیدنی'     => 'Food & Drinks',
    'خدمات پذیرش'       => 'Reception Services',
    'خدمات نظافتی'      => 'Cleaning Services',
    'حیوانات خانگی'     => 'Pets',
    'امکانات تجاری'     => 'Business Facilities',
    'ایمنی و امنیت'     => 'Safety & Security',
    'استخر'             => 'Pool',
    'پارکینگ'           => 'Parking',
    'حمل و نقل'         => 'Transport',
    'عمومی'             => 'General',
    'خدمات اسپا'        => 'Spa Services',
    'انواع اتاق'        => 'Room Types',
    'زبان کارکنان'      => 'Staff Languages',
    'دسترسی'            => 'Accessibility',
];
?>

<div class="ns-amenities-accordion">
    <?php $index = 0; ?>
    <?php foreach ($sections as $section_title => $fields) : 
        $section_id = $section_ids[$section_title] ?? ('sec-' . $index);
        $index++;
    ?>
        <div class="ns-amenity-group">
            <button type="button" class="ns-amenity-header" data-target="<?= esc_attr($section_id); ?>">
                <span><?= esc_html($section_title); ?> (<?= count($fields); ?>)</span>
                <span class="dashicons dashicons-arrow-down-alt2"></span>
            </button>
            <div class="ns-amenity-body" id="<?= esc_attr($section_id); ?>" style="display: none;">
                <div class="ns-grid ns-grid-3">
                    <?php foreach ($fields as $key => $label) :
                        $value = get_post_meta($post->ID, '_' . $key, true);
                        $extra = get_post_meta($post->ID, '_' . $key . '_extra', true);
                        $has_hours = in_array($key, $special_hours_keys);
                        $hours = get_post_meta($post->ID, 'working_hours_' . $key, true);
                    ?>
                        <div class="ns-amenity-item">
                            <div class="ns-amenity-main">
                                <label>
                                    <input type="checkbox" name="<?= esc_attr($key); ?>" 
                                           value="yes" <?= checked($value, 'yes', false); ?>>
                                    <?= esc_html($label); ?>
                                </label>
                                <?php if ($has_hours) : ?>
                                    <button type="button" class="button button-small set-working-hours" 
                                            data-key="<?= esc_attr($key); ?>"
                                            data-hours='<?= esc_attr(json_encode($hours ?: [])); ?>'>
                                        ⏰ ساعات
                                    </button>
                                <?php endif; ?>
                            </div>
                            <label class="ns-extra-charge">
                                <input type="checkbox" name="<?= esc_attr($key . '_extra'); ?>" 
                                       value="yes" <?= checked($extra, 'yes', false); ?>>
                                <small>💰 هزینه اضافی</small>
                            </label>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>