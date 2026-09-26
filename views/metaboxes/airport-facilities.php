<?php if (!defined('ABSPATH')) exit; ?>

<div class="nextsafar-airport-metabox">
    <div class="ns-section">
        <h3 class="ns-section-title">🏗️ امکانات فرودگاه</h3>
        
        <div class="ns-amenities-accordion">
            <?php 
            $index = 0;
            foreach ($sections as $section_title => $fields) :
                // ⭐ شناسه انگلیسی امن
                $section_id = 'airport-fac-' . $index;
                $section_meta_key = 'fac_custom_' . $index;
                $custom_values = get_post_meta($post->ID, $section_meta_key, true);
                if (!is_array($custom_values) || empty($custom_values)) {
                    $custom_values = [''];
                }
                $index++;
            ?>
                <div class="ns-amenity-group">
                    <button type="button" class="ns-amenity-header" 
                            data-target="<?= esc_attr($section_id); ?>"
                            data-meta-key="<?= esc_attr($section_meta_key); ?>">
                        <span><?= esc_html($section_title); ?> (<?= count($fields); ?>)</span>
                        <span class="dashicons dashicons-arrow-down-alt2"></span>
                    </button>
                    <div class="ns-amenity-body" id="<?= esc_attr($section_id); ?>" style="display: none;">
                        
                        <!-- Facility checkboxes -->
                        <div class="ns-grid ns-grid-3">
                            <?php foreach ($fields as $key => $label) :
                                $value = get_post_meta($post->ID, '_' . $key, true);
                            ?>
                                <div class="ns-amenity-item">
                                    <div class="ns-amenity-main">
                                        <label>
                                            <input type="checkbox" name="<?= esc_attr($key); ?>" 
                                                   value="yes" <?= checked($value, 'yes', false); ?>>
                                            <?= esc_html($label); ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Repeatable fields -->
                        <div class="ns-repeatable-wrapper" data-name="<?= esc_attr($section_meta_key); ?>">
                            <div class="ns-repeatable-list">
                                <?php foreach ($custom_values as $val) : ?>
                                    <div class="ns-repeatable-item">
                                        <input type="text" name="<?= esc_attr($section_meta_key); ?>[]" 
                                               value="<?= esc_attr($val); ?>" placeholder="مورد سفارشی..." />
                                        <button type="button" class="remove-item">✕</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="ns-btn-add add-item">➕ افزودن مورد</button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>