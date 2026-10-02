<?php
/**
 * NextSafar Core - PostgreSQL Settings Page
 * 
 * Admin UI for configuring PostgreSQL connection and running migrations.
 * 
 * @package NextSafar\Admin
 * @since   3.0.0
 */

namespace NextSafar\Admin;

use NextSafar\Database\PostgresqlConnection;
use NextSafar\Database\PgSchemaBuilder;
use NextSafar\Database\PgMigrationManager;

if (!defined('ABSPATH')) exit;

class PgSettings {
    
    const MENU_SLUG = 'nextsafar-postgresql';
    const OPTION_GROUP = 'nextsafar_pg_settings';
    
    /**
     * Initialize
     */
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'addMenu']);
        add_action('admin_init', [__CLASS__, 'registerSettings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueueAssets']);
        
        // AJAX handlers
        add_action('wp_ajax_ns_test_pg_connection', [__CLASS__, 'ajaxTestConnection']);
        add_action('wp_ajax_ns_run_migration', [__CLASS__, 'ajaxRunMigration']);
        add_action('wp_ajax_ns_get_pg_status', [__CLASS__, 'ajaxGetStatus']);
    }
    
    /**
     * Add admin menu (STANDALONE - no parent dependency)
     */
    public static function addMenu(): void {
        // ✅ منوی والد مستقل (حتماً نمایش داده می‌شه)
        add_menu_page(
            'NextSafar PostgreSQL',
            'PostgreSQL',
            'manage_options',
            self::MENU_SLUG,
            [__CLASS__, 'renderPage'],
            'dashicons-database',
            30
        );
        
        // زیرمنوی اصلی (برای جلوگیری از تکرار)
        add_submenu_page(
            self::MENU_SLUG,
            'PostgreSQL Settings',
            'Settings',
            'manage_options',
            self::MENU_SLUG,
            [__CLASS__, 'renderPage']
        );
        
        // زیرمنوی وضعیت جداول
        add_submenu_page(
            self::MENU_SLUG,
            'Tables Status',
            'Tables',
            'manage_options',
            self::MENU_SLUG . '-tables',
            [__CLASS__, 'renderTablesPage']
        );
    }

    /**
     * Render tables page (standalone view)
     */
    public static function renderTablesPage(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions.'));
        }
        ?>
        <div class="wrap nextsafar-pg-settings">
            <h1>📊 PostgreSQL Tables Status</h1>
            <div class="ns-pg-section">
                <?php self::renderTablesStatus(); ?>
            </div>
            <p>
                <a href="<?php echo admin_url('admin.php?page=' . self::MENU_SLUG); ?>" class="button">
                    ← Back to Settings
                </a>
            </p>
        </div>
        <?php
    }
    
    /**
     * Register settings
     */
    public static function registerSettings(): void {
        register_setting(self::OPTION_GROUP, 'ns_pg_host', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'localhost',
        ]);
        
        register_setting(self::OPTION_GROUP, 'ns_pg_port', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 5432,
        ]);
        
        register_setting(self::OPTION_GROUP, 'ns_pg_database', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'nextsafar_booking',
        ]);
        
        register_setting(self::OPTION_GROUP, 'ns_pg_username', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        
        register_setting(self::OPTION_GROUP, 'ns_pg_password', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        
        register_setting(self::OPTION_GROUP, 'ns_pg_charset', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => 'utf8',
        ]);
    }
    
    /**
     * Enqueue assets
     */
    public static function enqueueAssets(string $hook): void {
        if (strpos($hook, self::MENU_SLUG) === false) {
            return;
        }
        
        wp_enqueue_script(
            'ns-pg-settings',
            NEXTSAFAR_URL . 'assets/admin/js/pg-settings.js',
            ['jquery'],
            NEXTSAFAR_VERSION,
            true
        );
        
        wp_localize_script('ns-pg-settings', 'nsPgSettings', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('ns_pg_settings_nonce'),
        ]);
        
        wp_enqueue_style(
            'ns-pg-settings',
            NEXTSAFAR_URL . 'assets/admin/css/pg-settings.css',
            [],
            NEXTSAFAR_VERSION
        );
    }
    
    /**
     * Render settings page
     */
    public static function renderPage(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions.'));
        }
        
        $status = self::getFullStatus();
        
        ?>
        <div class="wrap nextsafar-pg-settings">
            <h1>🐘 PostgreSQL Configuration</h1>
            <p class="description">
                تنظیمات اتصال به دیتابیس PostgreSQL برای ذخیره‌سازی رزروها، پرداخت‌ها و تراکنش‌ها.
            </p>
            
            <!-- Status Card -->
            <div class="ns-pg-status-card" id="ns-pg-status">
                <?php self::renderStatusCard($status); ?>
            </div>
            
            <!-- Connection Form -->
            <div class="ns-pg-section">
                <h2>🔌 Connection Settings</h2>
                
                <form method="post" action="options.php">
                    <?php settings_fields(self::OPTION_GROUP); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="ns_pg_host">Host</label></th>
                            <td>
                                <input type="text" id="ns_pg_host" name="ns_pg_host" 
                                       value="<?php echo esc_attr(get_option('ns_pg_host', 'localhost')); ?>" 
                                       class="regular-text" />
                                <p class="description">معمولاً <code>localhost</code> یا IP سرور</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="ns_pg_port">Port</label></th>
                            <td>
                                <input type="number" id="ns_pg_port" name="ns_pg_port" 
                                       value="<?php echo esc_attr(get_option('ns_pg_port', 5432)); ?>" 
                                       class="small-text" />
                            </td>
                        </tr>
                        <tr>
                            <th><label for="ns_pg_database">Database Name</label></th>
                            <td>
                                <input type="text" id="ns_pg_database" name="ns_pg_database" 
                                       value="<?php echo esc_attr(get_option('ns_pg_database', 'nextsafar_booking')); ?>" 
                                       class="regular-text" />
                            </td>
                        </tr>
                        <tr>
                            <th><label for="ns_pg_username">Username</label></th>
                            <td>
                                <input type="text" id="ns_pg_username" name="ns_pg_username" 
                                       value="<?php echo esc_attr(get_option('ns_pg_username')); ?>" 
                                       class="regular-text" autocomplete="off" />
                            </td>
                        </tr>
                        <tr>
                            <th><label for="ns_pg_password">Password</label></th>
                            <td>
                                <input type="password" id="ns_pg_password" name="ns_pg_password" 
                                       value="<?php echo esc_attr(get_option('ns_pg_password')); ?>" 
                                       class="regular-text" autocomplete="off" />
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <?php submit_button('💾 Save Settings'); ?>
                        <button type="button" class="button button-secondary" id="ns-pg-test-connection">
                            🔌 Test Connection
                        </button>
                    </p>
                </form>
                
                <div id="ns-pg-test-result" class="ns-pg-result" style="display:none;"></div>
            </div>
            
            <!-- Migration Section -->
            <div class="ns-pg-section">
                <h2>🚀 Schema Migration</h2>
                <p class="description">
                    ایجاد و به‌روزرسانی جداول دیتابیس. این عملیات شامل ساخت 12 جدول اصلی برای سیستم رزرو، پرداخت، کیف پول و غیره است.
                </p>
                
                <div class="ns-pg-migration-info">
                    <strong>Target Version:</strong> <code><?php echo PgMigrationManager::SCHEMA_VERSION; ?></code><br>
                    <strong>Current Version:</strong> <code><?php echo PgMigrationManager::getCurrentVersion(); ?></code>
                </div>
                
                <p>
                    <button type="button" class="button button-primary button-large" id="ns-pg-run-migration">
                        ⚡ Run Migration
                    </button>
                    <button type="button" class="button" id="ns-pg-refresh-status">
                        🔄 Refresh Status
                    </button>
                </p>
                
                <div id="ns-pg-migration-result" class="ns-pg-result" style="display:none;"></div>
            </div>
            
            <!-- Tables Status -->
            <div class="ns-pg-section">
                <h2>📊 Tables Status</h2>
                <div id="ns-pg-tables-status">
                    <?php self::renderTablesStatus(); ?>
                </div>
            </div>
            
            <!-- Migration History -->
            <div class="ns-pg-section">
                <h2>📜 Migration History</h2>
                <?php self::renderMigrationHistory(); ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render status card
     */
    private static function renderStatusCard(array $status): void {
        $extensionOk = $status['extension_loaded'];
        $connectionOk = $status['connection_ok'];
        $migrationOk = !$status['needs_migration'];
        $tablesOk = $status['tables_existing'] === $status['tables_expected'];
        
        $overallOk = $extensionOk && $connectionOk && $migrationOk && $tablesOk;
        $statusClass = $overallOk ? 'status-ok' : 'status-error';
        $statusText = $overallOk ? '✅ All Systems Operational' : '⚠️ Configuration Needed';
        ?>
        <div class="ns-pg-overall-status <?php echo $statusClass; ?>">
            <h3><?php echo $statusText; ?></h3>
            <ul>
                <li>
                    <span class="dashicons <?php echo $extensionOk ? 'dashicons-yes' : 'dashicons-no'; ?>"></span>
                    PDO PostgreSQL Extension: <?php echo $extensionOk ? 'Loaded' : '<strong>Missing</strong>'; ?>
                </li>
                <li>
                    <span class="dashicons <?php echo $connectionOk ? 'dashicons-yes' : 'dashicons-no'; ?>"></span>
                    Database Connection: <?php echo $connectionOk ? 'OK' : 'Failed'; ?>
                </li>
                <li>
                    <span class="dashicons <?php echo $migrationOk ? 'dashicons-yes' : 'dashicons-no'; ?>"></span>
                    Schema Version: <?php echo $migrationOk ? 'Up to date' : 'Migration needed'; ?>
                </li>
                <li>
                    <span class="dashicons <?php echo $tablesOk ? 'dashicons-yes' : 'dashicons-no'; ?>"></span>
                    Tables: <?php echo $status['tables_existing']; ?>/<?php echo $status['tables_expected']; ?> created
                </li>
            </ul>
        </div>
        <?php
    }
    
    /**
     * Render tables status
     */
    private static function renderTablesStatus(): void {
        try {
            $status = PgSchemaBuilder::getStatus();
        } catch (\Throwable $e) {
            echo '<p class="ns-pg-error">Unable to fetch table status: ' . esc_html($e->getMessage()) . '</p>';
            return;
        }
        
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Table Name</th><th>Status</th><th>Row Count</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($status as $tableName => $info) {
            $statusIcon = $info['exists'] ? '✅' : '❌';
            $rowCount = $info['rows'] >= 0 ? number_format($info['rows']) : 'N/A';
            
            echo '<tr>';
            echo '<td><code>' . esc_html($tableName) . '</code></td>';
            echo '<td>' . $statusIcon . '</td>';
            echo '<td>' . $rowCount . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    /**
     * Render migration history
     */
    private static function renderMigrationHistory(): void {
        try {
            $history = PgMigrationManager::getHistory();
        } catch (\Throwable $e) {
            echo '<p>No migration history available.</p>';
            return;
        }
        
        if (empty($history)) {
            echo '<p>No migrations executed yet.</p>';
            return;
        }
        
        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Version</th><th>Name</th><th>Executed At</th><th>Time (ms)</th></tr></thead>';
        echo '<tbody>';
        
        foreach ($history as $row) {
            echo '<tr>';
            echo '<td><code>' . esc_html($row['version']) . '</code></td>';
            echo '<td>' . esc_html($row['name']) . '</td>';
            echo '<td>' . esc_html($row['executed_at']) . '</td>';
            echo '<td>' . esc_html($row['execution_ms']) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
    }
    
    /**
     * Get full status
     */
    private static function getFullStatus(): array {
        $status = PgMigrationManager::getStatus();
        
        $status['connection_ok'] = false;
        
        try {
            $db = PostgresqlConnection::getInstance();
            $status['connection_ok'] = $db->isConnected();
        } catch (\Throwable $e) {
            // Ignore
        }
        
        return $status;
    }
    
    /**
     * AJAX: Test connection
     */
    public static function ajaxTestConnection(): void {
        check_ajax_referer('ns_pg_settings_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $config = [
            'host'     => sanitize_text_field($_POST['host'] ?? get_option('ns_pg_host')),
            'port'     => absint($_POST['port'] ?? get_option('ns_pg_port', 5432)),
            'database' => sanitize_text_field($_POST['database'] ?? get_option('ns_pg_database')),
            'username' => sanitize_text_field($_POST['username'] ?? get_option('ns_pg_username')),
            'password' => $_POST['password'] ?? get_option('ns_pg_password'),
            'charset'  => 'utf8',
        ];
        
        $result = PostgresqlConnection::testConnection($config);
        
        if ($result['success']) {
            // Reload connection with saved settings
            PostgresqlConnection::getInstance()->reloadConfig();
            
            wp_send_json_success([
                'message' => 'Connection successful!',
                'version' => $result['version'],
                'time_ms' => $result['time_ms'],
            ]);
        } else {
            wp_send_json_error([
                'message' => 'Connection failed: ' . $result['message'],
            ]);
        }
    }
    
    /**
     * AJAX: Run migration
     */
    public static function ajaxRunMigration(): void {
        check_ajax_referer('ns_pg_settings_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        $result = PgMigrationManager::migrate();
        
        if ($result['status'] === 'success' || $result['status'] === 'up_to_date') {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * AJAX: Get status
     */
    public static function ajaxGetStatus(): void {
        check_ajax_referer('ns_pg_settings_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized']);
        }
        
        wp_send_json_success(self::getFullStatus());
    }
}