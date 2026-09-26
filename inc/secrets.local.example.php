<?php

/**
 * NextSafar Local Secrets - Example File
 *
 * IMPORTANT:
 * Copy this file to secrets.local.php and set real values there.
 *
 *   cp inc/secrets.local.example.php inc/secrets.local.php
 *
 * secrets.local.php is ignored by Git and must never be committed.
 */

if (!defined('ABSPATH')) exit;

return [
    /*
     * Next.js frontend URL used for ISR revalidation.
     * Local: http://localhost:3000
     * Production: https://nextsafar.com
     */
    'nextjs_url' => 'http://localhost:3000',

    /*
     * Shared secret between WordPress and Next.js /api/revalidate route.
     * Generate with:
     * openssl rand -hex 32
     */
    'nextjs_revalidation_secret' => 'REPLACE_WITH_NEXTJS_REVALIDATION_SECRET',

    /*
     * Internal secret used for AI Trip Planner background processing.
     * Generate with:
     * openssl rand -hex 32
     */
    'ai_trip_process_secret' => 'REPLACE_WITH_AI_PROCESS_SECRET',
];