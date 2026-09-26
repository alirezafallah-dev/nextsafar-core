<?php

/**
 * NextSafar RSS Fetcher — Version 2.2
 * - Time window (Note 1): Items older than X hours are not parsed at all
 * - Video extraction (Note 5)
 * - Fixed og:image and img regexes with capture groups
 */

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

require_once __DIR__ . '/rate-limiter.php';

class RSSFetcher {
    private $timeout = 20;
    private $user_agent = 'NextSafar/1.1 (+https://nextsafar.com)';
    private $cutoff_ts = 0;   /* Time window */

    /* ========================================================================
       Fetch All Feeds
       ======================================================================== */
    public function fetch_all(int $max_age_hours = 12): array {
        global $wpdb;

        $table = $wpdb->prefix . 'ns_news_sources';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            error_log('❌ ns_news_sources table does not exist');
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
                error_log('⚠️ Too many RSS errors, stopping fetch');
                break;
            }

            try {
                RateLimiter::wait_if_needed('rss', 1.0);

                $items = $this->fetch_feed($feed);

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

                error_log("❌ RSS Fetch Error [{$feed->name}]: " . $e->getMessage());
            }
        }

        error_log('✅ RSS Fetch completed: ' . count($all_news) . ' fresh items (window ' . $max_age_hours . 'h) from ' . count($feeds) . ' feeds');

        return $all_news;
    }

    /* ========================================================================
       Fetch Single Feed
       ======================================================================== */
    private function fetch_feed($feed): array {
        $response = wp_remote_get($feed->url, [
            'timeout'    => $this->timeout,
            'user-agent' => $this->user_agent,
            'headers'    => ['Accept' => 'application/rss+xml, application/xml, text/xml'],
            'sslverify'  => false,
        ]);

        if (is_wp_error($response)) throw new \Exception('HTTP Error: ' . $response->get_error_message());

        $status = wp_remote_retrieve_response_code($response);

        if ($status !== 200) throw new \Exception("HTTP {$status}");

        $xml = mb_convert_encoding(wp_remote_retrieve_body($response), 'UTF-8', 'auto');

        libxml_use_internal_errors(true);

        $rss = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);

        if ($rss === false) throw new \Exception('Invalid XML');

        return $this->parse_rss($rss, $feed);
    }

    /* ========================================================================
       Parse RSS/Atom
       ======================================================================== */
    private function parse_rss($rss, $feed): array {
        $items = [];

        if (isset($rss->channel->item)) {
            foreach ($rss->channel->item as $item) {
                $p = $this->parse_rss_item($item, $feed);
                if ($p) $items[] = $p;
            }
        } elseif (isset($rss->entry)) {
            foreach ($rss->entry as $entry) {
                $p = $this->parse_atom_entry($entry, $feed);
                if ($p) $items[] = $p;
            }
        }

        return $items;
    }

    /* ========================================================================
       Items Older Than Time Window Are Rejected
       ======================================================================== */
    private function in_window(?string $pub_date): bool {
        $ts = $pub_date ? strtotime($pub_date) : time();

        return $ts >= $this->cutoff_ts;
    }

    /* ========================================================================
       Parse RSS Item
       ======================================================================== */
    private function parse_rss_item($item, $feed): ?array {
        $namespaces = $item->getNameSpaces(true);

        $dc      = isset($namespaces['dc'])      ? $item->children($namespaces['dc'])      : null;
        $media   = isset($namespaces['media'])   ? $item->children($namespaces['media'])   : null;
        $content = isset($namespaces['content']) ? $item->children($namespaces['content']) : null;

        $link  = (string) $item->link;
        $title = (string) $item->title;

        if (empty($link) || empty($title)) return null;

        $pub_date = (string) $item->pubDate;

        if (!$this->in_window($pub_date)) return null;   /* Time window */

        $description     = (string) $item->description;
        $content_encoded = $content ? (string) $content->encoded : '';
        $guid            = (string) $item->guid;

        $image = $this->extract_image($item, $media, $link);

        $full  = !empty($content_encoded) ? $content_encoded : $description;

        return [
            'source_name'  => $feed->name,
            'source_id'    => $feed->id,
            'source_group' => $feed->group_name,
            'title'        => $this->clean_text($title),
            'link'         => trim($link),
            'guid'         => !empty($guid) ? trim($guid) : trim($link),
            'excerpt'      => $this->extract_excerpt($description, $full),
            'content'      => $this->clean_html($full),
            'image'        => $image,
            'video'        => $this->extract_video($item, $media, $full),   /* Note 5 */
            'pub_date'     => date('Y-m-d H:i:s', strtotime($pub_date)),
            'author'       => $dc ? (string) $dc->creator : '',
            'categories'   => $this->extract_categories($item),
            'fetch_type'   => 'rss',
        ];
    }

    /* ========================================================================
       Parse Atom Entry
       ======================================================================== */
    private function parse_atom_entry($entry, $feed): ?array {
        $link = '';

        foreach ($entry->link as $l) {
            if ((string) $l['rel'] === 'alternate' || empty((string) $l['rel'])) {
                $link = (string) $l['href'];
                break;
            }
        }

        $title = (string) $entry->title;

        if (empty($link) || empty($title)) return null;

        $pub_date = (string) ($entry->published ?: $entry->updated);

        if (!$this->in_window($pub_date)) return null;   /* Time window */

        $summary = (string) $entry->summary;
        $content = isset($entry->content) ? (string) $entry->content : '';
        $full    = !empty($content) ? $content : $summary;
        $id      = (string) $entry->id;

        return [
            'source_name'  => $feed->name,
            'source_id'    => $feed->id,
            'source_group' => $feed->group_name,
            'title'        => $this->clean_text($title),
            'link'         => trim($link),
            'guid'         => !empty($id) ? trim($id) : trim($link),
            'excerpt'      => $this->extract_excerpt($summary, $full),
            'content'      => $this->clean_html($full),
            'image'        => $this->fetch_og_image($link),
            'video'        => $this->extract_video(null, null, $full),
            'pub_date'     => date('Y-m-d H:i:s', strtotime($pub_date)),
            'author'       => isset($entry->author->name) ? (string) $entry->author->name : '',
            'categories'   => [],
            'fetch_type'   => 'rss',
        ];
    }

    /* ========================================================================
       Extract Image (Fixed Regex)
       ======================================================================== */
    private function extract_image($item, $media, string $original_url): ?string {
        if ($media) {
            if (isset($media->content) && !empty($media->content['url']))   return (string) $media->content['url'];
            if (isset($media->thumbnail) && !empty($media->thumbnail['url'])) return (string) $media->thumbnail['url'];
        }

        if ($item && isset($item->enclosure) && !empty($item->enclosure['url'])) {
            if (strpos((string) $item->enclosure['type'], 'image') !== false) return (string) $item->enclosure['url'];
        }

        if ($item) {
            $desc = (string) $item->description;

            /* Has capture group */
            if (preg_match('/<img[^>]+src=[\'"]([^\'"]+)[\'"]/i', $desc, $m)) return $m[1];
        }

        return $this->fetch_og_image($original_url);
    }

    /* ========================================================================
       Fetch OG Image
       ======================================================================== */
    private function fetch_og_image(string $url): ?string {
        $cache_key = 'ns_og_' . md5($url);

        $cached = get_transient($cache_key);

        if ($cached !== false) return $cached ?: null;

        $response = wp_remote_get($url, [
            'timeout' => 10, 'user-agent' => $this->user_agent, 'redirection' => 3, 'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            set_transient($cache_key, '', HOUR_IN_SECONDS);
            return null;
        }

        $html  = wp_remote_retrieve_body($response);
        $image = null;

        /* All three patterns have capture groups */
        if (preg_match('/<meta[^>]+property=[\'"]og:image[\'"][^>]+content=[\'"]([^\'"]+)[\'"]/i', $html, $m)) {
            $image = $m[1];
        } elseif (preg_match('/<meta[^>]+content=[\'"]([^\'"]+)[\'"][^>]+property=[\'"]og:image[\'"]/i', $html, $m)) {
            $image = $m[1];
        } elseif (preg_match('/<meta[^>]+name=[\'"]twitter:image[\'"][^>]+content=[\'"]([^\'"]+)[\'"]/i', $html, $m)) {
            $image = $m[1];
        }

        set_transient($cache_key, $image ?: '', DAY_IN_SECONDS);

        return $image;
    }

    /* ========================================================================
       Extract Video (Note 5)
       ======================================================================== */
    private function extract_video($item, $media, string $content): string {
        /* 1) Video enclosure */
        if ($item && isset($item->enclosure) && !empty($item->enclosure['url'])) {
            if (strpos((string) $item->enclosure['type'], 'video') !== false) return (string) $item->enclosure['url'];
        }

        /* 2) media:content / media:player video */
        if ($media) {
            if (isset($media->content)) {
                foreach ($media->content as $mc) {
                    $type = (string) $mc['type'];
                    $medium = (string) $mc['medium'];

                    if (strpos($type, 'video') !== false || $medium === 'video') {
                        $u = (string) $mc['url'];
                        if ($u) return $u;
                    }
                }
            }

            if (isset($media->player) && !empty($media->player['url'])) return (string) $media->player['url'];
        }

        /* 3) YouTube/Aparat link inside content */
        if (preg_match('~https?://(?:www\.)?(?:youtube\.com/watch\?v=[\w-]+|youtu\.be/[\w-]+|aparat\.com/v/[\w-]+)~i', $content, $m)) {
            return $m[0];
        }

        return '';
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

    private function extract_categories($item): array {
        $cats = [];

        foreach ($item->category as $cat) $cats[] = (string) $cat;

        return $cats;
    }

    private function clean_text(string $text): string {
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        return trim(preg_replace('/\s+/', ' ', strip_tags($text)));
    }

    private function clean_html(string $html): string {
        return wp_kses($html, wp_kses_allowed_html('post'));
    }
}