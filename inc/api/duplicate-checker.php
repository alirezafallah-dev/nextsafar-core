<?php

/**
 * DuplicateChecker — Multi-level duplicate detection for news
 * @version 2.3.0 - Fixed Duplicate Entry + INSERT IGNORE
 */

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

class DuplicateChecker {
    const SIMILARITY_THRESHOLD = 0.75;

    /* ==========================================================================
       Filter Duplicates
       ========================================================================== */
    public function filter_duplicates(array $news_items): array {
        $this->ensure_table_exists();

        $unique = [];
        $seen_in_batch = [];

        foreach ($news_items as $item) {
            /* Level 1: Check within batch */
            $url_hash = md5($this->normalize_url($item['link'] ?? ''));
            $title_hash = md5($this->normalize_title($item['title'] ?? ''));

            if (isset($seen_in_batch[$url_hash]) || isset($seen_in_batch[$title_hash])) {
                error_log("🔍 Batch duplicate skipped: " . mb_substr($item['title'], 0, 50));
                continue;
            }

            /* Level 2: Fuzzy check within batch */
            if ($this->is_similar_in_batch($item, array_values($seen_in_batch))) {
                error_log("🔍 Batch fuzzy duplicate skipped: " . mb_substr($item['title'], 0, 50));
                continue;
            }

            /* Level 3: Check in database */
            if ($this->is_duplicate($item)) {
                continue;
            }

            $seen_in_batch[$url_hash] = $item;
            $seen_in_batch[$title_hash] = $item;
            $unique[] = $item;
        }

        return $unique;
    }

    /* ==========================================================================
       Batch Similarity Check
       ========================================================================== */
    private function is_similar_in_batch(array $item, array $seen_items): bool {
        $item_normalized = $this->normalize_title($item['title'] ?? '');

        foreach ($seen_items as $seen_item) {
            $seen_normalized = $this->normalize_title($seen_item['title'] ?? '');

            if ($this->calculate_similarity($item_normalized, $seen_normalized) >= self::SIMILARITY_THRESHOLD) {
                return true;
            }
        }

        return false;
    }

    /* ==========================================================================
       Similarity Calculation (Jaccard)
       ========================================================================== */
    private function calculate_similarity(string $text1, string $text2): float {
        if ($text1 === $text2) return 1.0;
        if (empty($text1) || empty($text2)) return 0.0;

        $words1 = array_unique(array_filter(explode(' ', $text1)));
        $words2 = array_unique(array_filter(explode(' ', $text2)));

        if (empty($words1) || empty($words2)) return 0.0;

        return count(array_intersect($words1, $words2)) / count(array_unique(array_merge($words1, $words2)));
    }

    /* ==========================================================================
       Database Duplicate Check
       ========================================================================== */
    public function is_duplicate(array $item): bool {
        global $wpdb;

        $table = $wpdb->prefix . 'ns_news_duplicates';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return false;
        }

        /* Level 1: URL */
        if (!empty($item['link'])) {
            $url_hash = md5($this->normalize_url($item['link']));

            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE url_hash = %s LIMIT 1",
                $url_hash
            ));

            if ($exists) return true;
        }

        /* Level 2: GUID */
        if (!empty($item['guid']) && $item['guid'] !== ($item['link'] ?? '')) {
            $guid_hash = md5($item['guid']);

            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table} WHERE guid_hash = %s LIMIT 1",
                $guid_hash
            ));

            if ($exists) return true;
        }

        /* Level 3: Title */
        $title_hash = md5($this->normalize_title($item['title'] ?? ''));

        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE title_hash = %s LIMIT 1",
            $title_hash
        ));

        if ($exists) return true;

        /* Level 4: wp_postmeta */
        if (!empty($item['link'])) {
            $post_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_ns_source_url' AND meta_value = %s LIMIT 1",
                $item['link']
            ));

            if ($post_exists) return true;
        }

        /* Level 5: Similar recent posts */
        if ($this->find_similar_post($item['title'] ?? '')) {
            return true;
        }

        return false;
    }

    /* ==========================================================================
       Find Similar Recent Post
       ========================================================================== */
    private function find_similar_post(string $title): bool {
        global $wpdb;

        $normalized = $this->normalize_title($title);
        $words = array_filter(explode(' ', $normalized));

        if (count($words) < 3) return false;

        $recent_posts = $wpdb->get_col(
            "SELECT post_title FROM {$wpdb->posts}
             WHERE post_type = 'travelnews' AND post_status IN ('publish','draft')
             AND post_date >= DATE_SUB(NOW(), INTERVAL 24 HOUR) LIMIT 200"
        );

        foreach ($recent_posts as $post_title) {
            if ($this->calculate_similarity($normalized, $this->normalize_title($post_title)) >= self::SIMILARITY_THRESHOLD) {
                return true;
            }
        }

        return false;
    }

    /* ==========================================================================
       Mark as Filtered
       ========================================================================== */
    public function mark_as_filtered(array $item): void {
        global $wpdb;

        $table = $wpdb->prefix . 'ns_news_duplicates';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return;

        $url_hash = md5($this->normalize_url($item['link'] ?? ''));
        $title_hash = md5($this->normalize_title($item['title'] ?? ''));
        $guid_hash = !empty($item['guid']) ? md5($item['guid']) : null;

        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table}
             (url_hash, title_hash, guid_hash, post_id, source_name, fetched_at)
             VALUES (%s, %s, %s, %d, %s, NOW())",
            $url_hash, $title_hash, $guid_hash, 0, $item['source_name'] ?? 'filtered'
        ));
    }

    /* ==========================================================================
       🆕 Save with INSERT IGNORE (Prevent Duplicate Entry Error)
       ========================================================================== */
    public function mark_as_saved(array $item, int $post_id): void {
        global $wpdb;

        $table = $wpdb->prefix . 'ns_news_duplicates';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            return;
        }

        $url_hash = md5($this->normalize_url($item['link'] ?? ''));
        $title_hash = md5($this->normalize_title($item['title'] ?? ''));
        $guid_hash = !empty($item['guid']) ? md5($item['guid']) : null;

        // ✅ Use INSERT IGNORE instead of plain INSERT
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table}
             (url_hash, title_hash, guid_hash, post_id, source_name, fetched_at)
             VALUES (%s, %s, %s, %d, %s, NOW())",
            $url_hash,
            $title_hash,
            $guid_hash,
            $post_id,
            $item['source_name'] ?? 'unknown'
        ));
    }

    /* ==========================================================================
       URL Normalization
       ========================================================================== */
    private function normalize_url(string $url): string {
        if (empty($url)) return '';

        $parsed = parse_url($url);

        $base = ($parsed['scheme'] ?? 'https') . '://' . strtolower($parsed['host'] ?? '') . ($parsed['path'] ?? '');

        $query = $parsed['query'] ?? '';

        if (!empty($query)) {
            parse_str($query, $params);

            unset(
                $params['utm_source'], $params['utm_medium'], $params['utm_campaign'],
                $params['utm_content'], $params['utm_term'], $params['ref'],
                $params['fbclid'], $params['gclid']
            );

            if (!empty($params)) $base .= '?' . http_build_query($params);
        }

        return $base;
    }

    /* ==========================================================================
       Title Normalization
       ========================================================================== */
    private function normalize_title(string $title): string {
        if (empty($title)) return '';

        $title = mb_strtolower($title, 'UTF-8');

        // Remove diacritics
        $title = preg_replace('/[\x{064B}-\x{065F}\x{0670}]/u', '', $title);

        // Normalize Arabic characters to Persian
        $title = str_replace(['ي','ك','ۀ','ة','ـ'], ['ی','ک','ه','ه',''], $title);

        // Remove non-alphanumeric characters
        $title = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $title);

        // Remove stop words
        $stop_words = ['و','در','به','از','که','این','آن','است','بود','شد','های','ای','را','با','برای','the','a','an','is','are','was','were'];

        $words = array_filter(preg_split('/\s+/', $title), function($w) use ($stop_words) {
            $w = trim($w);
            return !in_array($w, $stop_words) && mb_strlen($w) > 1;
        });

        return implode(' ', $words);
    }

    /* ==========================================================================
       Cleanup Old Hashes
       ========================================================================== */
    public function cleanup_old_hashes(): int {
        global $wpdb;

        $table = $wpdb->prefix . 'ns_news_duplicates';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return 0;

        return (int) $wpdb->query("DELETE FROM {$table} WHERE fetched_at < DATE_SUB(NOW(), INTERVAL 3 MONTH)");
    }

    /* ==========================================================================
       Ensure Table Exists
       ========================================================================== */
    private function ensure_table_exists(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'ns_news_duplicates';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            \NextSafar\Database\NewsTables::create_duplicates_table();
        }
    }
}