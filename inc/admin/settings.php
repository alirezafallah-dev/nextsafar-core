<?php
/**
 * NextSafar Settings - News Settings Only
 * API keys moved to post-generator.php
 */

namespace NextSafar\Admin;

if (!defined('ABSPATH')) exit;

class Settings {
    public static function render_settings_page(): void {
        /* ========================================================================
           Save News Settings
           ======================================================================== */
        if (isset($_POST['save_news_settings']) && check_admin_referer('nextsafar_news_settings')) {
            update_option('nextsafar_news_auto', isset($_POST['news_auto']) ? '1' : '0');

            $hours = [];
            if (isset($_POST['sync_hours'])) {
                foreach ($_POST['sync_hours'] as $h) {
                    $h = absint($h);
                    if ($h >= 0 && $h <= 23) {
                        $hours[] = $h;
                    }
                }
            }
            update_option('nextsafar_news_sync_hours', $hours);

            update_option('nextsafar_news_max_publish_per_run', absint($_POST['max_publish_per_run'] ?? 5));
            update_option('nextsafar_news_max_draft_per_run', absint($_POST['max_draft_per_run'] ?? 3));
            update_option('nextsafar_news_max_ai_review_per_run', absint($_POST['max_ai_review_per_run'] ?? 2));
            update_option('nextsafar_news_time_window_hours', absint($_POST['time_window_hours'] ?? 24));

            echo '<div class="updated"><p>تنظیمات اخبار ذخیره شد.</p></div>';
        }

        $news_auto = get_option('nextsafar_news_auto', '0');
        $sync_hours = get_option('nextsafar_news_sync_hours', [8, 12, 18]);
        $max_publish = get_option('nextsafar_news_max_publish_per_run', 5);
        $max_draft = get_option('nextsafar_news_max_draft_per_run', 3);
        $max_ai_review = get_option('nextsafar_news_max_ai_review_per_run', 2);
        $time_window = get_option('nextsafar_news_time_window_hours', 24);
        ?>
        <div class="wrap">
            <h1>تنظیمات عمومی اخبار</h1>

            <!-- News Settings -->
            <div style="background:#fff;padding:24px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,0.1);margin-bottom:20px;">
                <h2>تنظیمات دریافت اخبار</h2>
                <form method="post">
                    <?php wp_nonce_field('nextsafar_news_settings'); ?>
                    <table class="form-table">
                        <tr>
                            <th>دریافت خودکار:</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="news_auto" value="1" <?php checked($news_auto, '1'); ?>>
                                    دریافت خودکار اخبار فعال باشد
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th>ساعت‌های دریافت:</th>
                            <td>
                                <div style="display:grid;grid-template-columns:repeat(8,1fr);gap:5px;">
                                    <?php for ($i = 0; $i < 24; $i++): ?>
                                        <label style="display:flex;align-items:center;gap:4px;">
                                            <input type="checkbox" name="sync_hours[]" value="<?php echo $i; ?>" <?php checked(in_array($i, (array)$sync_hours)); ?>>
                                            <?php echo str_pad($i, 2, '0', STR_PAD_LEFT); ?>
                                        </label>
                                    <?php endfor; ?>
                                </div>
                                <p class="description">ساعت‌هایی که اخبار به صورت خودکار دریافت شود</p>
                            </td>
                        </tr>
                        <tr>
                            <th>حداکثر انتشار در هر اجرا:</th>
                            <td>
                                <input type="number" name="max_publish_per_run" value="<?php echo esc_attr($max_publish); ?>" min="1" max="50" class="small-text">
                            </td>
                        </tr>
                        <tr>
                            <th>حداکثر پیش‌نویس در هر اجرا:</th>
                            <td>
                                <input type="number" name="max_draft_per_run" value="<?php echo esc_attr($max_draft); ?>" min="0" max="50" class="small-text">
                            </td>
                        </tr>
                        <tr>
                            <th>حداکثر بررسی با هوش مصنوعی در هر اجرا:</th>
                            <td>
                                <input type="number" name="max_ai_review_per_run" value="<?php echo esc_attr($max_ai_review); ?>" min="0" max="20" class="small-text">
                            </td>
                        </tr>
                        <tr>
                            <th>بازه زمانی اخبار (ساعت):</th>
                            <td>
                                <input type="number" name="time_window_hours" value="<?php echo esc_attr($time_window); ?>" min="1" max="168" class="small-text">
                                <p class="description">فقط اخبار این تعداد ساعت اخیر دریافت شوند</p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" name="save_news_settings" class="button button-primary">ذخیره تنظیمات اخبار</button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }
}