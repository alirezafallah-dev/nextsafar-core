<?php if (!defined('ABSPATH')) exit;
$text_fields = [
    'hospital_emergency_phone' => 'شماره اورژانس',
    'hospital_fax'             => 'فکس',
    'hospital_mail'            => 'ایمیل',
];
?>
<div class="nextsafar-hospital-metabox">

<div class="ns-section">
    <h3 class="ns-section-title">📞 تماس‌های اختصاصی</h3>
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
    <p class="description">ℹ️ نام انگلیسی، شهر/کشور، آدرس، مختصات، تلفن، وب‌سایت، امتیاز و نوع در «📍 اطلاعات مکانی و سینک».</p>
</div>

<div class="ns-section">
    <h3 class="ns-section-title">🏥 ساختار بیمارستان</h3>
    <div class="ns-grid ns-grid-2">
        <?php $beds = get_post_meta($post->ID, '_hospital_number_of_bed', true); ?>
        <div class="ns-field">
            <label for="hospital_number_of_bed">تعداد تخت‌ها:</label>
            <input class="ns-input" type="number" min="0" id="hospital_number_of_bed"
                   name="hospital_number_of_bed" value="<?= esc_attr($beds); ?>">
        </div>
    </div>
</div>

<div class="ns-section">
    <h3 class="ns-section-title">📝 توضیحات و محتوا</h3>
    <?php
    $textareas = [
        'hospital_facilities'       => '🏥 امکانات بیمارستان',
        'hospital_services'         => '🩺 خدمات',
        'hospital_special_services' => '✨ خدمات ویژه',
        'hospital_key_doctors'      => '👨‍⚕️ پزشکان برجسته',
        'hospital_insurance'        => '💳 امکانات بیمه',
        'hospital_languages'        => '🌐 زبان‌های پشتیبانی',
        'hospital_accessibility'    => '♿ دسترسی‌ها',
        'hospital_more_desc'        => '📖 توضیحات و توصیه‌ها',
    ];
    foreach ($textareas as $id => $label) :
        $val = get_post_meta($post->ID, '_' . $id, true); ?>
    <div class="ns-field" style="margin-bottom: 15px;">
        <label for="<?= esc_attr($id); ?>"><strong><?= esc_html($label); ?>:</strong></label>
        <textarea class="ns-textarea" id="<?= esc_attr($id); ?>"
                  name="<?= esc_attr($id); ?>" rows="5"><?= esc_textarea($val); ?></textarea>
    </div>
    <?php endforeach; ?>
</div>

</div>