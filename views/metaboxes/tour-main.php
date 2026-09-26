<?php if (!defined('ABSPATH')) exit;

$types = \NextSafar\MetaBoxes\TourMetaBox::get_tour_types();
$currencies = \NextSafar\MetaBoxes\TourMetaBox::get_currencies();
?>

<div class="nextsafar-tour-metabox">
    <!-- Basic information -->
    <div class="ns-section">
        <h3 class="ns-section-title">📋 اطلاعات پایه تور</h3>
        
        <div class="ns-grid ns-grid-2">
            <?php
            $text_fields = [
                'tour_name_en'   => 'نام انگلیسی تور',
                'tour_country'   => 'کشور مقصد',
                'tour_city'      => 'شهر مقصد',
                'tour_departure_date' => 'تاریخ رفت',
                'tour_return_date'    => 'تاریخ برگشت',
            ];
            
            foreach ($text_fields as $id => $label) :
                $value = get_post_meta($post->ID, '_' . $id, true); ?>
                <div class="ns-field">
                    <label for="<?= esc_attr($id); ?>"><?= esc_html($label); ?>:</label>
                    <input class="ns-input" type="text" id="<?= esc_attr($id); ?>" 
                           name="<?= esc_attr($id); ?>" value="<?= esc_attr($value); ?>">
                </div>
            <?php endforeach; ?>
            
            <!-- Tour duration -->
            <?php
            $days = get_post_meta($post->ID, '_tour_duration_days', true);
            $nights = get_post_meta($post->ID, '_tour_duration_nights', true);
            ?>
            <div class="ns-field">
                <label for="tour_duration_days">مدت تور (روز):</label>
                <input class="ns-input" type="number" min="1" max="60" 
                       id="tour_duration_days" name="tour_duration_days" 
                       value="<?= esc_attr($days); ?>">
            </div>
            <div class="ns-field">
                <label for="tour_duration_nights">مدت اقامت (شب):</label>
                <input class="ns-input" type="number" min="1" max="60" 
                       id="tour_duration_nights" name="tour_duration_nights" 
                       value="<?= esc_attr($nights); ?>">
            </div>
            
            <!-- Tour type -->
            <?php $type_value = get_post_meta($post->ID, '_tour_type', true); ?>
            <div class="ns-field">
                <label for="tour_type">نوع تور:</label>
                <select class="ns-input" id="tour_type" name="tour_type">
                    <?php foreach ($types as $val => $lbl) : ?>
                        <option value="<?= esc_attr($val); ?>" <?= selected($type_value, $val, false); ?>>
                            <?= esc_html($lbl); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- Pricing -->
    <div class="ns-section">
        <h3 class="ns-section-title">💰 قیمت‌گذاری</h3>
        
        <div class="ns-grid ns-grid-2">
            <?php
            $price_fields = [
                'tour_price_single' => 'قیمت یک تخته',
                'tour_price_double' => 'قیمت دو تخته',
                'tour_price_child'  => 'قیمت کودک',
                'tour_price_infant' => 'قیمت نوزاد',
            ];
            
            foreach ($price_fields as $id => $label) :
                $value = get_post_meta($post->ID, '_' . $id, true); ?>
                <div class="ns-field">
                    <label for="<?= esc_attr($id); ?>"><?= esc_html($label); ?>:</label>
                    <input class="ns-input ns-price-input" type="text" id="<?= esc_attr($id); ?>" 
                           name="<?= esc_attr($id); ?>" value="<?= esc_attr(number_format((int)$value)); ?>">
                </div>
            <?php endforeach; ?>
            
            <!-- Currency unit -->
            <?php $currency = get_post_meta($post->ID, '_tour_currency', true); ?>
            <div class="ns-field">
                <label for="tour_currency">واحد ارزی:</label>
                <select class="ns-input" id="tour_currency" name="tour_currency">
                    <?php foreach ($currencies as $val => $lbl) : ?>
                        <option value="<?= esc_attr($val); ?>" <?= selected($currency, $val, false); ?>>
                            <?= esc_html($lbl); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Discount -->
            <?php $discount = get_post_meta($post->ID, '_tour_discount', true); ?>
            <div class="ns-field">
                <label for="tour_discount">تخفیف (درصد):</label>
                <input class="ns-input" type="number" min="0" max="100" 
                       id="tour_discount" name="tour_discount" 
                       value="<?= esc_attr($discount); ?>">
            </div>
        </div>
    </div>

</div>