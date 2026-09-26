<?php

namespace NextSafar\MetaBoxes;

use NextSafar\Sync\GeoSchema;

class HospitalMetaBox {
    public static function register() {
        add_meta_box('nextsafar_hospital_info', '🏥 اطلاعات بیمارستان', [__CLASS__, 'render_main'], 'hospital', 'normal', 'high');
        add_meta_box('nextsafar_hospital_hours', '⏰ ساعات کاری', [__CLASS__, 'render_hours'], 'hospital', 'normal', 'default');
        add_meta_box('nextsafar_hospital_api', '🔗 داده‌های API', [__CLASS__, 'render_api'], 'hospital', 'side', 'default');
    }

    public static function register_hooks() {
        add_action('save_post_hospital', [__CLASS__, 'save'], 10, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    public static function enqueue_assets($hook) {
        global $post_type;

        if (($hook !== 'post.php' && $hook !== 'post-new.php') || $post_type !== 'hospital') return;

        wp_enqueue_style('nextsafar-hospital-admin', NEXTSAFAR_URL . 'assets/hospital.css', [], NEXTSAFAR_VERSION);
        wp_enqueue_script('nextsafar-hospital-admin', NEXTSAFAR_URL . 'assets/hospital.js', ['jquery'], NEXTSAFAR_VERSION, true);
    }

    public static function render_main($post) {
        wp_nonce_field('nextsafar_hospital_main', 'nextsafar_hospital_main_nonce');

        require NEXTSAFAR_PATH . 'views/metaboxes/hospital-main.php';
    }

    /**
     * ⭐ Render working hours - multi-slot format
     */
    public static function render_hours($post) {
        wp_nonce_field('nextsafar_hospital_hours', 'nextsafar_hospital_hours_nonce');

        $week_days = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه'];

        $work_time = get_post_meta($post->ID, '_hospital_work_time', true);

        if (!is_array($work_time)) $work_time = [];
        ?>
        <div class="nextsafar-hours-metabox">
            <style>
                .ns-hours-day { margin-bottom: 15px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px; }
                .ns-hours-day-header { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; font-weight: bold; }
                .ns-hours-day-name { min-width: 80px; }
                .ns-hours-slots { padding-left: 20px; }
                .ns-hours-slot { display: flex; gap: 10px; margin-bottom: 5px; align-items: center; }
                .ns-hours-slot input[type="time"] { padding: 4px; }
                .ns-hours-slot .remove-slot { color: #a00; cursor: pointer; padding: 2px 8px; }
                .ns-add-slot { margin-top: 5px; padding: 3px 10px; font-size: 12px; }
            </style>

            <p class="description">⏰ برای هر روز می‌توانید چند بازه زمانی تعریف کنید</p>

            <?php foreach ($week_days as $day) :
                $slots = $work_time[$day] ?? [];

                $option = 'custom';

                if (empty($slots)) {
                    $option = 'custom';
                } elseif (count($slots) === 1) {
                    if (($slots[0]['from'] ?? '') === 'off') $option = 'off';
                    elseif (($slots[0]['from'] ?? '') === '24h') $option = '24h';
                }
            ?>
            <div class="ns-hours-day" data-day="<?= esc_attr($day); ?>">
                <div class="ns-hours-day-header">
                    <span class="ns-hours-day-name"><?= esc_html($day); ?></span>
                    <label>
                        <input type="radio" name="hospital_work_time_option[<?= esc_attr($day); ?>]"
                               value="custom" <?= checked($option, 'custom'); ?> class="ns-hours-option">
                        فعال
                    </label>
                    <label>
                        <input type="radio" name="hospital_work_time_option[<?= esc_attr($day); ?>]"
                               value="off" <?= checked($option, 'off'); ?> class="ns-hours-option">
                        تعطیل
                    </label>
                    <label>
                        <input type="radio" name="hospital_work_time_option[<?= esc_attr($day); ?>]"
                               value="24h" <?= checked($option, '24h'); ?> class="ns-hours-option">
                        ۲۴ ساعته
                    </label>
                </div>
                <div class="ns-hours-slots" style="<?= $option !== 'custom' ? 'display:none;' : ''; ?>">
                    <?php if (empty($slots)) : ?>
                        <div class="ns-hours-slot">
                            <input type="time" name="hospital_work_time[<?= esc_attr($day); ?>][from][]" value="">
                            <span>تا</span>
                            <input type="time" name="hospital_work_time[<?= esc_attr($day); ?>][to][]" value="">
                            <span class="remove-slot" onclick="this.parentElement.remove()">✕</span>
                        </div>
                    <?php else : ?>
                        <?php foreach ($slots as $slot) : ?>
                            <div class="ns-hours-slot">
                                <input type="time" name="hospital_work_time[<?= esc_attr($day); ?>][from][]"
                                       value="<?= esc_attr($slot['from'] ?? ''); ?>">
                                <span>تا</span>
                                <input type="time" name="hospital_work_time[<?= esc_attr($day); ?>][to][]"
                                       value="<?= esc_attr($slot['to'] ?? ''); ?>">
                                <span class="remove-slot" onclick="this.parentElement.remove()">✕</span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <button type="button" class="button ns-add-slot" onclick="addHospitalTimeSlot(this)">
                        ➕ افزودن بازه زمانی
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <script>
            function addHospitalTimeSlot(button) {
                const slotsContainer = button.parentElement;
                const day = slotsContainer.closest('.ns-hours-day').dataset.day;

                const newSlot = document.createElement('div');
                newSlot.className = 'ns-hours-slot';
                newSlot.innerHTML = `
                    <input type="time" name="hospital_work_time[${day}][from][]">
                    <span>تا</span>
                    <input type="time" name="hospital_work_time[${day}][to][]">
                    <span class="remove-slot" onclick="this.parentElement.remove()">✕</span>
                `;

                slotsContainer.insertBefore(newSlot, button);
            }

            document.addEventListener('DOMContentLoaded', function () {
                document.querySelectorAll('.ns-hours-option').forEach(radio => {
                    radio.addEventListener('change', function () {
                        const day = this.closest('.ns-hours-day');
                        const slots = day.querySelector('.ns-hours-slots');

                        if (this.value === 'custom' && this.checked) {
                            slots.style.display = '';
                        } else if (this.checked) {
                            slots.style.display = 'none';
                        }
                    });
                });
            });
        </script>
        <?php
    }

    public static function render_api($post) {
        $external_id = GeoSchema::get($post->ID, 'external_id');
        $source      = GeoSchema::get($post->ID, 'source');
        $last_sync   = GeoSchema::get($post->ID, 'last_sync');
        ?>
        <div class="ns-api-sidebar">
            <p><strong>شناسه خارجی:</strong><br><?= esc_html($external_id ?: '—'); ?></p>
            <p><strong>منبع:</strong><br><?= esc_html($source ?: 'دستی'); ?></p>
            <p><strong>آخرین sync:</strong><br><?= esc_html($last_sync ?: '—'); ?></p>
        </div>
        <?php
    }

    public static function save($post_id, $post) {
        if (isset($_POST['nextsafar_hospital_main_nonce']) && wp_verify_nonce($_POST['nextsafar_hospital_main_nonce'], 'nextsafar_hospital_main')) {
            self::save_main_fields($post_id);
        }

        if (isset($_POST['nextsafar_hospital_hours_nonce']) && wp_verify_nonce($_POST['nextsafar_hospital_hours_nonce'], 'nextsafar_hospital_hours')) {
            self::save_working_hours($post_id);
        }
    }

    private static function save_main_fields($post_id) {
        $text_fields = ['hospital_fax', 'hospital_mail', 'hospital_emergency_phone'];

        foreach ($text_fields as $field) {
            if (isset($_POST[$field])) {
                update_post_meta($post_id, '_' . $field, sanitize_text_field($_POST[$field]));
            }
        }

        if (isset($_POST['hospital_number_of_bed'])) {
            update_post_meta($post_id, '_hospital_number_of_bed', intval($_POST['hospital_number_of_bed']));
        }

        $textareas = [
            'hospital_facilities'       => 'امکانات بیمارستان',
            'hospital_services'         => 'خدمات',
            'hospital_special_services' => 'خدمات ویژه',
            'hospital_key_doctors'      => 'پزشکان برجسته',
            'hospital_insurance'        => 'امکانات بیمه',
            'hospital_languages'        => 'زبان‌های پشتیبانی',
            'hospital_accessibility'    => 'دسترسی‌ها',
            'hospital_more_desc'        => 'توضیحات و توصیه‌ها',
        ];

        foreach ($textareas as $field => $label) {
            if (isset($_POST[$field])) {
                $value = wp_kses_post($_POST[$field]);
                $value = str_replace("\r
", "
", $value);

                update_post_meta($post_id, '_' . $field, $value);
            } else {
                delete_post_meta($post_id, '_' . $field);
            }
        }
    }

    /**
     * ⭐ Save working hours - multi-slot format
     */
    private static function save_working_hours($post_id) {
        $week_days = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه'];

        $work_time = [];

        foreach ($week_days as $day) {
            $option = $_POST['hospital_work_time_option'][$day] ?? '';

            if ($option === 'off') {
                $work_time[$day] = [['from' => 'off', 'to' => 'off']];
            } elseif ($option === '24h') {
                $work_time[$day] = [['from' => '24h', 'to' => '24h']];
            } else {
                $slots = [];

                $froms = $_POST['hospital_work_time'][$day]['from'] ?? [];
                $tos = $_POST['hospital_work_time'][$day]['to'] ?? [];

                if (!is_array($froms)) $froms = [$froms];
                if (!is_array($tos)) $tos = [$tos];

                foreach ($froms as $i => $from) {
                    $to = $tos[$i] ?? '';

                    if (!empty($from) || !empty($to)) {
                        $slots[] = [
                            'from' => sanitize_text_field($from),
                            'to' => sanitize_text_field($to),
                        ];
                    }
                }

                $work_time[$day] = !empty($slots) ? $slots : [];
            }
        }

        update_post_meta($post_id, '_hospital_work_time', $work_time);
    }

    public static function smart_update_meta($post_id, $key, $value, $source = 'api') {
        if ($value === '' || $value === null) return false;

        if (in_array($key, ['_location_coords', '_google_map_coords'], true)) {
            $p = array_map('trim', explode(',', (string) $value));

            if (count($p) >= 2 && $p[0] !== '') {
                GeoSchema::set_latlng($post_id, $p[0], $p[1]);
                GeoSchema::set($post_id, 'source', $source);
                GeoSchema::set($post_id, 'last_sync', current_time('mysql'));

                return true;
            }

            return false;
        }

        $map = [
            '_hospital_name_en'       => 'name_en',
            '_hospital_country'       => 'country',
            '_hospital_city'          => 'city',
            '_hospital_address'       => 'address',
            '_hospital_tell_number'   => 'phone',
            '_hospital_website'       => 'website',
            '_hospital_rating'        => 'rating',
            '_hospital_reviews_count' => 'reviews',
            '_hospital_type'          => 'type',
            '_hospital_external_id'   => 'external_id',
            '_hospital_data_source'   => 'source',
            '_hospital_last_sync'     => 'last_sync',
        ];

        if (isset($map[$key])) {
            GeoSchema::set($post_id, $map[$key], (string) $value);
            GeoSchema::set($post_id, 'source', $source);
            GeoSchema::set($post_id, 'last_sync', current_time('mysql'));

            return true;
        }

        update_post_meta($post_id, $key, $value);

        return true;
    }

    public static function get_hospital_types() {
        return [
            '' => 'انتخاب کنید',
            '1' => 'دولتی',
            '2' => 'تخصصی',
            '3' => 'عمومی',
            '4' => 'خصوصی',
            '5' => 'غیره',
        ];
    }
}