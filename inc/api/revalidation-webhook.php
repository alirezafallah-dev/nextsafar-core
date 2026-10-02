<?php
/**
 * NextSafar Core - Revalidation Webhook
 * 
 * Notifies Next.js frontend when content changes.
 * 
 * ✅ FIX 1: Skip revalidation during admin sync (prevents timeout)
 * ✅ FIX 2: Short timeout (2 seconds instead of default)
 * ✅ FIX 3: Graceful failure handling (doesn't break sync)
 * ✅ FIX 4: Configurable Next.js URL (supports production)
 * ✅ FIX 5: Skip on localhost if not reachable
 * 
 * @package NextSafar\API
 * @since   2.5.0
 */

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

class RevalidationWebhook {
    
    /**
     * Revalidation timeout (2 seconds - must be fast!)
     */
    const TIMEOUT = 2;
    
    /**
     * Option keys
     */
    const OPTION_NEXTJS_URL = 'nextsafar_nextjs_url';
    const OPTION_SECRET     = 'nextsafar_revalidation_secret';
    const OPTION_ENABLED    = 'nextsafar_revalidation_enabled';
    
    /**
     * Flag to skip revalidation during bulk operations
     */
    private static bool $skip_revalidation = false;
    
    public static function init() {
        /* Hotels */
        add_action('save_post_hotel', [__CLASS__, 'on_hotel_change'], 20, 2);
        add_action('delete_post', [__CLASS__, 'on_hotel_change'], 20, 2);
        
        /* Other posts */
        add_action('save_post_destination', [__CLASS__, 'on_content_change'], 20, 2);
        add_action('save_post_tour', [__CLASS__, 'on_content_change'], 20, 2);
        add_action('save_post_travelguide', [__CLASS__, 'on_content_change'], 20, 2);
        add_action('save_post_restaurant', [__CLASS__, 'on_content_change'], 20, 2);
        add_action('save_post_hospital', [__CLASS__, 'on_content_change'], 20, 2);
        add_action('save_post_travelnews', [__CLASS__, 'on_news_change'], 20, 2);
        
        /* Tourism taxonomy */
        add_action('edited_tourism', [__CLASS__, 'on_tourism_change']);
        add_action('created_tourism', [__CLASS__, 'on_tourism_change']);
        
        // ✅ NEW: Allow other code to temporarily skip revalidation
        add_action('nextsafar_skip_revalidation', [__CLASS__, 'enable_skip']);
        add_action('nextsafar_enable_revalidation', [__CLASS__, 'disable_skip']);
    }
    
    /**
     * Enable skip mode (call before bulk operations)
     */
    public static function enable_skip(): void {
        self::$skip_revalidation = true;
    }
    
    /**
     * Disable skip mode (call after bulk operations)
     */
    public static function disable_skip(): void {
        self::$skip_revalidation = false;
    }
    
    /**
     * Hotel change → revalidate home page + hotel list
     */
    public static function on_hotel_change($post_id, $post = null) {
        if (self::should_skip()) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!$post || $post->post_status !== 'publish') return;
        
        self::trigger_revalidation([
            '/',
            '/hotels',
            '/hotels/' . $post->post_name,
        ]);
    }
    
    /**
     * Generic content change
     */
    public static function on_content_change($post_id, $post = null) {
        if (self::should_skip()) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!$post || $post->post_status !== 'publish') return;
        
        self::trigger_revalidation(['/']);
    }
    
    /**
     * News change
     */
    public static function on_news_change($post_id, $post = null) {
        if (self::should_skip()) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!$post || $post->post_status !== 'publish') return;
        
        self::trigger_revalidation([
            '/',
            '/news',
            '/news/' . $post->post_name,
        ]);
    }
    
    /**
     * Tourism change
     */
    public static function on_tourism_change($term_id) {
        if (self::should_skip()) return;
        
        self::trigger_revalidation(['/']);
    }
    
    /**
     * Check if revalidation should be skipped
     */
    private static function should_skip(): bool {
        // Skip if manually disabled
        if (self::$skip_revalidation) {
            return true;
        }
        
        // Skip if globally disabled
        if (get_option(self::OPTION_ENABLED, '1') !== '1') {
            return true;
        }
        
        // Skip during batch sync
        $sync_state = get_option('nextsafar_sync_state');
        if ($sync_state && ($sync_state['status'] ?? '') === 'running') {
            return true;
        }
        
        // Skip during news sync
        if (get_transient('ns_news_sync_lock')) {
            return true;
        }
        
        // Skip during AJAX sync
        if (defined('DOING_AJAX') && DOING_AJAX) {
            $action = $_POST['action'] ?? '';
            $skip_actions = [
                'nextsafar_sync_hotels',
                'nextsafar_sync_batch',
                'nextsafar_sync_news',
                'nextsafar_pg_sync',
                'ns_bg_news_sync',
            ];
            if (in_array($action, $skip_actions, true)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Get Next.js URL
     * 
     * Priority:
     * 1. Option (nextsafar_nextjs_url)
     * 2. Environment variable (NEXTJS_URL)
     * 3. Auto-detect from site URL (replace wp with frontend)
     */
    private static function nextjs_url(): string {
        // From option
        $url = get_option(self::OPTION_NEXTJS_URL, '');
        
        if (!empty($url)) {
            return rtrim($url, '/');
        }
        
        // From environment
        $env_url = getenv('NEXTJS_URL');
        if (!empty($env_url)) {
            return rtrim($env_url, '/');
        }
        
        // Auto-detect: If WordPress is at api.example.com, Next.js might be at example.com
        $site_url = home_url();
        
        // If localhost development
        if (strpos($site_url, 'localhost') !== false || strpos($site_url, '127.0.0.1') !== false) {
            // Default to Next.js dev server
            return 'http://localhost:3000';
        }
        
        // Production: Assume Next.js is at the same domain or subdomain
        // Example: api.nextsafar.com → nextsafar.com
        $parsed = parse_url($site_url);
        $host = $parsed['host'] ?? '';
        
        // Remove 'api.' prefix if exists
        if (strpos($host, 'api.') === 0) {
            $host = substr($host, 4);
        }
        
        // Remove 'wp.' prefix if exists
        if (strpos($host, 'wp.') === 0) {
            $host = substr($host, 3);
        }
        
        $scheme = $parsed['scheme'] ?? 'https';
        
        return "{$scheme}://{$host}";
    }
    
    /**
     * Get revalidation secret
     */
    private static function revalidation_secret(): string {
        // From option
        $secret = get_option(self::OPTION_SECRET, '');
        
        if (!empty($secret)) {
            return $secret;
        }
        
        // From environment
        $env_secret = getenv('NEXTJS_REVALIDATION_SECRET');
        if (!empty($env_secret)) {
            return $env_secret;
        }
        
        // Try to load from local secrets file
        $secrets_file = NEXTSAFAR_PATH . 'inc/secrets.local.php';
        if (file_exists($secrets_file)) {
            $secrets = include $secrets_file;
            if (is_array($secrets) && !empty($secrets['nextjs_revalidation_secret'])) {
                return $secrets['nextjs_revalidation_secret'];
            }
        }
        
        return '';
    }
    
    /**
     * Call Next.js API
     * 
     * ✅ FIX: Short timeout, graceful failure, no blocking
     */
    private static function trigger_revalidation(array $paths) {
        // Skip if disabled or during bulk operations
        if (self::should_skip()) {
            return;
        }
        
        $secret = self::revalidation_secret();
        
        if ($secret === '') {
            error_log('⏭️ Revalidation skipped: missing secret (set via option or NEXTJS_REVALIDATION_SECRET env)');
            return;
        }
        
        $nextjs_url = self::nextjs_url();
        $url = $nextjs_url . '/api/revalidate';
        
        // ✅ Short timeout to prevent blocking
        $response = wp_remote_post($url, [
            'timeout'   => self::TIMEOUT,
            'blocking'  => true,
            'headers'   => ['Content-Type' => 'application/json'],
            'body'      => json_encode([
                'secret' => $secret,
                'paths'  => $paths,
            ]),
            'sslverify' => false, // Allow self-signed certs in development
        ]);
        
        // ✅ Graceful failure handling
        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            
            // Don't log localhost connection errors in production (they're expected)
            if (strpos($error_msg, 'localhost') !== false || strpos($error_msg, 'connect') !== false) {
                error_log('⏭️ Revalidation skipped: Next.js not reachable at ' . $nextjs_url);
            } else {
                error_log('⚠️ Revalidation failed: ' . $error_msg);
            }
            return;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        
        if ($code !== 200) {
            error_log('⚠️ Revalidation error: HTTP ' . $code);
            return;
        }
        
        error_log('✅ Revalidated: ' . implode(', ', $paths));
    }
    
    /**
     * Render settings section (can be called from admin settings page)
     */
    public static function render_settings_section(): void {
        $enabled = get_option(self::OPTION_ENABLED, '1');
        $nextjs_url = get_option(self::OPTION_NEXTJS_URL, '');
        $secret = get_option(self::OPTION_SECRET, '');
        ?>
        <table class="form-table">
            <tr>
                <th>فعال‌سازی Revalidation</th>
                <td>
                    <label>
                        <input type="checkbox" name="nextsafar_revalidation_enabled" value="1" <?php checked($enabled, '1'); ?>>
                        اعلان تغییرات به Next.js ارسال شود
                    </label>
                </td>
            </tr>
            <tr>
                <th>آدرس Next.js</th>
                <td>
                    <input type="url" name="nextsafar_nextjs_url" value="<?php echo esc_attr($nextjs_url); ?>" 
                           class="regular-text" dir="ltr" placeholder="https://example.com">
                    <p class="description">
                        آدرس فرانت‌اند Next.js. اگر خالی باشد، به صورت خودکار تشخیص داده می‌شود.
                    </p>
                </td>
            </tr>
            <tr>
                <th>کلید Revalidation</th>
                <td>
                    <input type="password" name="nextsafar_revalidation_secret" value="<?php echo esc_attr($secret); ?>" 
                           class="regular-text" dir="ltr" autocomplete="off">
                    <p class="description">
                        کلید مخفی برای تأیید درخواست‌های revalidation.
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }
}