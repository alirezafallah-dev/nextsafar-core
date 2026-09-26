<?php if (!defined('ABSPATH')) exit;

$transport_options = \NextSafar\MetaBoxes\TourDetails::get_transport_options();
$features = \NextSafar\MetaBoxes\TourDetails::get_features();
$documents = \NextSafar\MetaBoxes\TourDetails::get_documents();

$transport_type = get_post_meta($post->ID, '_tour_transport_type', true);
$airline = get_post_meta($post->ID, '_tour_airline', true);
$train_name = get_post_meta($post->ID, '_tour_train_name', true);
$ship_name = get_post_meta($post->ID, '_tour_ship_name', true);
$bus_name = get_post_meta($post->ID, '_tour_bus_name', true);

$selected_features = get_post_meta($post->ID, '_tour_features', true);
if (!is_array($selected_features)) $selected_features = [];

$selected_documents = get_post_meta($post->ID, '_tour_documents', true);
if (!is_array($selected_documents)) $selected_documents = [];

$itinerary = get_post_meta($post->ID, '_tour_itinerary', true);
if (!is_array($itinerary) || empty($itinerary)) $itinerary = ['' => ''];
?>

<div class="nextsafar-tour-metabox">
    <!-- Transport -->
    <div class="ns-section">
        <h3 class="ns-section-title">✈️ حمل‌ونقل</h3>
        
        <div class="ns-grid ns-grid-2">
            <div class="ns-field">
                <label for="tour_transport_type">نوع حمل‌ونقل:</label>
                <select class="ns-input" id="tour_transport_type" name="tour_transport_type">
                    <option value="">انتخاب کنید</option>
                    <option value="هواپیما" <?= selected($transport_type, 'هواپیما'); ?>>هواپیما</option>
                    <option value="قطار" <?= selected($transport_type, 'قطار'); ?>>قطار</option>
                    <option value="کشتی" <?= selected($transport_type, 'کشتی'); ?>>کشتی</option>
                    <option value="اتوبوس" <?= selected($transport_type, 'اتوبوس'); ?>>اتوبوس</option>
                </select>
            </div>
        </div>

        <!-- Airline -->
        <div class="ns-transport-field" id="tour-airline-field" style="display:<?= $transport_type === 'هواپیما' ? 'block' : 'none'; ?>;">
            <div class="ns-field">
                <label for="tour_airline">ایرلاین:</label>
                <select class="ns-input" id="tour_airline" name="tour_airline">
                    <option value="">-- انتخاب ایرلاین --</option>
                    <optgroup label="ایرلاین‌های ایرانی">
                        <?php foreach ($transport_options['iran_airlines'] as $key => $label) : ?>
                            <option value="<?= esc_attr($key); ?>" <?= selected($airline, $key); ?>>
                                <?= esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                    <optgroup label="ایرلاین‌های خارجی">
                        <?php foreach ($transport_options['foreign_airlines'] as $key => $label) : ?>
                            <option value="<?= esc_attr($key); ?>" <?= selected($airline, $key); ?>>
                                <?= esc_html($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </optgroup>
                </select>
            </div>
        </div>

        <!-- Train -->
        <div class="ns-transport-field" id="tour-train-field" style="display:<?= $transport_type === 'قطار' ? 'block' : 'none'; ?>;">
            <div class="ns-field">
                <label for="tour_train_name">نام قطار:</label>
                <select class="ns-input" id="tour_train_name" name="tour_train_name">
                    <option value="">-- انتخاب --</option>
                    <?php foreach ($transport_options['trains'] as $key => $label) : ?>
                        <option value="<?= esc_attr($key); ?>" <?= selected($train_name, $key); ?>>
                            <?= esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Ship -->
        <div class="ns-transport-field" id="tour-ship-field" style="display:<?= $transport_type === 'کشتی' ? 'block' : 'none'; ?>;">
            <div class="ns-field">
                <label for="tour_ship_name">نام کشتی:</label>
                <select class="ns-input" id="tour_ship_name" name="tour_ship_name">
                    <option value="">-- انتخاب --</option>
                    <?php foreach ($transport_options['ships'] as $key => $label) : ?>
                        <option value="<?= esc_attr($key); ?>" <?= selected($ship_name, $key); ?>>
                            <?= esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Bus -->
        <div class="ns-transport-field" id="tour-bus-field" style="display:<?= $transport_type === 'اتوبوس' ? 'block' : 'none'; ?>;">
            <div class="ns-field">
                <label for="tour_bus_name">نام اتوبوس:</label>
                <select class="ns-input" id="tour_bus_name" name="tour_bus_name">
                    <option value="">-- انتخاب --</option>
                    <?php foreach ($transport_options['buses'] as $key => $label) : ?>
                        <option value="<?= esc_attr($key); ?>" <?= selected($bus_name, $key); ?>>
                            <?= esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- Tour features -->
    <div class="ns-section">
        <h3 class="ns-section-title">✅ امکانات تور</h3>
        <div class="ns-grid ns-grid-2">
            <?php foreach ($features as $key => $label) : ?>
                <div class="ns-amenity-item">
                    <label>
                        <input type="checkbox" name="tour_features[<?= $key; ?>]" value="1" 
                               <?= isset($selected_features[$key]) ? 'checked' : ''; ?>>
                        <?= esc_html($label); ?>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Required documents -->
    <div class="ns-section">
        <h3 class="ns-section-title">📄 مدارک لازم</h3>
        <div class="ns-grid ns-grid-2">
            <?php foreach ($documents as $key => $label) : ?>
                <div class="ns-amenity-item">
                    <label>
                        <input type="checkbox" name="tour_documents[<?= $key; ?>]" value="1" 
                               <?= isset($selected_documents[$key]) ? 'checked' : ''; ?>>
                        <?= esc_html($label); ?>
                    </label>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Itinerary -->
    <div class="ns-section">
        <h3 class="ns-section-title">🗓️ برنامه سفر (روز به روز)</h3>
        <div id="tour-itinerary-wrapper">
            <?php $day_num = 1; ?>
            <?php foreach ($itinerary as $day => $content) : ?>
                <div class="ns-itinerary-item" data-day="<?= $day_num; ?>">
                    <div class="ns-itinerary-header">
                        <strong>📅 روز <?= $day_num; ?></strong>
                        <button type="button" class="ns-remove-day">✕</button>
                    </div>
                    <textarea name="tour_itinerary[<?= $day_num; ?>]" rows="3" 
                              class="ns-textarea"><?= esc_textarea($content); ?></textarea>
                </div>
                <?php $day_num++; ?>
            <?php endforeach; ?>
        </div>
        <button type="button" id="add-itinerary-day" class="ns-btn-add">➕ افزودن روز جدید</button>
    </div>
</div>