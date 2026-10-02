<?php
/**
 * NextSafar News Batch Sync
 * Live terminal news sync with batch processing
 */

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

class NewsBatchSync {
    const BATCH_SIZE = 5;
    const STATE_KEY = 'nextsafar_news_batch_state';
    const LOCK_KEY = 'ns_news_batch_lock';

    /* ========================================================================
       Start Sync - Fetch & Prepare
       ======================================================================== */
    public static function start_sync(string $mode = 'quick'): array {
        do_action('nextsafar_skip_revalidation');

        if (get_transient(self::LOCK_KEY)) {
            return ['success' => false, 'message' => 'یک همگام‌سازی اخبار در حال اجرا است.'];
        }

        set_transient(self::LOCK_KEY, time(), 1800);

        try {
            $rss_fetcher = new RSSFetcher();
            $api_fetcher = new NewsApiFetcher();
            $duplicate_checker = new DuplicateChecker();
            $news_filter = new NewsFilter();

            $window = max(1, (int) get_option('nextsafar_news_fetch_window_hours', 12));
            $ai_on = ($mode === 'ai') && get_option('nextsafar_news_ai_enabled', '0') === '1';

            // Stage 1: Fetch RSS
            $rss_items = [];
            try {
                $rss_items = (array) $rss_fetcher->fetch_all($window);
            } catch (\Throwable $e) {
                error_log('RSS fetch failed: ' . $e->getMessage());
            }

            // Stage 2: Fetch API
            $api_items = [];
            try {
                $api_items = (array) $api_fetcher->fetch_all();
            } catch (\Throwable $e) {
                error_log('API fetch failed: ' . $e->getMessage());
            }

            // Stage 3: Merge + time window
            $all = array_merge($rss_items, $api_items);
            $cutoff = time() - $window * HOUR_IN_SECONDS;

            $all = array_filter($all, function ($it) use ($cutoff) {
                $ts = strtotime($it['pub_date'] ?? '');
                return !$ts || $ts >= $cutoff;
            });
            $all = array_values($all);

            $stats = [
                'rss' => count($rss_items),
                'api' => count($api_items),
                'total_fetched' => count($all),
                'duplicates' => 0,
                'filtered' => 0,
            ];

            if (empty($all)) {
                delete_transient(self::LOCK_KEY);
                return [
                    'success' => true,
                    'total' => 0,
                    'message' => 'هیچ خبر جدیدی یافت نشد.',
                    'stats' => $stats,
                ];
            }

            // Stage 4: Deduplicate
            $unique = $all;
            try {
                $unique = $duplicate_checker->filter_duplicates($all);
            } catch (\Throwable $e) {
                error_log('Duplicate checker failed: ' . $e->getMessage());
            }

            $stats['duplicates'] = count($all) - count($unique);

            // Stage 5: Keyword filter
            $passed = [];
            try {
                $passed = $news_filter->filter_items($unique);
            } catch (\Throwable $e) {
                error_log('NewsFilter failed: ' . $e->getMessage());
                $passed = $unique;
            }

            $stats['filtered'] = count($unique) - count($passed);

            if (empty($passed)) {
                delete_transient(self::LOCK_KEY);
                return [
                    'success' => true,
                    'total' => 0,
                    'message' => 'همه اخبار فیلتر شدند.',
                    'stats' => $stats,
                ];
            }

            // Sort by score
            usort($passed, function ($a, $b) {
                return ($b['_filter_result']['score'] ?? 0) <=> ($a['_filter_result']['score'] ?? 0);
            });

            // Save state
            $state = [
                'mode' => $mode,
                'ai_on' => $ai_on,
                'all_items' => array_values($passed),
                'total' => count($passed),
                'processed' => 0,
                'published' => 0,
                'drafts' => 0,
                'failed' => 0,
                'skipped' => 0,
                'ai_used' => 0,
                'current_batch' => 0,
                'status' => 'running',
                'started_at' => current_time('mysql'),
                'stats' => $stats,
            ];

            update_option(self::STATE_KEY, $state, false);

            return [
                'success' => true,
                'total' => count($passed),
                'batch_size' => self::BATCH_SIZE,
                'total_batches' => ceil(count($passed) / self::BATCH_SIZE),
                'stats' => $stats,
                'mode' => $mode,
                'ai_on' => $ai_on,
            ];
        } catch (\Throwable $e) {
            delete_transient(self::LOCK_KEY);
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /* ========================================================================
       Process Next Batch
       ======================================================================== */
    public static function process_next_batch(): array {
        $state = get_option(self::STATE_KEY);

        if (!$state || $state['status'] !== 'running') {
            delete_transient(self::LOCK_KEY);
            return ['completed' => true, 'message' => 'سینک فعالی وجود ندارد'];
        }

        $total = $state['total'];
        $current_batch = $state['current_batch'];
        $batch_start = $current_batch * self::BATCH_SIZE;
        $batch_end = min($batch_start + self::BATCH_SIZE, $total);

        $items = array_slice($state['all_items'], $batch_start, self::BATCH_SIZE);

        if (empty($items)) {
            return self::finish_sync($state);
        }

        $ai_rewriter = new AIRewriter();
        $ai_on = $state['ai_on'];

        $batch_details = [];

        foreach ($items as $index => $item) {
            $title = mb_substr($item['title'] ?? 'News ' . ($batch_start + $index + 1), 0, 45);

            $detail = [
                'index' => $batch_start + $index + 1,
                'name' => $title,
                'status' => 'processing',
                'source' => $item['source_name'] ?? '',
            ];

            try {
                $result = self::save_news_item($item, $ai_on, $ai_rewriter);
                $detail['status'] = $result['status'];

                switch ($result['status']) {
                    case 'published':
                        $state['published']++;
                        if ($result['ai_used']) $state['ai_used']++;
                        break;
                    case 'draft':
                        $state['drafts']++;
                        break;
                    case 'skipped':
                        $state['skipped']++;
                        break;
                    case 'failed':
                        $state['failed']++;
                        break;
                }
            } catch (\Throwable $e) {
                $state['failed']++;
                $detail['status'] = 'failed';
                error_log('News save failed: ' . $e->getMessage());
            }

            $batch_details[] = $detail;
        }

        $state['processed'] = $batch_end;
        $state['current_batch']++;

        update_option(self::STATE_KEY, $state, false);

        $is_completed = $batch_end >= $total;

        if ($is_completed) {
            self::finish_sync($state);
        }

        return [
            'completed' => $is_completed,
            'processed' => $batch_end,
            'total' => $total,
            'progress_percent' => $total > 0 ? round(($batch_end / $total) * 100) : 0,
            'published' => $state['published'],
            'drafts' => $state['drafts'],
            'failed' => $state['failed'],
            'skipped' => $state['skipped'],
            'ai_used' => $state['ai_used'],
            'current_batch' => $state['current_batch'],
            'total_batches' => ceil($total / self::BATCH_SIZE),
            'batch_details' => $batch_details,
        ];
    }

    /* ========================================================================
       Save Single News Item (No Categories)
       ======================================================================== */
    private static function save_news_item(array $item, bool $ai_on, $ai_rewriter): array {
        $duplicate_checker = new DuplicateChecker();

        if ($duplicate_checker->is_duplicate($item)) {
            return ['status' => 'skipped', 'ai_used' => false];
        }

        // ✅ FIX: Extract filter result before processing
        $fr = $item['_filter_result'] ?? [];
        $filter_score = (int) ($fr['score'] ?? 0);
        $filter_decision = sanitize_text_field($fr['decision'] ?? 'review');
        $filter_reason = sanitize_text_field($fr['reason'] ?? '');

        $processed = null;
        $ai_ok = false;

        if ($ai_on) {
            try {
                $processed = $ai_rewriter->process($item);
                $ai_ok = !empty($processed['ai_used']);
            } catch (\Throwable $e) {
                error_log('AIRewriter failed: ' . $e->getMessage());
            }
        }

        $title = $processed['title'] ?? ($item['title'] ?? '');
        $content = $processed['content'] ?? ($item['content'] ?? '');
        $excerpt = $processed['excerpt'] ?? ($item['excerpt'] ?? '');

        if (empty($title) || empty(trim(wp_strip_all_tags($content)))) {
            return ['status' => 'skipped', 'ai_used' => false];
        }

        $content = ImageManager::strip_images_from_content($content);

        $slug = sanitize_title($title);
        if (self::slug_exists($slug)) {
            $slug .= '-' . time();
        }

        $post_status = ($ai_ok || !$ai_on) ? 'publish' : 'draft';

        $post_id = wp_insert_post([
            'post_type' => 'travelnews',
            'post_title' => wp_strip_all_tags($title),
            'post_content' => wp_kses_post($content),
            'post_excerpt' => sanitize_textarea_field($excerpt),
            'post_status' => $post_status,
            'post_author' => 1,
            'post_date' => $item['pub_date'] ?? current_time('mysql'),
            'post_name' => $slug,
        ]);

        if (is_wp_error($post_id)) {
            return ['status' => 'failed', 'ai_used' => false];
        }

        // Save basic meta
        update_post_meta($post_id, '_ns_source_url', esc_url_raw($item['link'] ?? ''));
        update_post_meta($post_id, '_ns_source_name', sanitize_text_field($item['source_name'] ?? ''));
        update_post_meta($post_id, '_ns_source_group', sanitize_text_field($item['source_group'] ?? ''));
        update_post_meta($post_id, '_ns_original_guid', sanitize_text_field($item['guid'] ?? ''));
        update_post_meta($post_id, '_ns_fetch_type', sanitize_text_field($item['fetch_type'] ?? 'unknown'));
        update_post_meta($post_id, '_ns_ai_used', $ai_ok ? '1' : '0');
        update_post_meta($post_id, '_ns_fetched_at', current_time('mysql'));

        // ✅ FIX: Save filter meta data
        update_post_meta($post_id, '_ns_filter_score',    $filter_score);
        update_post_meta($post_id, '_ns_filter_decision', $filter_decision);
        update_post_meta($post_id, '_ns_filter_reason',   $filter_reason);

        if ($post_status === 'draft') {
            update_post_meta($post_id, '_ns_needs_rewrite', '1');
            update_post_meta($post_id, '_ns_original_content', wp_kses_post($item['content'] ?? ''));
            update_post_meta($post_id, '_ns_original_title', sanitize_text_field($item['title'] ?? ''));
        }

        $featured = $item['image'] ?? '';
        if (!empty($featured)) {
            ImageManager::set_featured_image($post_id, $featured, $item['fetch_type'] ?? 'api');
        }

        $duplicate_checker->mark_as_saved($item, $post_id);

        if ($processed) {
            $ai_rewriter->log_final($post_id, $processed);
        }

        return [
            'status' => $post_status === 'publish' ? 'published' : 'draft',
            'ai_used' => $ai_ok,
        ];
    }

    /* ========================================================================
       Helpers
       ======================================================================== */
    private static function slug_exists(string $slug): bool {
        global $wpdb;

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'travelnews' LIMIT 1",
            $slug
        ));

        return !empty($exists);
    }

    private static function finish_sync(array $state): array {
        $state['status'] = 'completed';
        $state['completed_at'] = current_time('mysql');

        update_option(self::STATE_KEY, $state, false);
        delete_transient(self::LOCK_KEY);

        do_action('nextsafar_enable_revalidation');

        return [
            'completed' => true,
            'processed' => $state['total'],
            'total' => $state['total'],
            'progress_percent' => 100,
            'published' => $state['published'],
            'drafts' => $state['drafts'],
            'failed' => $state['failed'],
            'skipped' => $state['skipped'],
            'ai_used' => $state['ai_used'],
            'current_batch' => $state['current_batch'],
            'total_batches' => ceil($state['total'] / self::BATCH_SIZE),
        ];
    }
}