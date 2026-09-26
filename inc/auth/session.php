<?php

namespace NextSafar\Auth\Session;

/**
 * Session Management
 *
 * Handles creation, validation, sliding renewal, and destruction
 * of user sessions stored in the ns_sessions table.
 */

const SESSION_TTL_DAYS = 30;
const COOKIE_NAME = 'ns_session';

/**
 * Create a new session.
 *
 * @param int         $user_id    WordPress user ID.
 * @param string      $ip         Client IP address.
 * @param string|null $user_agent Client user agent string.
 * @return string The raw session token (64-char hex).
 */
function create(int $user_id, string $ip, ?string $user_agent = null): string {
    global $wpdb;

    $token = bin2hex(random_bytes(32)); // 64 char hex
    $table = $wpdb->prefix . 'ns_sessions';

    $wpdb->insert($table, [
        'token_hash' => hash('sha256', $token),
        'user_id'    => $user_id,
        'ip'         => $ip,
        'user_agent' => substr((string) $user_agent, 0, 255),
        'created_at' => current_time('mysql'),
        'last_seen'  => current_time('mysql'),
        'expires_at' => date('Y-m-d H:i:s', strtotime('+' . SESSION_TTL_DAYS . ' days')),
    ]);

    return $token;
}

/**
 * Validate a session token and perform sliding renewal.
 *
 * @param string $token The raw session token.
 * @return int|null The user ID if valid, null otherwise.
 */
function validate(string $token): ?int {
    global $wpdb;

    $table = $wpdb->prefix . 'ns_sessions';
    $hash  = hash('sha256', $token);
    $now   = current_time('mysql');

    $row = $wpdb->get_row($wpdb->prepare(
        "SELECT user_id, expires_at FROM {$table} WHERE token_hash = %s AND expires_at > %s LIMIT 1",
        $hash, $now
    ));

    if (!$row) return null;

    /* Sliding renewal: extend if less than half the TTL remains */
    $expires   = strtotime($row->expires_at);
    $half_ttl  = time() + (SESSION_TTL_DAYS * 24 * 3600 / 2);

    if ($expires < $half_ttl) {
        $wpdb->update($table,
            ['expires_at' => date('Y-m-d H:i:s', strtotime('+' . SESSION_TTL_DAYS . ' days'))],
            ['token_hash' => $hash]
        );
    }

    /* Update last_seen every 5 minutes */
    $wpdb->update($table, ['last_seen' => $now], ['token_hash' => $hash]);

    return (int) $row->user_id;
}

/**
 * Destroy the current session.
 *
 * @param string $token The raw session token to destroy.
 */
function destroy(string $token): void {
    global $wpdb;

    $wpdb->delete($wpdb->prefix . 'ns_sessions', [
        'token_hash' => hash('sha256', $token)
    ]);
}

/**
 * Destroy all sessions for a specific user.
 *
 * @param int $user_id The WordPress user ID.
 */
function destroy_all_for_user(int $user_id): void {
    global $wpdb;

    $wpdb->delete($wpdb->prefix . 'ns_sessions', ['user_id' => $user_id]);
}