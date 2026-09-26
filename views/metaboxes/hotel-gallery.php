<?php if (!defined('ABSPATH')) exit;
$gallery = get_post_meta($post->ID, '_hotel_gallery', true);
$gallery = is_array($gallery) ? $gallery : [];
$gallery_source = get_post_meta($post->ID, '_hotel_gallery_source', true);
?>
<div class="ns-section">
    <h3 class="ns-section-title">🖼️ گالری تصاویر هتل</h3>
    
    <div style="margin-bottom: 12px; font-size: 12px; color: #666;">
        منبع گالری: 
        <?php if ($gallery_source === 'manual') : ?>
            <span class="ns-badge" style="background:#d4edda; color:#155724;">🔒 دستی - قفل (API دست نمی‌زند)</span>
        <?php elseif ($gallery_source === 'api') : ?>
            <span class="ns-badge" style="background:#d1ecf1; color:#0c5460;">🤖 API</span>
        <?php else : ?>
            <span class="ns-badge">— خالی (در sync بعدی API پر می‌کند)</span>
        <?php endif; ?>
        <span style="margin-right: 10px;">(<?= count($gallery); ?> تصویر)</span>
    </div>

    <div id="hotel-gallery-grid" class="ns-gallery-grid">
        <?php foreach ($gallery as $img_id) :
            $thumb = wp_get_attachment_image_url($img_id, 'thumbnail');
            if (!$thumb) continue; ?>
            <div class="ns-gallery-item" data-id="<?= $img_id; ?>">
                <img src="<?= esc_url($thumb); ?>">
                <button type="button" class="ns-gallery-remove">✕</button>
            </div>
        <?php endforeach; ?>
    </div>

    <input type="hidden" id="hotel-gallery-ids" name="hotel_gallery_ids" 
           value="<?= esc_attr(implode(',', $gallery)); ?>">
    <input type="hidden" id="hotel-gallery-touched" name="hotel_gallery_touched" value="0">
    <input type="hidden" id="featured-touched" name="hotel_featured_touched" value="0">

    <div style="margin-top: 12px;">
        <button type="button" id="ns-add-gallery-images" class="button">
            ➕ افزودن تصویر از کتابخانه 미디어
        </button>
    </div>
</div>

<style>
.ns-gallery-grid { display: flex; flex-wrap: wrap; gap: 10px; }
.ns-gallery-item { position: relative; width: 100px; height: 100px; }
.ns-gallery-item img { width: 100%; height: 100%; object-fit: cover; border-radius: 6px; border: 2px solid #ddd; }
.ns-gallery-remove { position: absolute; top: -8px; right: -8px; width: 22px; height: 22px; border-radius: 50%; background: #d63638; color: #fff; border: none; cursor: pointer; font-size: 11px; }
</style>