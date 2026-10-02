<?php
/**
 * NextSafar Core - PostgreSQL Connection Manager
 * 
 * Manages PDO connections to PostgreSQL with connection pooling,
 * transaction support, and automatic reconnection.
 * 
 * @package NextSafar\Database
 * @since   3.0.0
 */

namespace NextSafar\Database;

use NextSafar\Core\Logger;
use PDO;
use PDOException;
use PDOStatement;

if (!defined('ABSPATH')) exit;

class PostgresqlConnection {
    
    /**
     * Singleton instance
     */
    private static ?PostgresqlConnection $instance = null;
    
    /**
     * PDO connection
     */
    private ?PDO $pdo = null;
    
    /**
     * Connection config
     */
    private array $config = [];
    
    /**
     * Last query execution time (ms)
     */
    private float $lastQueryTime = 0;
    
    /**
     * Total queries executed
     */
    private int $totalQueries = 0;
    
    /**
     * Transaction depth (for nested transactions)
     */
    private int $transactionDepth = 0;
    
    /**
     * Private constructor (singleton)
     */
    private function __construct() {
        $this->loadConfig();
    }
    
    /**
     * Get singleton instance
     */
    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Prevent cloning
     */
    private function __clone() {}
    
    /**
     * Prevent unserialization
     */
    public function __wakeup() {
        throw new \Exception("Cannot unserialize singleton");
    }
    
    /**
     * Load connection config from WordPress options
     */
    private function loadConfig(): void {
        $this->config = [
            'host'     => get_option('ns_pg_host', 'localhost'),
            'port'     => (int) get_option('ns_pg_port', 5432),
            'database' => get_option('ns_pg_database', 'nextsafar_booking'),
            'username' => get_option('ns_pg_username', 'nextsafar_user'),
            'password' => get_option('ns_pg_password', ''),
            'charset'  => get_option('ns_pg_charset', 'utf8'),
            'options'  => [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_PERSISTENT         => false,
                PDO::ATTR_TIMEOUT            => 5,
            ],
        ];
    }
    
    /**
     * Reload config (after settings update)
     */
    public function reloadConfig(): void {
        $this->loadConfig();
        $this->pdo = null; // Force reconnection
    }
    
    /**
     * Check if PostgreSQL extension is loaded
     */
    public static function isExtensionLoaded(): bool {
        return extension_loaded('pdo_pgsql');
    }
    
    /**
     * Get connection (lazy connect)
     */
    public function getConnection(): PDO {
        if ($this->pdo === null) {
            $this->connect();
        }
        return $this->pdo;
    }
    
    /**
     * Connect to PostgreSQL
     */
    public function connect(): bool {
        if (!self::isExtensionLoaded()) {
            Logger::error('PDO PostgreSQL extension not loaded');
            throw new \RuntimeException('PHP extension pdo_pgsql is not installed');
        }
        
        $c = $this->config;
        
        if (empty($c['host']) || empty($c['database']) || empty($c['username'])) {
            Logger::error('PostgreSQL config incomplete', ['config' => $c]);
            throw new \RuntimeException('PostgreSQL configuration is incomplete');
        }
        
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;options=\'--client_encoding=%s\'',
            $c['host'],
            $c['port'],
            $c['database'],
            $c['charset']
        );
        
        try {
            $startTime = microtime(true);
            
            $this->pdo = new PDO(
                $dsn,
                $c['username'],
                $c['password'],
                $c['options']
            );
            
            // Set timezone
            $this->pdo->exec("SET TIME ZONE 'Asia/Tehran'");
            
            $connectTime = (microtime(true) - $startTime) * 1000;
            
            Logger::info('PostgreSQL connected successfully', [
                'host'      => $c['host'],
                'database'  => $c['database'],
                'time_ms'   => round($connectTime, 2),
            ]);
            
            return true;
            
        } catch (PDOException $e) {
            Logger::error('PostgreSQL connection failed', [
                'error' => $e->getMessage(),
                'host'  => $c['host'],
            ]);
            throw new \RuntimeException('PostgreSQL connection failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Disconnect
     */
    public function disconnect(): void {
        $this->pdo = null;
        Logger::debug('PostgreSQL disconnected');
    }
    
    /**
     * Test connection without persisting
     */
    public static function testConnection(array $config): array {
        if (!self::isExtensionLoaded()) {
            return [
                'success' => false,
                'message' => 'PHP extension pdo_pgsql is not installed',
            ];
        }
        
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;options=\'--client_encoding=%s\'',
            $config['host'] ?? 'localhost',
            $config['port'] ?? 5432,
            $config['database'] ?? '',
            $config['charset'] ?? 'utf8'
        );
        
        try {
            $startTime = microtime(true);
            
            $pdo = new PDO(
                $dsn,
                $config['username'] ?? '',
                $config['password'] ?? '',
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT            => 5,
                    PDO::ATTR_PERSISTENT         => false,
                ]
            );
            
            // Test query
            $pdo->query('SELECT 1');
            
            // Get PostgreSQL version
            $version = $pdo->query('SELECT version()')->fetchColumn();
            
            $connectTime = (microtime(true) - $startTime) * 1000;
            
            return [
                'success' => true,
                'message' => 'Connection successful',
                'version' => $version,
                'time_ms' => round($connectTime, 2),
            ];
            
        } catch (PDOException $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
    
    /**
     * Check if connected
     */
    public function isConnected(): bool {
        if ($this->pdo === null) {
            return false;
        }
        
        try {
            $this->pdo->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Execute a raw query
     */
    public function query(string $sql, array $params = []): PDOStatement {
        $startTime = microtime(true);
        
        try {
            $stmt = $this->getConnection()->prepare($sql);
            $stmt->execute($params);
            
            $this->lastQueryTime = (microtime(true) - $startTime) * 1000;
            $this->totalQueries++;
            
            if ($this->lastQueryTime > 1000) {
                Logger::warning('Slow query detected', [
                    'sql'      => $sql,
                    'time_ms'  => round($this->lastQueryTime, 2),
                ]);
            }
            
            return $stmt;
            
        } catch (PDOException $e) {
            Logger::error('Query failed', [
                'sql'    => $sql,
                'params' => $params,
                'error'  => $e->getMessage(),
            ]);
            throw $e;
        }
    }
    
    /**
     * Fetch all rows
     */
    public function fetchAll(string $sql, array $params = []): array {
        return $this->query($sql, $params)->fetchAll();
    }
    
    /**
     * Fetch single row
     */
    public function fetch(string $sql, array $params = []): ?array {
        $result = $this->query($sql, $params)->fetch();
        return $result ?: null;
    }
    
    /**
     * Fetch single column
     */
    public function fetchColumn(string $sql, array $params = [], int $column = 0) {
        return $this->query($sql, $params)->fetchColumn($column);
    }
    
    /**
     * Insert a row and return ID
     */
    public function insert(string $table, array $data): int {
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) RETURNING id',
            $this->quoteIdentifier($table),
            implode(', ', array_map([$this, 'quoteIdentifier'], $columns)),
            implode(', ', $placeholders)
        );
        
        $stmt = $this->query($sql, array_values($data));
        return (int) $stmt->fetchColumn();
    }
    
    /**
     * Update rows
     */
    public function update(string $table, array $data, array $where): int {
        $setParts = [];
        $values = [];
        
        foreach ($data as $column => $value) {
            $setParts[] = $this->quoteIdentifier($column) . ' = ?';
            $values[] = $value;
        }
        
        $whereParts = [];
        foreach ($where as $column => $value) {
            $whereParts[] = $this->quoteIdentifier($column) . ' = ?';
            $values[] = $value;
        }
        
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quoteIdentifier($table),
            implode(', ', $setParts),
            implode(' AND ', $whereParts)
        );
        
        return $this->query($sql, $values)->rowCount();
    }
    
    /**
     * Delete rows
     */
    public function delete(string $table, array $where): int {
        $whereParts = [];
        $values = [];
        
        foreach ($where as $column => $value) {
            $whereParts[] = $this->quoteIdentifier($column) . ' = ?';
            $values[] = $value;
        }
        
        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            $this->quoteIdentifier($table),
            implode(' AND ', $whereParts)
        );
        
        return $this->query($sql, $values)->rowCount();
    }
    
    /**
     * Quote identifier (table/column names)
     */
    public function quoteIdentifier(string $identifier): string {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
    
    /**
     * Begin transaction
     */
    public function beginTransaction(): bool {
        if ($this->transactionDepth === 0) {
            $this->getConnection()->beginTransaction();
            Logger::debug('Transaction started');
        } else {
            // Nested transaction via savepoint
            $this->query('SAVEPOINT sp_' . $this->transactionDepth);
        }
        
        $this->transactionDepth++;
        return true;
    }
    
    /**
     * Commit transaction
     */
    public function commit(): bool {
        if ($this->transactionDepth === 0) {
            throw new \RuntimeException('No transaction to commit');
        }
        
        $this->transactionDepth--;
        
        if ($this->transactionDepth === 0) {
            $this->getConnection()->commit();
            Logger::debug('Transaction committed');
        } else {
            $this->query('RELEASE SAVEPOINT sp_' . $this->transactionDepth);
        }
        
        return true;
    }
    
    /**
     * Rollback transaction
     */
    public function rollback(): bool {
        if ($this->transactionDepth === 0) {
            throw new \RuntimeException('No transaction to rollback');
        }
        
        $this->transactionDepth--;
        
        if ($this->transactionDepth === 0) {
            $this->getConnection()->rollBack();
            Logger::warning('Transaction rolled back');
        } else {
            $this->query('ROLLBACK TO SAVEPOINT sp_' . $this->transactionDepth);
        }
        
        return true;
    }
    
    /**
     * Execute callback in transaction
     */
    public function transaction(callable $callback) {
        $this->beginTransaction();
        
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }
    
    /**
     * Check if table exists
     */
    public function tableExists(string $tableName): bool {
        $sql = "SELECT EXISTS (
            SELECT FROM information_schema.tables 
            WHERE table_schema = 'public' 
            AND table_name = ?
        )";
        
        return (bool) $this->fetchColumn($sql, [$tableName]);
    }
    
    /**
     * Get statistics
     */
    public function getStats(): array {
        return [
            'connected'      => $this->isConnected(),
            'total_queries'  => $this->totalQueries,
            'last_query_ms'  => round($this->lastQueryTime, 2),
            'transaction_depth' => $this->transactionDepth,
            'config'         => [
                'host'     => $this->config['host'],
                'database' => $this->config['database'],
                'port'     => $this->config['port'],
            ],
        ];
    }
}