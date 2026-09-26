<?php if (!defined('ABSPATH')) exit;

$text_fields = [
    'restaurant_mail'         => 'ایمیل',
    'restaurant_menu_link'    => 'لینک منو آنلاین',
    'restaurant_entry_fee'    => 'هزینه رزرو/ورود',
    'restaurant_best_season'  => 'بهترین فصل',
    'restaurant_duration'     => 'مدت زمان پیشنهادی بازدید',
    'restaurant_cuisine_type' => 'سبک آشپزی',
];
?>
<div class="nextsafar-restaurant-metabox">

<div class="ns-section">
    <h3 class="ns-section-title">📋 اطلاعات تکمیلی رستوران</h3>
    <div class="ns-grid ns-grid-2">
        <?php foreach ($text_fields as $id => $label) :
            $value = get_post_meta($post->ID, '_' . $id, true); ?>
        <div class="ns-field">
            <label for="<?= esc_attr($id); ?>"><?= esc_html($label); ?>:</label>
            <input class="ns-input" type="text" id="<?= esc_attr($id); ?>"
                   name="<?= esc_attr($id); ?>" value="<?= esc_attr($value); ?>">
        </div>
        <?php endforeach; ?>
    </div>
    <p class="description">ℹ️ نام انگلیسی، شهر/کشور، آدرس، مختصات، تماس، وب‌سایت، امتیاز، نوع و سطح قیمت در «📍 اطلاعات مکانی و سینک».</p>
</div>

</div>