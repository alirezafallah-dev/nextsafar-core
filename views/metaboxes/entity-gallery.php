<?php
if (!defined('ABSPATH')) exit;

$gallery = get_post_meta($post->ID, '_entity_gallery', true);
$gallery = is_array($gallery) ? $gallery : [];
$source  = get_post_meta($post->ID, '_entity_gallery_source', true);
$featured_source = get_post_meta($post->ID, '_featured_image_source', true);
?>

<div class="ns-gallery-wrapper">
    
    <!-- Lock status -->
    <div class="ns-gallery-status">
        <div class="ns-gallery-status-row">
            <span class="ns-gallery-label">📸 گالری:</span>
            <?php if ($source === 'manual') : ?>
                <span class="ns-badge ns-badge-manual">🔒 دستی - قفل (API دست نمی‌زند)</span>
            <?php elseif ($source === 'api') : ?>
                <span class="ns-badge ns-badge-api">🤖 از API</span>
            <?php else : ?>
                <span class="ns-badge ns-badge-empty">⚠️ خالی (در sync بعدی API پر می‌کند)</span>
            <?php endif; ?>
            <span class="ns-gallery-count">(<?= count($gallery); ?> تصویر)</span>
        </div>
        <div class="ns-gallery-status-row">
            <span class="ns-gallery-label">🖼️ تصویر شاخص:</span>
            <?php if ($featured_source === 'manual') : ?>
                <span class="ns-badge ns-badge-manual">🔒 دستی</span>
            <?php elseif (has_post_thumbnail($post->ID)) : ?>
                <span class="ns-badge ns-badge-api">🤖 API</span>
            <?php else : ?>
                <span class="ns-badge ns-badge-empty">⚠️ خالی</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Gallery grid -->
    <div class="ns-gallery-grid" id="ns-gallery-grid">
        <?php foreach ($gallery as $img_id) :
            $thumb = wp_get_attachment_image_url($img_id, 'thumbnail');
            $full  = wp_get_attachment_image_url($img_id, 'large');
            $alt   = get_post_meta($img_id, '_wp_attachment_image_alt', true);
            if (!$thumb) continue; ?>
            <div class="ns-gallery-item" data-id="<?= esc_attr($img_id); ?>">
                <img src="<?= esc_url($thumb); ?>" 
                     data-full="<?= esc_url($full); ?>" 
                     alt="<?= esc_attr($alt); ?>"
                     loading="lazy">
                <div class="ns-gallery-actions">
                    <button type="button" class="ns-gallery-view" title="مشاهده">🔍</button>
                    <button type="button" class="ns-gallery-remove" title="حذف">✕</button>
                </div>
                <div class="ns-gallery-drag-handle" title="جابجایی">⋮⋮</div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Hidden inputs -->
    <input type="hidden" id="ns-gallery-ids" name="nextsafar_gallery_ids" 
           value="<?= esc_attr(implode(',', $gallery)); ?>">
    <input type="hidden" id="ns-gallery-touched" name="nextsafar_gallery_touched" value="0">
    <input type="hidden" id="ns-featured-touched" name="nextsafar_featured_touched" value="0">

    <!-- Buttons -->
    <div class="ns-gallery-buttons">
        <button type="button" id="ns-gallery-add" class="button button-primary">
            ➕ افزودن تصاویر
        </button>
        <button type="button" id="ns-gallery-clear" class="button">
            🗑️ پاک کردن همه
        </button>
    </div>

    <!-- Help -->
    <div class="ns-gallery-help">
        💡 <strong>راهنما:</strong> 
        تصاویر را با درگ مرتب کن. اولین تصویر به صورت خودکار به عنوان تصویر شاخص پیشنهاد می‌شود.
        هر تغییری که دستی انجام بدی، گالری را قفل می‌کند و API در sync بعدی آن را بازنویسی نمی‌کند.
    </div>
</div>

<!-- Image view modal -->
<div id="ns-gallery-modal" class="ns-gallery-modal" style="display:none;">
    <div class="ns-gallery-modal-overlay"></div>
    <div class="ns-gallery-modal-content">
        <button type="button" class="ns-gallery-modal-close">✕</button>
        <img src="" alt="" id="ns-gallery-modal-img">
    </div>
</div>