<?php if (!defined('ABSPATH')) exit;
$text_fields = [
    'destination_entry_fee'   => 'هزینه ورود',
    'destination_duration'    => 'مدت زمان پیشنهادی بازدید',
    'destination_best_season' => 'بهترین فصل بازدید',
];
?>
<div class="nextsafar-destination-metabox">

<div class="ns-section">
    <h3 class="ns-section-title">🎟️ اطلاعات بازدید</h3>
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
    <p class="description">ℹ️ نام انگلیسی، شهر/کشور، آدرس، مختصات، تماس، وب‌سایت، ویکی‌پدیا، امتیاز و نوع مکان در «📍 اطلاعات مکانی و سینک».</p>
</div>

<div class="ns-section">
    <h3 class="ns-section-title">🏗️ اطلاعات ساختاری و تاریخی</h3>
    <div class="ns-grid ns-grid-2">
        <?php
        $structural_fields = [
            'destination_Opened'               => 'تاسیس / سال ساخت',
            'destination_Height'               => 'ارتفاع',
            'destination_Architect'            => 'معمار',
            'destination_Architectural_styles' => 'سبک‌های معماری',
            'destination_Owner'                => 'مالک',
            'destination_Floor_count'          => 'تعداد طبقات',
            'destination_Former_names'         => 'نام‌های پیشین',
            'destination_Structural_system'    => 'سیستم سازه‌ای',
        ];
        foreach ($structural_fields as $id => $label) :
            $value = get_post_meta($post->ID, '_' . $id, true); ?>
        <div class="ns-field">
            <label for="<?= esc_attr($id); ?>"><?= esc_html($label); ?>:</label>
            <input class="ns-input" type="text" id="<?= esc_attr($id); ?>"
                   name="<?= esc_attr($id); ?>" value="<?= esc_attr($value); ?>">
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="ns-section">
    <h3 class="ns-section-title">📝 توضیحات و محتوا</h3>
    <?php
    $textareas = [
        'destination_facilities'    => 'لیست امکانات',
        'destination_accessibility' => 'دسترسی‌ها',
        'destination_more_desc'     => 'توضیحات و توصیه‌ها',
    ];
    foreach ($textareas as $id => $label) :
        $val = get_post_meta($post->ID, '_' . $id, true); ?>
    <div class="ns-field" style="margin-bottom: 15px;">
        <label for="<?= esc_attr($id); ?>"><strong><?= esc_html($label); ?>:</strong></label>
        <textarea class="ns-textarea" id="<?= esc_attr($id); ?>"
                  name="<?= esc_attr($id); ?>" rows="6"><?= esc_textarea($val); ?></textarea>
    </div>
    <?php endforeach; ?>
</div>

</div>