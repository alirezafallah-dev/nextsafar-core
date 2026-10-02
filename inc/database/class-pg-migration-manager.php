<?php
/**
 * NextSafar Core - PostgreSQL Migration Manager
 * 
 * Manages schema migrations for PostgreSQL database.
 * 
 * @package NextSafar\Database
 * @since   3.0.0
 */

namespace NextSafar\Database;

use NextSafar\Core\Logger;

if (!defined('ABSPATH')) exit;

class PgMigrationManager {
    
    /**
     * Current schema version
     */
    const SCHEMA_VERSION = '3.0.0';
    
    /**
     * Option key for stored version
     */
    const VERSION_OPTION = 'ns_pg_schema_version';
    
    /**
     * Run all pending migrations
     */
    public static function migrate(): array {
        $results = [];
        
        try {
            // Ensure migrations table exists first
            self::ensureMigrationsTable();
            
            // Get current version
            $currentVersion = get_option(self::VERSION_OPTION, '0.0.0');
            
            if (version_compare($currentVersion, self::SCHEMA_VERSION, '>=')) {
                return [
                    'status'  => 'up_to_date',
                    'version' => $currentVersion,
                    'message' => 'Schema is already at the latest version',
                ];
            }
            
            Logger::info('Starting migration', [
                'from' => $currentVersion,
                'to'   => self::SCHEMA_VERSION,
            ]);
            
            // Build all tables
            $startTime = microtime(true);
            $tableResults = PgSchemaBuilder::buildAll();
            $executionMs = (int) ((microtime(true) - $startTime) * 1000);
            
            // Record migration
            self::recordMigration(self::SCHEMA_VERSION, 'full_schema_build', $executionMs);
            
            // Update stored version
            update_option(self::VERSION_OPTION, self::SCHEMA_VERSION);
            
            $results = [
                'status'       => 'success',
                'from_version' => $currentVersion,
                'to_version'   => self::SCHEMA_VERSION,
                'tables'       => $tableResults,
                'execution_ms' => $executionMs,
            ];
            
            Logger::info('Migration completed', $results);
            
        } catch (\Throwable $e) {
            $results = [
                'status'  => 'error',
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ];
            
            Logger::error('Migration failed', [
                'error' => $e->getMessage(),
            ]);
        }
        
        return $results;
    }
    
    /**
     * Ensure migrations table exists
     */
    private static function ensureMigrationsTable(): void {
        $db = PostgresqlConnection::getInstance();
        
        if (!$db->tableExists('ns_migrations')) {
            $sql = <<<'SQL'
CREATE TABLE ns_migrations (
    id BIGSERIAL PRIMARY KEY,
    version VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    executed_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
    execution_ms INT NOT NULL DEFAULT 0,
    checksum VARCHAR(64)
)
SQL;
            $db->query($sql);
            
            Logger::info('Migrations table created');
        }
    }
    
    /**
     * Record a migration in history
     */
    private static function recordMigration(
        string $version,
        string $name,
        int $executionMs
    ): void {
        $db = PostgresqlConnection::getInstance();
        
        $sql = <<<'SQL'
INSERT INTO ns_migrations (version, name, execution_ms, checksum)
VALUES (?, ?, ?, ?)
ON CONFLICT (version) DO UPDATE SET
    executed_at = NOW(),
    execution_ms = EXCLUDED.execution_ms
SQL;
        
        $checksum = md5($version . $name . time());
        
        $db->query($sql, [$version, $name, $executionMs, $checksum]);
    }
    
    /**
     * Get migration history
     */
    public static function getHistory(): array {
        $db = PostgresqlConnection::getInstance();
        
        if (!$db->tableExists('ns_migrations')) {
            return [];
        }
        
        return $db->fetchAll(
            'SELECT * FROM ns_migrations ORDER BY executed_at DESC'
        );
    }
    
    /**
     * Get current version
     */
    public static function getCurrentVersion(): string {
        return (string) get_option(self::VERSION_OPTION, '0.0.0');
    }
    
    /**
     * Check if migration is needed
     */
    public static function needsMigration(): bool {
        $current = self::getCurrentVersion();
        return version_compare($current, self::SCHEMA_VERSION, '<');
    }
    
    /**
     * Rollback (DANGEROUS - drops all tables)
     */
    public static function rollback(): array {
        if (!defined('NS_ALLOW_SCHEMA_DROP') || !NS_ALLOW_SCHEMA_DROP) {
            return [
                'status'  => 'error',
                'message' => 'Schema drop is not allowed. Set NS_ALLOW_SCHEMA_DROP constant.',
            ];
        }
        
        try {
            $dropResults = PgSchemaBuilder::dropAll();
            delete_option(self::VERSION_OPTION);
            
            return [
                'status'  => 'success',
                'message' => 'Schema rolled back successfully',
                'tables'  => $dropResults,
            ];
            
        } catch (\Throwable $e) {
            return [
                'status'  => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Get system status
     */
    public static function getStatus(): array {
        $currentVersion = self::getCurrentVersion();
        $needsMigration = self::needsMigration();
        
        $tableCount = 0;
        $tablesExist = 0;
        $totalRows = 0;
        
        try {
            $status = PgSchemaBuilder::getStatus();
            $tableCount = count($status);
            
            foreach ($status as $info) {
                if ($info['exists']) {
                    $tablesExist++;
                    if ($info['rows'] > 0) {
                        $totalRows += $info['rows'];
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ignore
        }
        
        return [
            'current_version'    => $currentVersion,
            'target_version'     => self::SCHEMA_VERSION,
            'needs_migration'    => $needsMigration,
            'extension_loaded'   => PostgresqlConnection::isExtensionLoaded(),
            'tables_expected'    => PgSchemaBuilder::getExpectedTableCount(),
            'tables_existing'    => $tablesExist,
            'tables_total'       => $tableCount,
            'total_rows'         => $totalRows,
            'connection_ok'      => false, // Will be set separately
        ];
    }
}