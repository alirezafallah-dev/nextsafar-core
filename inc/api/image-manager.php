<?php

/**
 * NextSafar Image Manager — Version 3.1
 * ✅ Note 4: ensure_featured_image() ensures post doesn't remain without featured image
 */

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

class ImageManager {
    const MAX_FILE_SIZE      = 5242880;
    const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'];
    const DOWNLOAD_TIMEOUT   = 30;

    /* ========================================================================
       Set Featured Image
       ======================================================================== */
    public static function set_featured_image(int $post_id, string $image_url = '', string $source = 'api'): bool {
        if (empty($image_url)) return false;

        $image_url = self::normalize_url($image_url);

        if (!self::is_valid_image_url($image_url)) return false;

        if (has_post_thumbnail($post_id)) return true;

        try {
            $attachment_id = self::download_and_attach($image_url, $post_id);

            if (is_wp_error($attachment_id)) {
                error_log("⚠️ ImageManager: Download failed for post #{$post_id}: " . $attachment_id->get_error_message());
                return false;
            }

            if ($attachment_id > 0) {
                set_post_thumbnail($post_id, $attachment_id);
                update_post_meta($post_id, '_ns_featured_image_source', sanitize_text_field($source));
                update_post_meta($post_id, '_ns_featured_image_url', esc_url_raw($image_url));

                error_log("🖼️ ImageManager: Featured image set for post #{$post_id} (attachment #{$attachment_id})");
                return true;
            }

            return false;
        } catch (\Throwable $e) {
            error_log("❌ ImageManager: Error for post #{$post_id}: " . $e->getMessage());
            return false;
        }
    }

    /* ========================================================================
       Fill Hotel Gallery from API Images List
       ⚠️ Flexible signature: accepts any type and casts inside method
       (because hotel-sync sometimes passes string)
       ======================================================================== */
    public static function fill_gallery($post_id, array $images, $max = 8): int {
        $post_id = (int) $post_id;
        $max     = max(1, min(20, (int) $max));   /* ✅ Safe cast */
        $added   = 0;

        $gallery = get_post_meta($post_id, '_hotel_gallery', true);
        $gallery = is_array($gallery) ? $gallery : [];

        foreach (array_slice($images, 0, $max) as $url) {
            if (!is_string($url) || $url === '') continue;
            if (in_array($url, $gallery, true)) continue;

            try {
                $att = self::download_and_attach($url, $post_id);

                if (is_wp_error($att) || !$att) continue;

                $att_url = wp_get_attachment_url((int) $att);

                if ($att_url) {
                    $gallery[] = $att_url;
                    $added++;
                }
            } catch (\Throwable $e) {
                error_log('⚠️ Gallery image failed for post ' . $post_id . ': ' . $e->getMessage());
            }
        }

        if (!empty($gallery)) {
            update_post_meta($post_id, '_hotel_gallery', $gallery);
            error_log("🖼️ Gallery: {$added} images added for post {$post_id}");
        }

        return $added;
    }

    /* ========================================================================
       Ensure Featured Image: Fallback Chain
       1) Item image  2) First image in content  3) og:image from source page  4) Local placeholder
       ======================================================================== */
    public static function ensure_featured_image(int $post_id, string $preferred = '', string $source_link = ''): bool {
        if (has_post_thumbnail($post_id)) return true;

        $candidates = [];

        if (!empty($preferred)) $candidates[] = $preferred;

        $in_content = self::extract_first_image(get_post_field('post_content', $post_id) ?: '');

        if ($in_content) $candidates[] = $in_content;

        if (!empty($source_link)) {
            $og = self::fetch_og_image($source_link);

            if ($og) $candidates[] = $og;
        }

        $ph = self::placeholder_url();

        if ($ph) $candidates[] = $ph;

        foreach (array_unique(array_filter($candidates)) as $url) {
            if (self::set_featured_image($post_id, $url, 'fallback')) return true;
        }

        error_log("⚠️ ImageManager: no image available for post #{$post_id}");
        return false;
    }

    /* ✅ Fixed regex with capture group */
    public static function extract_first_image(string $content): ?string {
        if (preg_match('/<img[^>]+src=[\'"]([^\'"]+)[\'"]/i', $content, $m)) return $m[1];
        if (preg_match('/<figure[^>]+data-url=[\'"]([^\'"]+)[\'"]/i', $content, $m)) return $m[1];
        return null;
    }

    /* ========================================================================
       Fetch OG Image from URL
       ======================================================================== */
    private static function fetch_og_image(string $url): ?string {
        $key = 'ns_og_' . md5($url);
        $cached = get_transient($key);

        if ($cached !== false) return $cached ?: null;

        $res = wp_remote_get($url, ['timeout' => 10, 'redirection' => 3, 'sslverify' => false]);

        if (is_wp_error($res)) {
            set_transient($key, '', HOUR_IN_SECONDS);
            return null;
        }

        $html = wp_remote_retrieve_body($res);
        $img  = null;

        if (preg_match('/<meta[^>]+property=[\'"]og:image[\'"][^>]+content=[\'"]([^\'"]+)[\'"]/i', $html, $m)) {
            $img = $m[1];
        } elseif (preg_match('/<meta[^>]+content=[\'"]([^\'"]+)[\'"][^>]+property=[\'"]og:image[\'"]/i', $html, $m)) {
            $img = $m[1];
        }

        set_transient($key, $img ?: '', DAY_IN_SECONDS);

        return $img;
    }

    /* ========================================================================
       Placeholder URL
       ======================================================================== */
    private static function placeholder_url(): string {
        $file = plugin_dir_path(__FILE__) . 'assets/placeholder-news.jpg';

        return file_exists($file) ? plugin_dir_url(__FILE__) . 'assets/placeholder-news.jpg' : '';
    }

    /* ========================================================================
       Unchanged Helpers
       ======================================================================== */
    private static function normalize_url(string $url): string {
        if (empty($url)) return '';

        $url = trim($url);

        if (strpos($url, '//') === 0) $url = 'https:' . $url;
        elseif (strpos($url, '/') === 0) $url = site_url($url);
        elseif (!preg_match('/^https?:\/\//i', $url)) $url = site_url('/' . $url);

        if (preg_match('/[^\x20-\x7f]/', $url)) {
            $parts = parse_url($url);

            if ($parts === false || empty($parts['host'])) return $url;

            $scheme = $parts['scheme'] ?? 'https';
            $host   = $parts['host'];
            $port   = isset($parts['port']) ? ':' . $parts['port'] : '';
            $path   = '';

            if (isset($parts['path']) && $parts['path'] !== '') {
                $segs = array_map(function ($s) {
                    return preg_match('/%[0-9A-Fa-f]{2}/', $s) ? $s : rawurlencode($s);
                }, explode('/', $parts['path']));

                $path = implode('/', $segs);
            }

            $query = isset($parts['query']) ? '?' . $parts['query'] : '';

            $url = "{$scheme}://{$host}{$port}{$path}{$query}";
        }

        return $url;
    }

    private static function is_valid_image_url(string $url): bool {
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) return false;

        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');

        if (!in_array($scheme, ['http', 'https'], true)) return false;

        $path = parse_url($url, PHP_URL_PATH);

        if (!empty($path)) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            if (!empty($ext) && !in_array($ext, self::ALLOWED_EXTENSIONS, true)) return false;
        }

        return true;
    }

    /* ========================================================================
       Download and Attach Image
       ======================================================================== */
    private static function download_and_attach(string $url, int $post_id) {
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        /* ✅ Download with browser headers + simple fallback */
        $tmp = self::download_image($url);

        if (is_wp_error($tmp)) return $tmp;

        $size = @filesize($tmp);

        if ($size === false || $size > self::MAX_FILE_SIZE) {
            @unlink($tmp);
            return new \WP_Error('image_too_large', 'image > 5MB');
        }

        $info = @getimagesize($tmp);

        if ($info === false) {
            if (self::get_file_mime($tmp) !== 'image/svg+xml') {
                @unlink($tmp);
                return new \WP_Error('not_an_image', 'not an image');
            }
        } elseif ($info[0] < 100 || $info[1] < 100) {
            @unlink($tmp);
            return new \WP_Error('image_too_small', '< 100px');
        }

        $filename   = self::extract_filename($url) ?: ('ns_image_' . $post_id . '_' . time() . '.jpg');
        $file_array = ['name' => sanitize_file_name($filename), 'tmp_name' => $tmp, 'size' => $size];

        $att = media_handle_sideload($file_array, $post_id);

        if (is_wp_error($att)) @unlink($tmp);

        return $att;
    }

    /* ========================================================================
       Download Image
       ✅ Attempt 1: With browser headers (prevent 403 Forbidden)
       ✅ Attempt 2: Simple download_url
       ======================================================================== */
        private static function download_image(string $url) {
            // ✅ FIX 1: Validate URL first
            if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                return new \WP_Error('invalid_url', 'Invalid image URL');
            }
            
            // ✅ FIX 2: Skip known broken domains
            $skip_domains = ['example.com', 'broken-domain.com'];
            foreach ($skip_domains as $domain) {
                if (stripos($url, $domain) !== false) {
                    return new \WP_Error('skipped_domain', 'Skipped known broken domain');
                }
            }

            // ✅ FIX 3: Increase timeout to 60 seconds
            $response = wp_remote_get($url, [
                'timeout'     => 60,  // Increased from 30 to 60
                'redirection' => 5,
                'sslverify'   => false,
                'headers'     => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                    'Referer'    => 'https://www.google.com/',
                    'Accept'     => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                ],
            ]);

            if (!is_wp_error($response) && (int) wp_remote_retrieve_response_code($response) === 200) {
                $body = wp_remote_retrieve_body($response);

                if (!empty($body)) {
                    $tmp = wp_tempnam($url);

                    if ($tmp) {
                        file_put_contents($tmp, $body);
                        return $tmp;
                    }
                }
            }

            // ✅ FIX 4: Fallback to download_url with increased timeout
            return download_url($url, 60);  // Increased from 30 to 60
        }

    /* ========================================================================
       Extract Filename from URL
       ======================================================================== */
    private static function extract_filename(string $url): string {
        $path = parse_url($url, PHP_URL_PATH);

        if (empty($path)) return '';

        $filename = sanitize_file_name(urldecode(basename($path)));

        if (!pathinfo($filename, PATHINFO_EXTENSION)) $filename .= '.jpg';

        return $filename;
    }

    /* ========================================================================
       Get File MIME Type
       ======================================================================== */
    private static function get_file_mime(string $file): string {
        $info = @getimagesize($file);

        if ($info !== false && isset($info['mime'])) return $info['mime'];

        $ft = wp_check_filetype($file);

        if (!empty($ft['type'])) return $ft['type'];

        $h = @file_get_contents($file, false, null, 0, 12);

        if ($h === false) return 'application/octet-stream';

        if (str_starts_with($h, "\xFF\xD8")) return 'image/jpeg';
        if (str_starts_with($h, "\x89PNG")) return 'image/png';
        if (str_starts_with($h, 'GIF8')) return 'image/gif';
        if (str_starts_with($h, 'RIFF') && strpos($h, 'WEBP') !== false) return 'image/webp';

        return 'application/octet-stream';
    }

    /* ========================================================================
       Strip Images from Content
       ======================================================================== */
    public static function strip_images_from_content(string $content): string {
        $content = preg_replace('/<img[^>]*>/i', '', $content);
        $content = preg_replace('/<figure[^>]*>.*?<\/figure>/is', '', $content);
        $content = preg_replace('/<p>\s*<\/p>/i', '', $content);

        return trim($content);
    }

    /* ========================================================================
       Get Featured Image Data
       ======================================================================== */
    public static function get_featured_image_data(int $post_id): array {
        $data = [
            'has_thumbnail' => false,
            'id' => 0,
            'url' => '',
            'width' => 0,
            'height' => 0,
            'alt' => '',
            'source' => get_post_meta($post_id, '_ns_featured_image_source', true) ?: '',
        ];

        $tid = get_post_thumbnail_id($post_id);

        if ($tid > 0) {
            $data['has_thumbnail'] = true;
            $data['id'] = $tid;

            $img = wp_get_attachment_image_src($tid, 'full');

            if ($img) {
                $data['url'] = $img[0];
                $data['width'] = $img[1];
                $data['height'] = $img[2];
            }

            $data['alt'] = get_post_meta($tid, '_wp_attachment_image_alt', true);
        }

        return $data;
    }
}