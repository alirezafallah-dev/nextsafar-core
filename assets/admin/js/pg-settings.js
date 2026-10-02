/**
 * NextSafar PostgreSQL Settings JS
 */
(function($) {
    'use strict';
    
    const $testBtn = $('#ns-pg-test-connection');
    const $testResult = $('#ns-pg-test-result');
    const $migrationBtn = $('#ns-pg-run-migration');
    const $migrationResult = $('#ns-pg-migration-result');
    const $refreshBtn = $('#ns-pg-refresh-status');
    
    /**
     * Test Connection
     */
    $testBtn.on('click', function(e) {
        e.preventDefault();
        
        const $btn = $(this);
        $btn.prop('disabled', true).text('🔄 Testing...');
        $testResult.hide().empty();
        
        $.ajax({
            url: nsPgSettings.ajaxUrl,
            method: 'POST',
            data: {
                action: 'ns_test_pg_connection',
                nonce: nsPgSettings.nonce,
                host: $('#ns_pg_host').val(),
                port: $('#ns_pg_port').val(),
                database: $('#ns_pg_database').val(),
                username: $('#ns_pg_username').val(),
                password: $('#ns_pg_password').val(),
            },
            success: function(response) {
                if (response.success) {
                    showResult($testResult, 'success',
                        '✅ ' + response.data.message +
                        '<br><strong>PostgreSQL Version:</strong> ' + response.data.version +
                        '<br><strong>Connection Time:</strong> ' + response.data.time_ms + ' ms'
                    );
                } else {
                    showResult($testResult, 'error', '❌ ' + response.data.message);
                }
            },
            error: function(xhr) {
                showResult($testResult, 'error', '❌ Network error: ' + xhr.statusText);
            },
            complete: function() {
                $btn.prop('disabled', false).text('🔌 Test Connection');
            }
        });
    });
    
    /**
     * Run Migration
     */
    $migrationBtn.on('click', function(e) {
        e.preventDefault();
        
        if (!confirm('Are you sure you want to run the migration? This will create/update all database tables.')) {
            return;
        }
        
        const $btn = $(this);
        $btn.prop('disabled', true).text('⏳ Migrating...');
        $migrationResult.hide().empty();
        
        $.ajax({
            url: nsPgSettings.ajaxUrl,
            method: 'POST',
            data: {
                action: 'ns_run_migration',
                nonce: nsPgSettings.nonce,
            },
            success: function(response) {
                if (response.success) {
                    const data = response.data;
                    let html = '<h4>✅ Migration Successful!</h4>';
                    html += '<p><strong>From:</strong> ' + data.from_version + 
                            ' → <strong>To:</strong> ' + data.to_version + '</p>';
                    html += '<p><strong>Execution Time:</strong> ' + data.execution_ms + ' ms</p>';
                    
                    if (data.tables) {
                        html += '<h4>Tables:</h4><ul>';
                        for (const table in data.tables) {
                            html += '<li><code>' + table + '</code>: ' + data.tables[table] + '</li>';
                        }
                        html += '</ul>';
                    }
                    
                    showResult($migrationResult, 'success', html);
                    
                    // Refresh page after 2 seconds
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    showResult($migrationResult, 'error', '❌ ' + response.data.message);
                }
            },
            error: function(xhr) {
                showResult($migrationResult, 'error', '❌ Network error: ' + xhr.statusText);
            },
            complete: function() {
                $btn.prop('disabled', false).text('⚡ Run Migration');
            }
        });
    });
    
    /**
     * Refresh Status
     */
    $refreshBtn.on('click', function(e) {
        e.preventDefault();
        location.reload();
    });
    
    /**
     * Show result message
     */
    function showResult($container, type, html) {
        const className = type === 'success' ? 'notice-success' : 'notice-error';
        $container
            .html('<div class="notice ' + className + '"><p>' + html + '</p></div>')
            .slideDown();
    }
    
})(jQuery);