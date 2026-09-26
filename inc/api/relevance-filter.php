<?php

/**
 * RelevanceFilter — Version 2.0 (Compatible)
 * ✅ Note 2: No longer has a separate keyword list in options;
 * everything is sourced from NewsFilter (single source).
 */

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

class RelevanceFilter {
    public function __construct($ai_client = null) {}

    public function is_relevant(array $item): array {
        if (get_option('nextsafar_news_relevance_filter') !== '1') {
            return ['relevant' => true, 'method' => 'disabled'];
        }

        $r = (new NewsFilter())->analyze_item($item);

        return [
            'relevant' => ($r['decision'] !== 'delete'),
            'method'   => 'newsfilter',
            'score'    => $r['score'],
            'reason'   => $r['reason'],
            'matched'  => $r['positive_matches'],
        ];
    }
}