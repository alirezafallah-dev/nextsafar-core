<?php
if (!defined('ABSPATH')) exit;

$external_id = get_post_meta($post->ID, '_hotel_external_id', true);
$data_source = get_post_meta($post->ID, '_hotel_data_source', true);
$last_sync   = get_post_meta($post->ID, '_hotel_last_sync', true);
$property_token = get_post_meta($post->ID, '_hotel_property_token', true);
?>

<div class="ns-api-info">
    <div class="ns-api-field">
        <strong>شناسه خارجی:</strong>
        <div><?= esc_html($external_id ?: '—'); ?></div>
    </div>
    <div class="ns-api-field">
        <strong>Property Token:</strong>
        <div style="word-break:break-all; font-size: 11px;">
            <?= esc_html($property_token ?: '—'); ?>
        </div>
    </div>
    <div class="ns-api-field">
        <strong>منبع داده:</strong>
        <div>
            <?php if ($data_source) : ?>
                <span class="ns-badge ns-badge-<?= esc_attr($data_source); ?>">
                    <?= esc_html($data_source); ?>
                </span>
            <?php else : ?>
                <span class="ns-badge ns-badge-manual">دستی</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="ns-api-field">
        <strong>آخرین همگام‌سازی:</strong>
        <div>
            <?= $last_sync ? date_i18n('Y-m-d H:i', strtotime($last_sync)) : '—'; ?>
        </div>
    </div>
    
    <hr style="margin: 15px 0;">
    
    <p style="font-size: 12px; color: #666;">
        💡 این فیلدها توسط API پر می‌شوند
    </p>
    
    <button type="button" class="button button-primary" id="sync-hotel-data" 
            style="width: 100%;">
        🔄 همگام‌سازی مجدد
    </button>
</div>

<style>
.ns-api-field { margin-bottom: 10px; }
.ns-api-field strong { display: block; font-size: 11px; color: #666; margin-bottom: 3px; }
.ns-badge { 
    display: inline-block; 
    padding: 2px 8px; 
    border-radius: 3px; 
    font-size: 11px; 
    background: #f0f0f0;
}
.ns-badge-searchapi { background: #d4edda; color: #155724; }
.ns-badge-serpapi { background: #d1ecf1; color: #0c5460; }
.ns-badge-dataforseo { background: #fff3cd; color: #856404; }
.ns-badge-manual { background: #f8d7da; color: #721c24; }
</style>