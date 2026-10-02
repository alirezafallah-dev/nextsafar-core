<?php
/**
 * NextSafar RSS Fetcher
 * Fetches news from RSS feeds with time window filtering
 */

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/rate-limiter.php';

// ✅ FIX: Load SimplePie from WordPress core
if (!class_exists('SimplePie')) {
    if (file_exists(ABSPATH . WPINC . '/class-simplepie.php')) {
        require_once ABSPATH . WPINC . '/class-simplepie.php';
    } elseif (file_exists(ABSPATH . WPINC . '/SimplePie/autoload.php')) {
        require_once ABSPATH . WPINC . '/SimplePie/autoload.php';
    }
}

class RSSFetcher {
    // ... بقیه کد بدون تغییر
    private $timeout = 20;
    private $user_agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36 NextSafar/3.0';
    private $cutoff_ts = 0;

    /* ========================================================================
       Fetch All Feeds
       ======================================================================== */
    public function fetch_all(int $max_age_hours = 12): array {
        global $wpdb;

        $table = $wpdb->prefix . 'ns_news_sources';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            error_log('ns_news_sources table does not exist');
            return [];
        }

        $this->cutoff_ts = time() - max(1, $max_age_hours) * HOUR_IN_SECONDS;

        $feeds = $wpdb->get_results(
            "SELECT * FROM {$table} WHERE type = 'rss' AND is_active = 1 ORDER BY priority DESC"
        );

        $all_news = [];
        $errors_count = 0;
        $max_errors = 5;

        foreach ($feeds as $feed) {
            if ($errors_count >= $max_errors) {
                error_log('Too many RSS errors, stopping fetch');
                break;
            }

            try {
                RateLimiter::wait_if_needed('rss', 1.0);

                $items = $this->fetch_feed($feed->url, $feed->name);

                $all_news = array_merge($all_news, $items);

                $wpdb->update($table, [
                    'last_fetch'    => current_time('mysql'),
                    'last_success'  => current_time('mysql'),
                    'total_fetched' => (int) $feed->total_fetched + count($items),
                    'error_message' => null,
                ], ['id' => $feed->id]);
            } catch (\Exception $e) {
                $errors_count++;

                $wpdb->update($table, [
                    'last_fetch'    => current_time('mysql'),
                    'error_message' => mb_substr($e->getMessage(), 0, 500),
                ], ['id' => $feed->id]);

                error_log("RSS Fetch Error [{$feed->name}]: " . $e->getMessage());
            }
        }

        error_log('RSS Fetch completed: ' . count($all_news) . ' items from ' . count($feeds) . ' feeds');

        return $all_news;
    }

    /* ========================================================================
       Fetch Single Feed
       ======================================================================== */
    private function fetch_feed(string $url, string $source_name): array {
        $items = [];

        // Browser-like headers to avoid 403 errors
        $response = wp_remote_get($url, [
            'timeout'    => $this->timeout,
            'user-agent' => $this->user_agent,
            'headers'    => [
                'Accept'          => 'application/rss+xml, application/xml, text/xml, */*',
                'Accept-Language' => 'en-US,en;q=0.9,fa;q=0.8',
            ],
        ]);

        if (is_wp_error($response)) {
            error_log('RSS Fetch Error [' . $source_name . ']: ' . $response->get_error_message());
            return [];
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            error_log('RSS Fetch Error [' . $source_name . ']: HTTP ' . $code);
            return [];
        }

        $body = wp_remote_retrieve_body($response);

        if (empty($body)) {
            error_log('RSS Feed empty [' . $source_name . ']');
            return [];
        }

        // Parse with SimplePie
        $feed = new \SimplePie();
        $feed->set_raw_data($body);
        $feed->enable_cache(false);
        $feed->init();

        if ($feed->error()) {
            error_log('RSS Parse Error [' . $source_name . ']: ' . $feed->error());
            return [];
        }

        foreach ($feed->get_items() as $item) {
            $pub_date = $item->get_date('Y-m-d H:i:s');

            // Skip items outside time window
            if (!$this->in_window($pub_date)) continue;

            $content = $item->get_content();

            $items[] = [
                'title'        => $this->clean_text($item->get_title()),
                'content'      => $this->clean_html($content),
                'excerpt'      => $this->extract_excerpt($item->get_description(), $content),
                'link'         => $item->get_link(),
                'pub_date'     => $pub_date,
                'source_name'  => $source_name,
                'source_group' => 'rss',
                'fetch_type'   => 'rss',
                'guid'         => $item->get_id() ?: $item->get_link(),
                'image'        => $this->extract_first_image($item),
                'video'        => $this->extract_video($content),
                'categories'   => $this->extract_categories($item),
            ];
        }

        return $items;
    }

    /* ========================================================================
       Time Window Filter
       ======================================================================== */
    private function in_window(?string $pub_date): bool {
        $ts = $pub_date ? strtotime($pub_date) : time();
        return $ts >= $this->cutoff_ts;
    }

    /* ========================================================================
       Extract First Image
       ======================================================================== */
    private function extract_first_image($item): ?string {
        // Try enclosure
        $enclosure = $item->get_enclosure();

        if ($enclosure && $enclosure->get_type() && strpos($enclosure->get_type(), 'image') !== false) {
            return $enclosure->get_link();
        }

        // Try media thumbnail
        if ($enclosure && $enclosure->get_thumbnail()) {
            return $enclosure->get_thumbnail();
        }

        // Try first img in content
        $content = $item->get_content();

        if (preg_match('/<img[^>]+src=[\'"]([^\'"]+)[\'"]/i', $content, $m)) {
            return $m[1];
        }

        return null;
    }

    /* ========================================================================
       Extract Video
       ======================================================================== */
    private function extract_video(string $content): string {
        if (preg_match('~https?://(?:www\.)?(?:youtube\.com/watch\?v=[\w-]+|youtu\.be/[\w-]+|aparat\.com/v/[\w-]+)~i', $content, $m)) {
            return $m[0];
        }

        return '';
    }

    /* ========================================================================
       Extract Categories
       ======================================================================== */
    private function extract_categories($item): array {
        $cats = [];
        $categories = $item->get_categories();

        if (is_array($categories)) {
            foreach ($categories as $cat) {
                $label = $cat->get_label();
                if (!empty($label)) $cats[] = $label;
            }
        }

        return $cats;
    }

    /* ========================================================================
       Utilities
       ======================================================================== */
    private function extract_excerpt(string $description, string $full_content): string {
        $text = strip_tags(!empty($description) ? $description : $full_content);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = trim(preg_replace('/\s+/', ' ', $text));

        if (mb_strlen($text) > 250) {
            $text = mb_substr($text, 0, 250);
            $sp = mb_strrpos($text, ' ');
            if ($sp !== false) $text = mb_substr($text, 0, $sp);
            $text .= '...';
        }

        return $text;
    }

    private function clean_text(string $text): string {
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        return trim(preg_replace('/\s+/', ' ', strip_tags($text)));
    }

    private function clean_html(string $html): string {
        return wp_kses($html, wp_kses_allowed_html('post'));
    }
}