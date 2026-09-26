<?php if (!defined('ABSPATH')) exit; ?>
<div class="ns-geo-field">
    <label for="ns_geo_coords"><strong>مختصات (lat,lng)</strong></label>
    <div style="display:flex;gap:6px;align-items:center;margin-top:6px;flex-wrap:wrap;">
        <input type="text" id="ns_geo_coords" name="ns_geo_coords"
               value="<?= esc_attr($value); ?>"
               placeholder="41.040124,28.984285"
               style="width:100%;" />
        <button type="button" class="button" id="ns-geo-open-map" style="width:100%;">
            🗺️ انتخاب از نقشه
        </button>
    </div>
    <p class="description" style="margin-top:6px;">
        یک فیلد ترکیبی: عرض و طول را با کاما جدا کن یا از نقشه انتخاب کن.
        هنگام ذخیره به <code>_geo_lat</code> و <code>_geo_lng</code> تبدیل می‌شود.
    </p>
    <?php wp_nonce_field('ns_geo_coords_' . $post->ID, 'ns_geo_coords_nonce'); ?>
</div>

<!-- ═══ Map modal (OSM — no API key required) ═══ -->
<div id="ns-geo-modal" style="display:none;">
    <div class="ns-geo-modal-box">
        <div class="ns-geo-modal-head">
            <input type="text" id="ns-geo-search" placeholder="🔍 جستجوی نام مکان (مثلاً: Isis Hotel Istanbul)..." />
            <button type="button" class="button" id="ns-geo-do-search">جستجو</button>
            <button type="button" class="button button-primary" id="ns-geo-use">✅ استفاده از این مختصات</button>
            <button type="button" class="button" id="ns-geo-close">بستن</button>
        </div>
        <div id="ns-geo-map"></div>
        <div class="ns-geo-modal-foot">
            مختصات فعلی: <code id="ns-geo-current">—</code>
            <span class="description">روی نقشه کلیک کن یا مارکر را بکش</span>
        </div>
    </div>
</div>