<?php if (!defined('ABSPATH')) exit;
$text_fields = [
    'airport_opened'   => 'تاریخ تاسیس',
    'airport_whatsapp' => 'شماره واتساپ',
];
?>
<div class="nextsafar-airport-metabox">

<div class="ns-section">
    <h3 class="ns-section-title">📋 اطلاعات تکمیلی فرودگاه</h3>
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
    <p class="description">ℹ️ نام انگلیسی، IATA، شهر/کشور، آدرس، مختصات، تماس، وب‌سایت، ترمینال‌ها و نوع در «📍 اطلاعات مکانی و سینک».</p>
</div>

<div class="ns-section">
    <h3 class="ns-section-title">🏗️ ساختار فرودگاه</h3>
    <div class="ns-grid ns-grid-4">
        <?php $runways = get_post_meta($post->ID, '_airport_runways_count', true); ?>
        <div class="ns-field">
            <label for="airport_runways_count">تعداد باندهای پرواز:</label>
            <input class="ns-input" type="number" min="0" id="airport_runways_count"
                   name="airport_runways_count" value="<?= esc_attr($runways); ?>">
        </div>
    </div>
</div>

</div>