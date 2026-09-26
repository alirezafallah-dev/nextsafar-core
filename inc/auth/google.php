<?php

/**
 * Google logic: find/create user based on email
 */

namespace NextSafar\Auth\Google;

/* ========================================================================
   Validate internal requests from Next.js
   ======================================================================== */
function validate_internal_request(): bool {
    $secret = $_SERVER['HTTP_X_NS_SECRET'] ?? '';
    $expected = defined('NS_INTERNAL_SECRET') ? NS_INTERNAL_SECRET : '';

    if (!$expected || !hash_equals($expected, (string) $secret)) {
        return false;
    }

    return true;
}

/* ========================================================================
   Find or create user based on Google email
   Priority:
   1. User with real email (not temporary @phone.local email)
   2. If not found, create new user with subscriber role
   ======================================================================== */
function resolve_user(string $email, string $name, ?string $avatar_url): array {
    $email = strtolower(trim($email));

    /* Check if user exists with this email (only real emails) */
    $user = get_user_by('email', $email);

    if ($user && !str_contains($user->user_email, '@phone.local.')) {
        /* Update avatar if user doesn't have one */
        if (!get_user_meta($user->ID, 'ns_google_avatar', true) && $avatar_url) {
            update_user_meta($user->ID, 'ns_google_avatar', $avatar_url);
        }

        /* Mark as linked to Google */
        update_user_meta($user->ID, 'ns_google_linked', current_time('mysql'));

        return ['user_id' => (int) $user->ID, 'is_new' => false];
    }

    /* Create new user */
    $base = 'user_' . substr(md5($email), 0, 8);
    $username = $base;
    $i = 1;

    while (username_exists($username)) {
        $username = $base . '_' . $i++;
    }

    $user_id = wp_insert_user([
        'user_login'   => $username,
        'user_email'   => $email,
        'user_pass'    => wp_generate_password(24, true, true),
        'display_name' => $name ?: explode('@', $email)[0],
        'role'         => 'subscriber',
    ]);

    if (is_wp_error($user_id)) {
        return ['error' => 'user_creation_failed', 'detail' => $user_id->get_error_message()];
    }

    update_user_meta($user_id, 'ns_signup_method', 'google');
    update_user_meta($user_id, 'ns_google_linked', current_time('mysql'));

    if ($avatar_url) {
        update_user_meta($user_id, 'ns_google_avatar', $avatar_url);
    }

    return ['user_id' => (int) $user_id, 'is_new' => true];
}

/* ========================================================================
   Get Google avatar (if available)
   ======================================================================== */
function get_user_avatar(int $user_id): ?string {
    $google_avatar = get_user_meta($user_id, 'ns_google_avatar', true);

    return $google_avatar ?: null;
}