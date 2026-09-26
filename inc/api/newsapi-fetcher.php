<?php

/**
 * NextSafar News API Fetcher — Version 4.0
 * ✅ FIX: NewsData.io size=10 (max free plan)
 * ✅ FIX: Currents API endpoint corrected
 * ✅ FIX: Source stats updated in ns_news_sources table
 * ✅ FIX: More precise rate limiting
 */

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/rate-limiter.php';

class NewsApiFetcher {
    private $timeout = 30;

    public function fetch_all(): array {
        $all_news = [];

        // GNews.io
        $gnews_items = $this->fetch_gnews();
        $all_news = array_merge($all_news, $gnews_items);

        // NewsData.io
        $newsdata_items = $this->fetch_newsdata();
        $all_news = array_merge($all_news, $newsdata_items);

        // Currents API
        $currents_items = $this->fetch_currents();
        $all_news = array_merge($all_news, $currents_items);

        return $all_news;
    }

    /* ========================================================================
       Update source stats in the database table
       ======================================================================== */
    private function update_source_stats(string $source_name, int $fetched_count, int $duplicate_count = 0, ?string $error = null): void {
        global $wpdb;

        $table = $wpdb->prefix . 'ns_news_sources';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return;

        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, total_fetched, total_duplicates FROM {$table} WHERE name = %s",
            $source_name
        ));

        if ($existing) {
            $update_data = [
                'last_fetch'       => current_time('mysql'),
                'total_fetched'    => (int) $existing->total_fetched + $fetched_count,
                'total_duplicates' => (int) $existing->total_duplicates + $duplicate_count,
            ];

            if ($error) {
                $update_data['error_message'] = mb_substr($error, 0, 500);
            } else {
                $update_data['last_success'] = current_time('mysql');
                $update_data['error_message'] = null;
            }

            $wpdb->update($table, $update_data, ['id' => $existing->id]);
        }
    }

    /* ========================================================================
       GNews.io
       ======================================================================== */
    private function fetch_gnews(): array {
        $api_key = get_option('nextsafar_gnews_api_key', '');

        if (empty($api_key)) {
            $this->update_source_stats('GNews.io', 0, 0, 'API key not set');
            error_log('⚠️ GNews.io: API key not set');
            return [];
        }

        RateLimiter::wait_if_needed('gnews', 2.0); // 2 seconds between requests

        // First attempt: Persian
        $items = $this->fetch_gnews_with_lang($api_key, 'fa', 'ir');

        // If no results, try English
        if (empty($items)) {
            $items = $this->fetch_gnews_with_lang($api_key, 'en', '');
        }

        if (empty($items)) {
            $this->update_source_stats('GNews.io', 0, 0, 'No articles returned');
        } else {
            $this->update_source_stats('GNews.io', count($items));
        }

        return $items;
    }

    private function fetch_gnews_with_lang(string $api_key, string $lang, string $country): array {
        $params = [
            'q'      => 'tourism OR travel OR hotel OR tour OR flight OR destination',
            'lang'   => $lang,
            'max'    => 10,  // ✅ Max for GNews free plan
            'apikey' => $api_key,
        ];

        if (!empty($country)) {
            $params['country'] = $country;
        }

        $url = 'https://gnews.io/api/v4/search?' . http_build_query($params);

        $response = wp_remote_get($url, [
            'timeout'   => $this->timeout,
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            error_log('❌ GNews.io HTTP Error: ' . $response->get_error_message());
            return [];
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $error = $body['message'] ?? "HTTP {$code}";
            error_log("❌ GNews.io ({$lang}): {$error}");
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($data['articles'])) {
            error_log("⚠️ GNews.io ({$lang}): no articles");
            return [];
        }

        $items = [];

        foreach ($data['articles'] as $article) {
            $items[] = [
                'source_name'  => 'GNews.io',
                'source_id'    => 0,
                'source_group' => 'api',
                'title'        => $article['title'] ?? '',
                'link'         => $article['url'] ?? '',
                'guid'         => $article['url'] ?? '',
                'excerpt'      => $article['description'] ?? '',
                'content'      => trim(($article['title'] ?? '') . "
" . ($article['description'] ?? '') . "
" . ($article['content'] ?? '')),
                'image'        => $article['image'] ?? '',
                'pub_date'     => !empty($article['publishedAt']) ? date('Y-m-d H:i:s', strtotime($article['publishedAt'])) : current_time('mysql'),
                'author'       => $article['source']['name'] ?? 'GNews',
                'categories'   => [],
                'fetch_type'   => 'api',
            ];
        }

        error_log('🌐 GNews.io (' . $lang . '): ' . count($items) . ' items');

        return $items;
    }

    /* ========================================================================
       NewsData.io
       ======================================================================== */
    private function fetch_newsdata(): array {
        $api_key = get_option('nextsafar_newsdata_api_key', '');

        if (empty($api_key)) {
            $this->update_source_stats('NewsData.io', 0, 0, 'API key not set');
            error_log('⚠️ NewsData.io: API key not set');
            return [];
        }

        RateLimiter::wait_if_needed('newsdata', 2.0);

        // First attempt: Persian
        $items = $this->fetch_newsdata_with_lang($api_key, 'fa');

        // If no results, try English
        if (empty($items)) {
            $items = $this->fetch_newsdata_with_lang($api_key, 'en');
        }

        if (empty($items)) {
            $this->update_source_stats('NewsData.io', 0, 0, 'No results returned');
        } else {
            $this->update_source_stats('NewsData.io', count($items));
        }

        return $items;
    }

    private function fetch_newsdata_with_lang(string $api_key, string $lang): array {
        // ✅ FIX: size must be 10 (max for NewsData.io free plan)
        $params = [
            'q'        => 'tourism OR travel OR hotel OR tour',
            'language' => $lang,
            'size'     => 10,  // ✅ FIX: changed from 20 to 10
            'apikey'   => $api_key,
        ];

        $url = 'https://newsdata.io/api/1/latest?' . http_build_query($params);

        $response = wp_remote_get($url, [
            'timeout'   => $this->timeout,
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            error_log('❌ NewsData.io HTTP Error: ' . $response->get_error_message());
            return [];
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $error = $body['results']['message'] ?? ($body['message'] ?? "HTTP {$code}");
            error_log("❌ NewsData.io ({$lang}): {$error}");
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($data['results'])) {
            error_log("⚠️ NewsData.io ({$lang}): no results");
            return [];
        }

        $items = [];

        foreach ($data['results'] as $article) {
            $items[] = [
                'source_name'  => 'NewsData.io',
                'source_id'    => 0,
                'source_group' => 'api',
                'title'        => $article['title'] ?? '',
                'link'         => $article['link'] ?? '',
                'guid'         => $article['link'] ?? '',
                'excerpt'      => $article['description'] ?? '',
                'content'      => trim(($article['title'] ?? '') . "
" . ($article['description'] ?? '')),
                'image'        => $article['image_url'] ?? '',
                'pub_date'     => !empty($article['pubDate']) ? date('Y-m-d H:i:s', strtotime($article['pubDate'])) : current_time('mysql'),
                'author'       => $article['source_id'] ?? 'NewsData',
                'categories'   => $article['category'] ?? [],
                'fetch_type'   => 'api',
            ];
        }

        error_log('🌐 NewsData.io (' . $lang . '): ' . count($items) . ' items');

        return $items;
    }

    /* ========================================================================
       Currents API
       ======================================================================== */
    private function fetch_currents(): array {
        $api_key = get_option('nextsafar_currents_api_key', '');

        if (empty($api_key)) {
            $this->update_source_stats('Currents API', 0, 0, 'API key not set');
            error_log('⚠️ Currents API: API key not set');
            return [];
        }

        RateLimiter::wait_if_needed('currents', 2.0);

        // ✅ FIX: Use latest-news endpoint instead of search
        $items = $this->fetch_currents_latest($api_key);

        // If no results, try search
        if (empty($items)) {
            $items = $this->fetch_currents_search($api_key);
        }

        if (empty($items)) {
            $this->update_source_stats('Currents API', 0, 0, 'No news returned');
        } else {
            $this->update_source_stats('Currents API', count($items));
        }

        return $items;
    }

    /* ✅ FIX: Use latest-news endpoint */
    private function fetch_currents_latest(string $api_key): array {
        $params = [
            'apiKey'   => $api_key,
            'language' => 'en',  // Currents free plan only supports English
        ];

        $url = 'https://api.currentsapi.services/v1/latest-news?' . http_build_query($params);

        $response = wp_remote_get($url, [
            'timeout'   => $this->timeout,
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            error_log('❌ Currents API (latest) HTTP Error: ' . $response->get_error_message());
            return [];
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $error = $body['message'] ?? "HTTP {$code}";
            error_log("❌ Currents API (latest): {$error}");
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($data['news'])) {
            error_log('⚠️ Currents API (latest): no news');
            return [];
        }

        // Filter travel-related news
        $filtered_items = $this->filter_currents_travel_news($data['news']);

        return $this->parse_currents_response($filtered_items);
    }

    private function fetch_currents_search(string $api_key): array {
        $params = [
            'apiKey'   => $api_key,
            'language' => 'en',
            'keywords' => 'travel tourism hotel tour vacation flight',
        ];

        $url = 'https://api.currentsapi.services/v1/search?' . http_build_query($params);

        $response = wp_remote_get($url, [
            'timeout'   => $this->timeout,
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code !== 200) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($data['news'])) {
            return [];
        }

        return $this->parse_currents_response($data['news']);
    }

    /* Filter travel-related news from Currents API */
    private function filter_currents_travel_news(array $news): array {
        $travel_keywords = [
            'travel', 'tourism', 'tourist', 'hotel', 'resort', 'flight',
            'airline', 'airport', 'destination', 'vacation', 'holiday',
            'beach', 'cruise', 'tour', 'adventure', 'backpack', 'hostel',
            'restaurant', 'museum', 'attraction', 'landmark', 'heritage'
        ];

        $filtered = [];

        foreach ($news as $article) {
            $text = strtolower(($article['title'] ?? '') . ' ' . ($article['description'] ?? ''));

            foreach ($travel_keywords as $keyword) {
                if (strpos($text, $keyword) !== false) {
                    $filtered[] = $article;
                    break;
                }
            }
        }

        return $filtered;
    }

    private function parse_currents_response(array $news): array {
        $items = [];

        foreach ($news as $article) {
            $items[] = [
                'source_name'  => 'Currents API',
                'source_id'    => 0,
                'source_group' => 'api',
                'title'        => $article['title'] ?? '',
                'link'         => $article['url'] ?? '',
                'guid'         => $article['url'] ?? '',
                'excerpt'      => $article['description'] ?? '',
                'content'      => trim(($article['title'] ?? '') . "
" . ($article['description'] ?? '')),
                'image'        => $article['image'] ?? '',
                'pub_date'     => !empty($article['published']) ? date('Y-m-d H:i:s', strtotime($article['published'])) : current_time('mysql'),
                'author'       => $article['author'] ?? 'Currents',
                'categories'   => [],
                'fetch_type'   => 'api',
            ];
        }

        error_log('🌐 Currents API: ' . count($items) . ' items');

        return $items;
    }
}