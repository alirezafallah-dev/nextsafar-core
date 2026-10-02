<?php

namespace NextSafar\MetaBoxes;

if (!defined('ABSPATH')) exit;

class TravelNewsMetaBox {
    /**
     * ✅ Main method - directly calls add_meta_box
     * This function is called from add_meta_boxes hook
     */
    public static function register() {
        add_meta_box(
            'nextsafar_news_source_info',
            __('📰 اطلاعات منبع خبر', 'nextsafar'),
            [__CLASS__, 'render_source_info'],
            'travelnews',
            'side',
            'high'
        );

        add_meta_box(
            'nextsafar_news_filter_info',
            __('🤖 اطلاعات فیلتر هوشمند', 'nextsafar'),
            [__CLASS__, 'render_filter_info'],
            'travelnews',
            'side',
            'default'
        );
    }

    /**
     * ✅ Compatibility with register_hooks (if called in init)
     */
    public static function register_hooks() {
        add_action('add_meta_boxes', [__CLASS__, 'register']);
    }

    public static function render_source_info($post) {
        wp_nonce_field('nextsafar_news_meta', 'nextsafar_news_meta_nonce');

        $source_url = get_post_meta($post->ID, '_ns_source_url', true);
        $source_name = get_post_meta($post->ID, '_ns_source_name', true);
        $source_group = get_post_meta($post->ID, '_ns_source_group', true);
        $pub_date = get_post_meta($post->ID, '_ns_original_pub_date', true);
        $image_credit = get_post_meta($post->ID, '_ns_image_credit', true);
        $fetch_type = get_post_meta($post->ID, '_ns_fetch_type', true);
        $ai_used = get_post_meta($post->ID, '_ns_ai_used', true);
        $ai_provider = get_post_meta($post->ID, '_ns_ai_provider', true);
        $fetched_at = get_post_meta($post->ID, '_ns_fetched_at', true);
        ?>
        <div class="ns-news-metabox" style="padding:10px;">
            <p>
                <strong>📡 نام منبع خبر:</strong><br>
                <?php if ($source_name): ?>
                    <span style="color:#00a32a;"><?= esc_html($source_name); ?></span>
                <?php else: ?>
                    <span style="color:#d63638;">نامشخص</span>
                <?php endif; ?>
            </p>

            <p>
                <strong>📂 گروه:</strong><br>
                <?php
                $group_labels = [
                    'travel_specialized' => '🎯 تخصصی گردشگری',
                    'official_news' => '📰 خبرگزاری رسمی',
                    'international' => '🌐 بین‌المللی',
                    'general' => '📋 عمومی',
                ];

                echo $group_labels[$source_group] ?? 'عمومی';
                ?>
            </p>

            <p>
                <strong>🔗 لینک خبر اصلی:</strong><br>
                <?php if ($source_url): ?>
                    <a href="<?= esc_url($source_url); ?>" target="_blank" rel="noopener nofollow" style="word-break:break-all;">
                        <?= esc_html(mb_substr($source_url, 0, 50)); ?>...
                    </a>
                <?php else: ?>
                    <span style="color:#d63638;">لینک ثبت نشده</span>
                <?php endif; ?>
            </p>

            <p>
                <strong>📅 تاریخ انتشار خبر:</strong><br>
                <?php
                if ($pub_date) {
                    echo esc_html($pub_date);
                } else {
                    echo '<span style="color:#d63638;">نامشخص</span>';
                }
                ?>
            </p>

            <p>
                <strong>📷 اعتبار عکس:</strong><br>
                <?php if ($image_credit): ?>
                    <?= esc_html($image_credit); ?>
                <?php else: ?>
                    <span style="color:#999;">ثبت نشده</span>
                <?php endif; ?>
            </p>

            <p>
                <strong>🔄 روش دریافت:</strong><br>
                <?php
                $fetch_labels = [
                    'rss' => '📡 RSS Feed',
                    'api' => '🌐 API',
                ];

                echo $fetch_labels[$fetch_type] ?? 'نامشخص';
                ?>
            </p>

            <p>
                <strong>🤖 بازنویسی با AI:</strong><br>
                <?php if ($ai_used === '1'): ?>
                    <span style="color:#00a32a;">✅ بله (<?= esc_html($ai_provider); ?>)</span>
                <?php else: ?>
                    <span style="color:#999;">❌ خیر</span>
                <?php endif; ?>
            </p>

            <p>
                <strong>🕐 زمان دریافت:</strong><br>
                <?php echo $fetched_at ? esc_html($fetched_at) : 'نامشخص'; ?>
            </p>
        </div>

        <style>
            .ns-news-metabox p {
                margin: 10px 0;
                padding: 8px;
                background: #f8f9fa;
                border-radius: 4px;
                border-right: 3px solid #0073aa;
            }
        </style>
        <?php
    }

    public static function render_filter_info($post) {
        $score = get_post_meta($post->ID, '_ns_filter_score', true);
        $decision = get_post_meta($post->ID, '_ns_filter_decision', true);
        $reason = get_post_meta($post->ID, '_ns_filter_reason', true);

        // ✅ FIX: If score is empty, try to re-analyze from original content
        if ($score === '' || $score === false) {
            $original_content = get_post_meta($post->ID, '_ns_original_content', true);
            $original_title = get_post_meta($post->ID, '_ns_original_title', true);

            if (!empty($original_content) || !empty($original_title)) {
                try {
                    $filter = new \NextSafar\API\NewsFilter();
                    $item = [
                        'title' => $original_title ?: $post->post_title,
                        'content' => $original_content ?: $post->post_content,
                    ];
                    $result = $filter->analyze_item($item);
                    $score = $result['score'] ?? 0;
                    $decision = $result['decision'] ?? 'review';
                    $reason = $result['reason'] ?? '';

                    // Save for future use
                    update_post_meta($post->ID, '_ns_filter_score', (int) $score);
                    update_post_meta($post->ID, '_ns_filter_decision', sanitize_text_field($decision));
                    update_post_meta($post->ID, '_ns_filter_reason', sanitize_text_field($reason));
                } catch (\Throwable $e) {
                    error_log('MetaBox re-analysis failed: ' . $e->getMessage());
                }
            }
        }

        // Ensure score is numeric
        $score = (int) $score;
        ?>
        <div class="ns-filter-metabox" style="padding:10px;">
            <p>
                <strong>📊 امتیاز گردشگری:</strong><br>
                <span style="font-size:24px;font-weight:bold;color:<?= $score >= 0 ? '#00a32a' : '#d63638'; ?>;">
                    <?= $score; ?>
                </span>
            </p>
            <p>
                <strong>✅ تصمیم فیلتر:</strong><br>
                <?php
                $decision_labels = [
                    'publish' => '🟢 انتشار مستقیم',
                    'draft' => '🟡 پیش‌نویس',
                    'review' => '🔵 نیاز به بررسی',
                    'delete' => '🔴 حذف شده',
                ];
                echo $decision_labels[$decision] ?? 'نامشخص';
                ?>
            </p>
            <?php if ($reason): ?>
            <p>
                <strong>📝 دلیل:</strong><br>
                <small style="color:#666;"><?= esc_html($reason); ?></small>
            </p>
            <?php endif; ?>
        </div>
        <style>
        .ns-filter-metabox p {
            margin: 10px 0;
            padding: 8px;
            background: #f0f0f1;
            border-radius: 4px;
        }
        </style>
        <?php
    }
}