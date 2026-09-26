<?php
if (!defined('ABSPATH')) exit;
?>
<div class="nextsafar-hotel-metabox">

<!-- ========== Hotel Type ========== -->
<div class="ns-section">
    <h3 class="ns-section-title">نوع هتل (پکیج پذیرایی)</h3>
    <div class="ns-grid ns-grid-4">
        <?php $type_value = get_post_meta($post->ID, '_hotel_type', true); ?>
        <div class="ns-field">
            <label for="hotel_type">نوع:</label>
            <select class="ns-input" name="hotel_type">
                <option value="">انتخاب</option>
                <?php foreach (['UALL', 'ALL', 'FB', 'HB', 'BB'] as $type) : ?>
                <option value="<?= $type; ?>" <?= selected($type_value, $type, false); ?>><?= $type; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <p class="description">نام انگلیسی، آدرس، مختصات، ستاره، ساعت ورود/خروج و قوانین در متاباکس «اطلاعات مکانی و سینک» مدیریت می‌شوند.</p>
</div>

<!-- ========== Ratings ========== -->
<div class="ns-section">
    <h3 class="ns-section-title">امتیازات (از ۱۰)</h3>
    <div class="ns-grid ns-grid-6">
        <?php
        $points = [
            'hotel_staff_point'      => 'کارکنان',
            'hotel_facilities_point' => 'امکانات',
            'hotel_cleanliness'      => 'پاکیزگی',
            'hotel_comfort_point'    => 'راحتی',
            'hotel_services_point'   => 'خدمات',
            'hotel_location_point'   => 'مکان',
        ];
        foreach ($points as $id => $label) :
            $val = get_post_meta($post->ID, '_' . $id, true); ?>
        <div class="ns-field">
            <label for="<?= esc_attr($id); ?>"><?= esc_html($label); ?></label>
            <input type="number" name="<?= esc_attr($id); ?>" step="0.1" min="0" max="10"
                   placeholder="0.0" value="<?= esc_attr($val); ?>" class="ns-input">
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ========== Nearby Locations ========== -->
<?php
$locations = get_post_meta($post->ID, '_hotel_near_locations', true);
$locations = is_array($locations) ? $locations : [];
$location_categories = [
    'near_locations_htl' => 'مکان‌های نزدیک',
    'destination'        => 'مقصد گردشگری',
    'restaurant'         => 'رستوران',
    'airport'            => 'فرودگاه',
    'shopping'           => 'مرکز خرید',
    'hospital'           => 'بیمارستان',
];
?>
<div class="ns-section">
    <h3 class="ns-section-title">مکان‌های نزدیک</h3>
    <div id="hotel-locations-wrapper" data-index-count="<?= count($locations); ?>">
        <div id="hotel-locations-list">
            <?php foreach ($locations as $i => $loc) : ?>
            <div class="ns-location-row">
                <select name="hotel_near_locations[<?= $i ?>][type]" class="location-type">
                    <option value="manual" <?= selected($loc['type'] ?? '', 'manual'); ?>>دستی</option>
                    <option value="post" <?= selected($loc['type'] ?? '', 'post'); ?>>از پست</option>
                </select>
                <select name="hotel_near_locations[<?= $i ?>][category]">
                    <option value="">دسته‌بندی</option>
                    <?php foreach ($location_categories as $cat_key => $cat_label) : ?>
                    <option value="<?= esc_attr($cat_key); ?>" <?= selected($loc['category'] ?? '', $cat_key); ?>><?= esc_html($cat_label); ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="hotel_near_locations[<?= $i ?>][name]" placeholder="نام محل" value="<?= esc_attr($loc['name'] ?? ''); ?>">
                <input type="text" name="hotel_near_locations[<?= $i ?>][coords]" placeholder="lat,lng" value="<?= esc_attr($loc['coords'] ?? ''); ?>">
                <input type="text" name="hotel_near_locations[<?= $i ?>][distance_km]" placeholder="فاصله" value="<?= esc_attr($loc['distance_km'] ?? ''); ?>">
                <select name="hotel_near_locations[<?= $i ?>][distance_unit]">
                    <option value="km" <?= selected($loc['distance_unit'] ?? '', 'km'); ?>>کیلومتر</option>
                    <option value="m" <?= selected($loc['distance_unit'] ?? '', 'm'); ?>>متر</option>
                </select>
                <input type="text" name="hotel_near_locations[<?= $i ?>][walking_hr]" placeholder="زمان پیاده" value="<?= esc_attr($loc['walking_hr'] ?? ''); ?>">
                <button type="button" class="button remove-location">✕</button>
            </div>
            <?php endforeach; ?>
        </div>
        <button type="button" id="add-location" class="button button-primary">افزودن مکان</button>
    </div>
</div>

<!-- ========== Amenities ========== -->
<div class="ns-section">
    <h3 class="ns-section-title">امکانات هتل</h3>
    <?php include NEXTSAFAR_PATH . 'views/metaboxes/hotel-amenities.php'; ?>
</div>

<!-- ========== Descriptions & Policies ========== -->
<div class="ns-section">
    <h3 class="ns-section-title">توضیحات و قوانین</h3>
    <?php
    $textareas = [
        'hotel_dis_checkin'        => 'قوانین ورود',
        'hotel_dis_checkout'       => 'قوانین خروج',
        'hotel_dis_expenses'       => 'هزینه‌های اختیاری',
        'hotel_no_age_restriction' => 'محدودیت سنی',
        'hotel_Pets_rouls'         => 'قوانین حیوانات خانگی',
    ];
    foreach ($textareas as $id => $label) :
        $val = get_post_meta($post->ID, '_' . $id, true); ?>
    <div class="ns-field" style="margin-bottom: 15px;">
        <label for="<?= esc_attr($id); ?>"><strong><?= esc_html($label); ?>:</strong></label>
        <textarea name="<?= esc_attr($id); ?>" rows="4" class="ns-textarea"><?= esc_textarea($val); ?></textarea>
    </div>
    <?php endforeach; ?>
</div>

<!-- ========== Custom Sections ========== -->
<?php
$select_options = [
    'option1' => 'ودیعه خسارت', 'option2' => 'خروج', 'option3' => 'لغو/پیش‌پرداخت',
    'option4' => 'محدودیت سنی', 'option5' => 'حیوانات خانگی',
];
$repeatable_data = get_post_meta($post->ID, '_hotel_custom_sections', true);
if (!is_array($repeatable_data)) $repeatable_data = [];
?>
<div class="ns-section">
    <h3 class="ns-section-title">بخش‌های سفارشی</h3>
    <div id="custom-sections-list">
        <?php foreach ($repeatable_data as $item) : ?>
        <div class="ns-custom-section-row">
            <select name="hotel_custom_sections[select][]">
                <?php foreach ($select_options as $key => $label) : ?>
                <option value="<?= esc_attr($key); ?>" <?= selected($item['select'] ?? '', $key, false); ?>><?= esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
            <textarea name="hotel_custom_sections[textarea][]" rows="2"><?= esc_textarea($item['textarea'] ?? ''); ?></textarea>
            <button type="button" class="button remove-custom-section">✕</button>
        </div>
        <?php endforeach; ?>
    </div>
    <button type="button" id="add-custom-section" class="button">افزودن بخش</button>
</div>

</div>