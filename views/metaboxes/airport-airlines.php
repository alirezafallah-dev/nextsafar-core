<?php if (!defined('ABSPATH')) exit; ?>

<div class="nextsafar-airport-metabox">
    <div class="ns-section">
        <h3 class="ns-section-title">🛩️ خطوط هوایی (ایرلاین‌ها)</h3>
        
        <!-- Main airline website -->
        <?php $main_site = get_post_meta($post->ID, '_airline_main_airline_site', true); ?>
        <div class="ns-field" style="margin-bottom: 20px;">
            <label for="airline-main-site">🌐 آدرس سایت اصلی ایرلاین‌ها:</label>
            <input class="ns-input" type="url" id="airline-main-site" 
                   name="airline_main_airline_site" value="<?= esc_attr($main_site); ?>" 
                   placeholder="https://...">
        </div>

        <!-- Airlines list -->
        <?php $airlines = get_post_meta($post->ID, '_airlines', true); ?>
        <?php if (!is_array($airlines)) $airlines = []; ?>

        <label style="font-weight: 500; color: #555; display: block; margin-bottom: 10px;">
            ✈️ لیست خطوط هوایی فعال در این فرودگاه:
        </label>

        <div id="airlines-wrapper">
            <?php foreach ($airlines as $index => $airline) : ?>
                <div class="ns-airline-item" data-index="<?= $index; ?>">
                    <input type="text" name="airlines[<?= $index; ?>][name]" 
                           value="<?= esc_attr($airline['name']); ?>" 
                           placeholder="نام ایرلاین (مثلاً: ماهان)">
                    <input type="url" name="airlines[<?= $index; ?>][link]" 
                           value="<?= esc_url($airline['link']); ?>" 
                           placeholder="لینک سایت (اختیاری)">
                    <button type="button" class="remove-airline" title="حذف">✕</button>
                </div>
            <?php endforeach; ?>
        </div>

        <button type="button" id="add-airline" class="ns-btn-add">
            ➕ افزودن ایرلاین جدید
        </button>
    </div>
</div>