<?php

namespace NextSafar\Account\Admin;

/* ==========================================================================
   Display Custom Fields On User Edit Screen
   ========================================================================== */
add_action('show_user_profile', __NAMESPACE__ . '\\render_custom_fields');
add_action('edit_user_profile', __NAMESPACE__ . '\\render_custom_fields');

/* ==========================================================================
   Display Fields On New User Creation Screen
   ========================================================================== */
add_action('user_new_form', __NAMESPACE__ . '\\render_new_user_fields');

/* ==========================================================================
   Save Changes (Edit + Create)
   ========================================================================== */
add_action('personal_options_update', __NAMESPACE__ . '\\save_custom_fields');
add_action('edit_user_profile_update', __NAMESPACE__ . '\\save_custom_fields');
add_action('user_register', __NAMESPACE__ . '\\save_custom_fields');

/* ==========================================================================
   Validation Before Saving
   ========================================================================== */
add_action('user_profile_update_errors', __NAMESPACE__ . '\\validate_fields', 10, 3);

/* ==========================================================================
   National ID Validation
   ========================================================================== */
function valid_national_id(string $code): bool {
    if (!preg_match('/^\d{10}$/', $code)) return false;
    if (preg_match('/^(\d)\1{9}$/', $code)) return false;

    $sum = 0;

    for ($i = 0; $i < 9; $i++) {
        $sum += ((int) $code[$i]) * (10 - $i);
    }

    $r = $sum % 11;
    $check = (int) $code[9];

    return ($r < 2 && $check === $r) || ($r >= 2 && $check === 11 - $r);
}

/* ==========================================================================
   Jalali Date Validation
   ========================================================================== */
function valid_jalali(string $d): bool {
    return (bool) preg_match('/^1[34]\d{2}\/(0[1-9]|1[0-2])\/(0[1-9]|[12]\d|3[01])$/', $d);
}

/* ==========================================================================
   Field HTML
   ========================================================================== */
function render_custom_fields($user) {
    $phone = get_user_meta($user->ID, 'ns_phone', true);
    $national_id = get_user_meta($user->ID, 'ns_national_id', true);
    $birthdate = get_user_meta($user->ID, 'ns_birthdate', true);
    $phone_verified = get_user_meta($user->ID, 'ns_phone_verified', true);
    $signup_method = get_user_meta($user->ID, 'ns_signup_method', true);
    $google_linked = get_user_meta($user->ID, 'ns_google_linked', true);
    ?>
    <h3 style="margin-top:30px;">اطلاعات تکمیلی سفر بعدی</h3>

    <table class="form-table">
        <tr>
            <th><label for="ns_phone">شماره موبایل</label></th>
            <td>
                <input type="text" name="ns_phone" id="ns_phone"
                       value="<?php echo esc_attr($phone); ?>"
                       class="regular-text" dir="ltr" maxlength="11" />

                <?php if ($phone_verified): ?>
                    <span style="color:#22c55e; margin-inline-start:8px; font-weight:bold;">✓ تایید شده</span>
                <?php endif; ?>

                <p class="description">شماره موبایل ایرانی (مثال: 09123456789)</p>
            </td>
        </tr>

        <tr>
            <th><label for="ns_national_id">کد ملی</label></th>
            <td>
                <input type="text" name="ns_national_id" id="ns_national_id"
                       value="<?php echo esc_attr($national_id); ?>"
                       class="regular-text" dir="ltr" maxlength="10" />

                <p class="description">کد ملی ۱۰ رقمی</p>
            </td>
        </tr>

        <tr>
            <th><label for="ns_birthdate">تاریخ تولد</label></th>
            <td>
                <input type="text" name="ns_birthdate" id="ns_birthdate"
                       value="<?php echo esc_attr($birthdate); ?>"
                       class="regular-text" dir="ltr" placeholder="1375/05/01" />

                <p class="description">فرمت جلالی: YYYY/MM/DD</p>
            </td>
        </tr>

        <tr>
            <th>روش ثبت‌نام</th>
            <td>
                <?php
                $method = $signup_method ?: 'نامشخص';

                $method_fa = [
                    'phone' => '📱 شماره موبایل',
                    'google' => '🔵 حساب گوگل',
                    'email' => '✉️ ایمیل',
                ][$method] ?? $method;

                echo esc_html($method_fa);

                if ($google_linked) {
                    echo '<br><small style="color:#666;">متصل به گوگل از ' . esc_html($google_linked) . '</small>';
                }
                ?>
            </td>
        </tr>
    </table>
    <?php
}

/* ==========================================================================
   Fields On New User Creation Screen
   ========================================================================== */
function render_new_user_fields($operation) {
    ?>
    <table class="form-table" id="ns-new-user-fields">
        <tr>
            <th><label for="ns_phone">شماره موبایل</label></th>
            <td>
                <input type="text" name="ns_phone" id="ns_phone"
                       class="regular-text" dir="ltr" maxlength="11"
                       placeholder="09123456789" />
            </td>
        </tr>

        <tr>
            <th><label for="ns_national_id">کد ملی</label></th>
            <td>
                <input type="text" name="ns_national_id" id="ns_national_id"
                       class="regular-text" dir="ltr" maxlength="10" />
            </td>
        </tr>

        <tr>
            <th><label for="ns_birthdate">تاریخ تولد</label></th>
            <td>
                <input type="text" name="ns_birthdate" id="ns_birthdate"
                       class="regular-text" dir="ltr" placeholder="1375/05/01" />

                <p class="description">فرمت جلالی: YYYY/MM/DD</p>
            </td>
        </tr>
    </table>
    <?php
}

/* ==========================================================================
   Save Fields (Edit + New User Creation)
   ========================================================================== */
function save_custom_fields($user_id) {
    if (!current_user_can('edit_user', $user_id)) return;

    if (isset($_POST['ns_phone'])) {
        $phone = sanitize_text_field($_POST['ns_phone']);

        if ($phone && !preg_match('/^09\d{9}$/', $phone)) {
            // Ignore invalid phone number.
        } else {
            update_user_meta($user_id, 'ns_phone', $phone);

            // If an admin changes it manually, mark it as verified.
            if (current_user_can('manage_options') && $phone) {
                update_user_meta($user_id, 'ns_phone_verified', current_time('mysql'));
            }
        }
    }

    if (isset($_POST['ns_national_id'])) {
        $national_id = sanitize_text_field($_POST['ns_national_id']);
        update_user_meta($user_id, 'ns_national_id', $national_id);
    }

    if (isset($_POST['ns_birthdate'])) {
        $birthdate = sanitize_text_field($_POST['ns_birthdate']);
        update_user_meta($user_id, 'ns_birthdate', $birthdate);
    }
}

/* ==========================================================================
   Validation Before Saving
   ========================================================================== */
function validate_fields($errors, $update, $user) {
    if (!empty($_POST['ns_national_id'])) {
        $code = sanitize_text_field($_POST['ns_national_id']);

        if (!valid_national_id($code)) {
            $errors->add(
                'invalid_national_id',
                '<strong>خطای کد ملی:</strong> کد ملی باید ۱۰ رقمی و معتبر باشد.'
            );
        }
    }

    if (!empty($_POST['ns_birthdate'])) {
        $d = sanitize_text_field($_POST['ns_birthdate']);

        if (!valid_jalali($d)) {
            $errors->add(
                'invalid_birthdate',
                '<strong>خطای تاریخ تولد:</strong> فرمت صحیح YYYY/MM/DD است (مثال: 1375/05/01).'
            );
        }
    }

    if (!empty($_POST['ns_phone'])) {
        $phone = sanitize_text_field($_POST['ns_phone']);

        if (!preg_match('/^09\d{9}$/', $phone)) {
            $errors->add(
                'invalid_phone',
                '<strong>خطای شماره موبایل:</strong> شماره باید ۱۱ رقمی و با 09 شروع شود.'
            );
        }
    }
}