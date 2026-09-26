<?php
if (!defined('ABSPATH')) exit;

$flag_url = get_post_meta($post->ID, '_country_flag', true);
$iso_code = get_post_meta($post->ID, '_country_iso', true);
$source   = get_post_meta($post->ID, '_country_flag_source', true);
?>

<div class="ns-flag-wrapper">
    <!-- Flag status -->
    <div class="ns-flag-status">
        <?php if ($source === 'manual') : ?>
            <span class="ns-badge ns-badge-manual">🔒 دستی</span>
        <?php elseif ($source === 'auto') : ?>
            <span class="ns-badge ns-badge-api">🤖 خودکار</span>
        <?php else : ?>
            <span class="ns-badge ns-badge-empty">⚠️ خالی</span>
        <?php endif; ?>
    </div>

    <!-- Flag preview -->
    <div class="ns-flag-preview" id="ns-flag-preview">
        <?php if ($flag_url) : ?>
            <img src="<?= esc_url($flag_url); ?>" alt="پرچم" style="max-width: 100%; border-radius: 6px;">
        <?php else : ?>
            <div class="ns-flag-placeholder">🏳️<br>پرچمی انتخاب نشده</div>
        <?php endif; ?>
    </div>

    <!-- Hidden inputs -->
    <input type="hidden" name="country_flag" id="ns-country-flag" value="<?= esc_attr($flag_url); ?>">
    <input type="hidden" name="country_iso" id="ns-country-iso" value="<?= esc_attr($iso_code); ?>">
    <input type="hidden" name="country_flag_source" id="ns-country-flag-source" value="<?= esc_attr($source); ?>">

    <!-- دکمه‌ها -->
    <div class="ns-flag-buttons">
        <button type="button" id="ns-flag-auto" class="button button-primary" style="width:100%; margin-bottom:6px;">
            🌍 دریافت خودکار پرچم (از نام کشور)
        </button>
        <button type="button" id="ns-flag-upload" class="button" style="width:100%;">
            📁 آپلود پرچم دستی
        </button>
        <button type="button" id="ns-flag-remove" class="button-link-delete" style="width:100%; margin-top:6px; text-align:center;">
            🗑️ حذف پرچم
        </button>
    </div>

    <p class="ns-flag-help" style="font-size:11px; color:#666; margin-top:10px;">
        💡 برای دریافت خودکار، ابتدا در فیلد «نام کشور» (در اطلاعات اصلی) نام کشور را وارد کن، سپس دکمه «دریافت خودکار» را بزن.
    </p>
</div>

<style>
.ns-flag-wrapper { padding: 5px; }
.ns-flag-status { margin-bottom: 10px; text-align: center; }
.ns-flag-preview {
    border: 2px dashed #ddd;
    border-radius: 8px;
    padding: 15px;
    text-align: center;
    margin-bottom: 12px;
    min-height: 80px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #fafafa;
}
.ns-flag-preview img { max-height: 80px; }
.ns-flag-placeholder { color: #999; font-size: 12px; }
.ns-badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 500; }
.ns-badge-manual { background: #d4edda; color: #155724; }
.ns-badge-api { background: #d1ecf1; color: #0c5460; }
.ns-badge-empty { background: #fff3cd; color: #856404; }
</style>