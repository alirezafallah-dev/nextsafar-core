<?php
/**
 * NextSafar Core - PostgreSQL Schema Builder
 * 
 * Creates and manages all booking-related tables in PostgreSQL.
 * 
 * @package NextSafar\Database
 * @since   3.0.0
 */

namespace NextSafar\Database;

use NextSafar\Core\Logger;

if (!defined('ABSPATH')) exit;

class PgSchemaBuilder {
    
    /**
     * All table definitions
     */
    private static function getTableDefinitions(): array {
        return [
            // جدول اصلی رزروها
            'ns_bookings' => [
                'columns' => [
                    'id'                 => 'BIGSERIAL PRIMARY KEY',
                    'booking_code'       => 'VARCHAR(30) NOT NULL UNIQUE',
                    'user_id'            => 'BIGINT NOT NULL DEFAULT 0',
                    'booking_type'       => "VARCHAR(20) NOT NULL CHECK (booking_type IN ('flight', 'hotel', 'tour', 'visa'))",
                    'external_id'        => 'VARCHAR(100)',
                    'status'             => "VARCHAR(30) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'awaiting_payment', 'paid', 'processing', 'confirmed', 'completed', 'cancelled', 'refunded', 'failed'))",
                    'total_amount'       => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00',
                    'currency'           => "VARCHAR(3) NOT NULL DEFAULT 'IRR'",
                    'passenger_count'    => 'INT NOT NULL DEFAULT 1',
                    'base_data'          => 'JSONB NOT NULL DEFAULT \'{}\'::jsonb',
                    'pricing_breakdown'  => 'JSONB',
                    'cancellation_policy' => 'JSONB',
                    'notes'              => 'TEXT',
                    'admin_notes'        => 'TEXT',
                    'created_at'         => "TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()",
                    'updated_at'         => "TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()",
                    'confirmed_at'       => 'TIMESTAMP WITH TIME ZONE',
                    'cancelled_at'       => 'TIMESTAMP WITH TIME ZONE',
                ],
                'indexes' => [
                    'idx_bookings_user_id'     => 'user_id',
                    'idx_bookings_type'        => 'booking_type',
                    'idx_bookings_status'      => 'status',
                    'idx_bookings_created'     => 'created_at',
                    'idx_bookings_external_id' => 'external_id',
                    'idx_bookings_user_status' => 'user_id, status',
                ],
            ],
            
            // جدول مسافران
            'ns_passengers' => [
                'columns' => [
                    'id'               => 'BIGSERIAL PRIMARY KEY',
                    'booking_id'       => 'BIGINT NOT NULL REFERENCES ns_bookings(id) ON DELETE CASCADE',
                    'passenger_type'   => "VARCHAR(20) NOT NULL CHECK (passenger_type IN ('adult', 'child', 'infant'))",
                    'title'            => "VARCHAR(10) CHECK (title IN ('MR', 'MRS', 'MS', 'MISS', 'MASTER'))",
                    'first_name'       => 'VARCHAR(100) NOT NULL',
                    'middle_name'      => 'VARCHAR(100)',
                    'last_name'        => 'VARCHAR(100) NOT NULL',
                    'first_name_fa'    => 'VARCHAR(100)',
                    'last_name_fa'     => 'VARCHAR(100)',
                    'national_id'      => 'VARCHAR(20)',
                    'passport_number'  => 'VARCHAR(50)',
                    'passport_expiry'  => 'DATE',
                    'passport_country' => 'VARCHAR(5)',
                    'birth_date'       => 'DATE NOT NULL',
                    'gender'           => "VARCHAR(10) NOT NULL CHECK (gender IN ('male', 'female'))",
                    'nationality'      => 'VARCHAR(50) NOT NULL',
                    'email'            => 'VARCHAR(255)',
                    'phone'            => 'VARCHAR(30)',
                    'emergency_contact' => 'JSONB',
                    'seat_preference'  => 'VARCHAR(20)',
                    'meal_preference'  => 'VARCHAR(50)',
                    'special_requests' => 'TEXT',
                    'price_paid'       => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00',
                    'created_at'       => "TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()",
                ],
                'indexes' => [
                    'idx_passengers_booking_id'   => 'booking_id',
                    'idx_passengers_passport'     => 'passport_number',
                    'idx_passengers_national_id'  => 'national_id',
                ],
            ],
            
            // جدول پرداخت‌ها
            'ns_payments' => [
                'columns' => [
                    'id'                => 'BIGSERIAL PRIMARY KEY',
                    'booking_id'        => 'BIGINT NOT NULL REFERENCES ns_bookings(id) ON DELETE CASCADE',
                    'user_id'           => 'BIGINT NOT NULL DEFAULT 0',
                    'gateway'           => "VARCHAR(30) NOT NULL CHECK (gateway IN ('zarinpal', 'idpay', 'nextpay', 'wallet', 'manual'))",
                    'amount'            => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00',
                    'currency'          => "VARCHAR(3) NOT NULL DEFAULT 'IRR'",
                    'status'            => "VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'redirecting', 'success', 'failed', 'cancelled', 'refunded', 'partial_refund'))",
                    'authority_code'    => 'VARCHAR(100)',
                    'transaction_id'    => 'VARCHAR(100) UNIQUE',
                    'reference_id'      => 'VARCHAR(100)',
                    'card_number'       => 'VARCHAR(20)',
                    'card_hash'         => 'VARCHAR(64)',
                    'ip_address'        => 'INET',
                    'user_agent'        => 'TEXT',
                    'gateway_request'   => 'JSONB',
                    'gateway_response'  => 'JSONB',
                    'callback_data'     => 'JSONB',
                    'description'       => 'TEXT',
                    'paid_at'           => 'TIMESTAMP WITH TIME ZONE',
                    'created_at'        => "TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()",
                    'updated_at'        => "TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()",
                ],
                'indexes' => [
                    'idx_payments_booking_id'   => 'booking_id',
                    'idx_payments_user_id'      => 'user_id',
                    'idx_payments_status'       => 'status',
                    'idx_payments_gateway'      => 'gateway',
                    'idx_payments_transaction'  => 'transaction_id',
                    'idx_payments_authority'    => 'authority_code',
                    'idx_payments_created'      => 'created_at',
                ],
            ],
            
            // جدول استردادها
            'ns_refunds' => [
                'columns' => [
                    'id'               => 'BIGSERIAL PRIMARY KEY',
                    'payment_id'       => 'BIGINT NOT NULL REFERENCES ns_payments(id) ON DELETE CASCADE',
                    'booking_id'       => 'BIGINT NOT NULL REFERENCES ns_bookings(id) ON DELETE CASCADE',
                    'user_id'          => 'BIGINT NOT NULL DEFAULT 0',
                    'amount'           => 'DECIMAL(14,2) NOT NULL',
                    'currency'         => "VARCHAR(3) NOT NULL DEFAULT 'IRR'",
                    'reason'           => 'TEXT NOT NULL',
                    'status'           => "VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'processing', 'approved', 'rejected', 'completed'))",
                    'admin_notes'      => 'TEXT',
                    'processed_by'     => 'BIGINT',
                    'processed_at'     => 'TIMESTAMP WITH TIME ZONE',
                    'gateway_ref_id'   => 'VARCHAR(100)',
                    'created_at'       => "TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()",
                ],
                'indexes' => [
                    'idx_refunds_payment_id'  => 'payment_id',
                    'idx_refunds_booking_id'  => 'booking_id',
                    'idx_refunds_user_id'     => 'user_id',
                    'idx_refunds_status'      => 'status',
                ],
            ],
            
            // جدول موجودی (Inventory)
            'ns_inventory' => [
                'columns' => [
                    'id'               => 'BIGSERIAL PRIMARY KEY',
                    'item_type'        => "VARCHAR(30) NOT NULL CHECK (item_type IN ('flight_seat', 'hotel_room', 'tour_slot', 'visa_slot'))",
                    'item_id'          => 'VARCHAR(100) NOT NULL',
                    'variant_key'      => 'VARCHAR(100) NOT NULL',
                    'available_date'   => 'DATE NOT NULL',
                    'total_capacity'   => 'INT NOT NULL DEFAULT 0',
                    'booked_count'     => 'INT NOT NULL DEFAULT 0',
                    'available_count'   => 'INT GENERATED ALWAYS AS (total_capacity - booked_count) STORED',
                    'price'            => 'DECIMAL(14,2) NOT NULL',
                    'currency'         => "VARCHAR(3) NOT NULL DEFAULT 'IRR'",
                    'min_stay'         => 'INT DEFAULT 1',
                    'max_stay'         => 'INT',
                    'rules'            => 'JSONB',
                    'updated_at'       => "TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()",
                ],
                'constraints' => [
                    'uq_inventory_item' => 'UNIQUE (item_type, item_id, variant_key, available_date)',
                ],
                'indexes' => [
                    'idx_inventory_item'       => 'item_type, item_id',
                    'idx_inventory_date'       => 'available_date',
                    'idx_inventory_available'  => 'available_count',
                ],
            ],
            
            // جدول کوپن‌ها
            'ns_coupons' => [
                'columns' => [
                    'id'                => 'BIGSERIAL PRIMARY KEY',
                    'code'              => 'VARCHAR(50) NOT NULL UNIQUE',
                    'description'       => 'TEXT',
                    'discount_type'     => "VARCHAR(20) NOT NULL CHECK (discount_type IN ('percentage', 'fixed', 'free_item'))",
                    'discount_value'    => 'DECIMAL(10,2) NOT NULL',
                    'min_purchase'      => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00',
                    'max_discount'      => 'DECIMAL(14,2)',
                    'applicable_types'  => 'JSONB',
                    'max_uses_total'    => 'INT DEFAULT 0',
                    'max_uses_per_user' => 'INT DEFAULT 1',
                    'used_count'        => 'INT NOT NULL DEFAULT 0',
                    'starts_at'         => 'TIMESTAMP WITH TIME ZONE',
                    'expires_at'        => 'TIMESTAMP WITH TIME ZONE',
                    'is_active'         => 'BOOLEAN NOT NULL DEFAULT TRUE',
                    'created_by'        => 'BIGINT',
                    'created_at'        => "TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()",
                ],
                'indexes' => [
                    'idx_coupons_code'       => 'code',
                    'idx_coupons_active'     => 'is_active',
                    'idx_coupons_expires'    => 'expires_at',
                ],
            ],
            
            // جدول استفاده از کوپن
            'ns_coupon_usage' => [
                'columns' => [
                    'id'           => 'BIGSERIAL PRIMARY KEY',
                    'coupon_id'    => 'BIGINT NOT NULL REFERENCES ns_coupons(id) ON DELETE CASCADE',
                    'user_id'      => 'BIGINT NOT NULL DEFAULT 0',
                    'booking_id'   => 'BIGINT REFERENCES ns_bookings(id) ON DELETE SET NULL',
                    'amount'       => 'DECIMAL(14,2) NOT NULL',
                    'used_at'      => "TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()",
                ],
                'indexes' => [
                    'idx_coupon_usage_coupon'  => 'coupon_id',
                    'idx_coupon_usage_user'    => 'user_id',
                    'idx_coupon_usage_booking' => 'booking_id',
                ],
            ],
            
            // جدول کیف پول
            'ns_wallets' => [
                'columns' => [
                    'id'          => 'BIGSERIAL PRIMARY KEY',
                    'user_id'     => 'BIGINT NOT NULL UNIQUE',
                    'balance'     => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00',
                    'currency'    => "VARCHAR(3) NOT NULL DEFAULT 'IRR'",
                    'status'      => "VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'frozen', 'closed'))",
                    'updated_at'  => "TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()",
                ],
                'indexes' => [
                    'idx_wallets_user'   => 'user_id',
                    'idx_wallets_status' => 'status',
                ],
            ],
            
            // جدول تراکنش‌های کیف پول
            'ns_wallet_transactions' => [
                'columns' => [
                    'id'            => 'BIGSERIAL PRIMARY KEY',
                    'wallet_id'     => 'BIGINT NOT NULL REFERENCES ns_wallets(id) ON DELETE CASCADE',
                    'user_id'       => 'BIGINT NOT NULL',
                    'type'          => "VARCHAR(20) NOT NULL CHECK (type IN ('deposit', 'withdrawal', 'payment', 'refund', 'bonus', 'admin_adjustment'))",
                    'amount'        => 'DECIMAL(14,2) NOT NULL',
                    'balance_after' => 'DECIMAL(14,2) NOT NULL',
                    'reference_id'  => 'VARCHAR(100)',
                    'reference_type' => 'VARCHAR(50)',
                    'description'   => 'TEXT',
                    'metadata'      => 'JSONB',
                    'created_at'    => "TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()",
                ],
                'indexes' => [
                    'idx_wt_wallet'      => 'wallet_id',
                    'idx_wt_user'        => 'user_id',
                    'idx_wt_type'        => 'type',
                    'idx_wt_created'     => 'created_at',
                    'idx_wt_reference'   => 'reference_type, reference_id',
                ],
            ],
            
            // جدول لاگ فعالیت‌ها
            'ns_activity_logs' => [
                'columns' => [
                    'id'           => 'BIGSERIAL PRIMARY KEY',
                    'user_id'      => 'BIGINT',
                    'action'       => 'VARCHAR(50) NOT NULL',
                    'entity_type'  => 'VARCHAR(50) NOT NULL',
                    'entity_id'    => 'BIGINT',
                    'details'      => 'JSONB',
                    'ip_address'   => 'INET',
                    'user_agent'   => 'TEXT',
                    'created_at'   => "TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()",
                ],
                'indexes' => [
                    'idx_activity_user'     => 'user_id',
                    'idx_activity_action'   => 'action',
                    'idx_activity_entity'   => 'entity_type, entity_id',
                    'idx_activity_created'  => 'created_at',
                ],
            ],
            
            // جدول اعلان‌ها
            'ns_notifications' => [
                'columns' => [
                    'id'           => 'BIGSERIAL PRIMARY KEY',
                    'user_id'      => 'BIGINT NOT NULL',
                    'channel'      => "VARCHAR(20) NOT NULL CHECK (channel IN ('sms', 'email', 'push', 'internal'))",
                    'type'         => 'VARCHAR(50) NOT NULL',
                    'title'        => 'VARCHAR(255) NOT NULL',
                    'message'      => 'TEXT NOT NULL',
                    'metadata'     => 'JSONB',
                    'status'       => "VARCHAR(20) NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'sent', 'failed', 'read'))",
                    'sent_at'      => 'TIMESTAMP WITH TIME ZONE',
                    'read_at'      => 'TIMESTAMP WITH TIME ZONE',
                    'error_message' => 'TEXT',
                    'created_at'   => "TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()",
                ],
                'indexes' => [
                    'idx_notifications_user'    => 'user_id',
                    'idx_notifications_status'  => 'status',
                    'idx_notifications_created' => 'created_at',
                ],
            ],
            
            // جدول تنظیمات سیستم
            'ns_settings' => [
                'columns' => [
                    'id'         => 'BIGSERIAL PRIMARY KEY',
                    'group_key'  => 'VARCHAR(50) NOT NULL',
                    'key'        => 'VARCHAR(100) NOT NULL',
                    'value'      => 'TEXT',
                    'type'       => 'VARCHAR(30) DEFAULT \'string\'',
                    'updated_at' => "TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()",
                ],
                'constraints' => [
                    'uq_settings_key' => 'UNIQUE (group_key, key)',
                ],
                'indexes' => [
                    'idx_settings_group' => 'group_key',
                ],
            ],
            
            // جدول Schema migrations (برای پیگیری نسخه‌ها)
            'ns_migrations' => [
                'columns' => [
                    'id'          => 'BIGSERIAL PRIMARY KEY',
                    'version'     => 'VARCHAR(20) NOT NULL UNIQUE',
                    'name'        => 'VARCHAR(100) NOT NULL',
                    'executed_at' => "TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()",
                    'execution_ms' => 'INT NOT NULL DEFAULT 0',
                    'checksum'    => 'VARCHAR(64)',
                ],
                'indexes' => [
                    'idx_migrations_version' => 'version',
                ],
            ],
        ];
    }
    
    /**
     * Build all tables
     */
    public static function buildAll(): array {
        $db = PostgresqlConnection::getInstance();
        $definitions = self::getTableDefinitions();
        $results = [];
        
        foreach ($definitions as $tableName => $definition) {
            try {
                $result = self::createTable($tableName, $definition);
                $results[$tableName] = $result;
                
                Logger::info('Table processed', [
                    'table'   => $tableName,
                    'result'  => $result,
                ]);
                
            } catch (\Throwable $e) {
                $results[$tableName] = 'error: ' . $e->getMessage();
                
                Logger::error('Table creation failed', [
                    'table' => $tableName,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        // Create helper functions
        self::createHelperFunctions();
        
        return $results;
    }
    
    /**
     * Create a single table
     */
    private static function createTable(string $tableName, array $definition): string {
        $db = PostgresqlConnection::getInstance();
        
        if ($db->tableExists($tableName)) {
            return 'already_exists';
        }
        
        // Build column definitions
        $columnDefs = [];
        foreach ($definition['columns'] as $colName => $colType) {
            $columnDefs[] = sprintf('"%s" %s', $colName, $colType);
        }
        
        $sql = sprintf(
            "CREATE TABLE %s (\n    %s\n)",
            $db->quoteIdentifier($tableName),
            implode(",\n    ", $columnDefs)
        );
        
        $db->query($sql);
        
        // Add constraints
        if (isset($definition['constraints'])) {
            foreach ($definition['constraints'] as $constraintName => $constraint) {
                $sql = sprintf(
                    'ALTER TABLE %s ADD CONSTRAINT %s %s',
                    $db->quoteIdentifier($tableName),
                    $db->quoteIdentifier($constraintName),
                    $constraint
                );
                $db->query($sql);
            }
        }
        
        // Create indexes
        if (isset($definition['indexes'])) {
            foreach ($definition['indexes'] as $indexName => $columns) {
                $sql = sprintf(
                    'CREATE INDEX %s ON %s (%s)',
                    $db->quoteIdentifier($indexName),
                    $db->quoteIdentifier($tableName),
                    $columns
                );
                $db->query($sql);
            }
        }
        
        return 'created';
    }
    
    /**
     * Create PostgreSQL helper functions
     */
    private static function createHelperFunctions(): void {
        $db = PostgresqlConnection::getInstance();
        
        // Updated_at trigger function
        $sql = <<<'SQL'
CREATE OR REPLACE FUNCTION ns_update_updated_at_column()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
SQL;
        
        try {
            $db->query($sql);
        } catch (\Throwable $e) {
            Logger::warning('Could not create trigger function', [
                'error' => $e->getMessage(),
            ]);
        }
        
        // Attach trigger to tables with updated_at
        $tablesWithUpdatedAt = [
            'ns_bookings', 'ns_payments', 'ns_inventory', 'ns_wallets'
        ];
        
        foreach ($tablesWithUpdatedAt as $table) {
            if ($db->tableExists($table)) {
                $triggerName = 'trg_' . $table . '_updated_at';
                $sql = sprintf(
                    'DROP TRIGGER IF EXISTS %s ON %s',
                    $db->quoteIdentifier($triggerName),
                    $db->quoteIdentifier($table)
                );
                $db->query($sql);
                
                $sql = sprintf(
                    'CREATE TRIGGER %s BEFORE UPDATE ON %s FOR EACH ROW EXECUTE FUNCTION ns_update_updated_at_column()',
                    $db->quoteIdentifier($triggerName),
                    $db->quoteIdentifier($table)
                );
                $db->query($sql);
            }
        }
    }
    
    /**
     * Drop all tables (DANGEROUS - admin only)
     */
    public static function dropAll(): array {
        $db = PostgresqlConnection::getInstance();
        $definitions = self::getTableDefinitions();
        $results = [];
        
        // Drop in reverse order (foreign keys)
        $tables = array_reverse(array_keys($definitions));
        
        foreach ($tables as $tableName) {
            if ($db->tableExists($tableName)) {
                $sql = sprintf('DROP TABLE %s CASCADE', $db->quoteIdentifier($tableName));
                $db->query($sql);
                $results[$tableName] = 'dropped';
            } else {
                $results[$tableName] = 'not_exists';
            }
        }
        
        return $results;
    }
    
    /**
     * Get status of all tables
     */
    public static function getStatus(): array {
        $db = PostgresqlConnection::getInstance();
        $definitions = self::getTableDefinitions();
        $status = [];
        
        foreach ($definitions as $tableName => $definition) {
            $exists = $db->tableExists($tableName);
            $rowCount = 0;
            
            if ($exists) {
                try {
                    $rowCount = (int) $db->fetchColumn(
                        sprintf('SELECT COUNT(*) FROM %s', $db->quoteIdentifier($tableName))
                    );
                } catch (\Throwable $e) {
                    $rowCount = -1;
                }
            }
            
            $status[$tableName] = [
                'exists' => $exists,
                'rows'   => $rowCount,
            ];
        }
        
        return $status;
    }
    
    /**
     * Get expected table count
     */
    public static function getExpectedTableCount(): int {
        return count(self::getTableDefinitions());
    }
}