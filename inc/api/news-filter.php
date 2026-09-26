<?php

/**
 * NextSafar News Filter
 * Version 3.0 — Single keyword source + three-stage decision (publish / review / delete)
 * Review items are checked with AI relevance in NewsSync.
 */

namespace NextSafar\API;

if (!defined('ABSPATH')) exit;

class NewsFilter {
    private $keywords_cache = null;

    /* ========================================================================
       Main API
       ======================================================================== */
    public function filter_items(array $items): array {
        $this->load_keywords();

        if (empty($this->keywords_cache)) {
            error_log('⚠️ NewsFilter: Keywords not available — all items go to AI review');

            foreach ($items as &$item) {
                $item['_filter_result'] = [
                    'score' => 0, 'decision' => 'review',
                    'reason' => 'کلمات کلیدی بارگذاری نشد - بررسی AI',
                    'positive_matches' => [], 'negative_matches' => [], 'ai_checked' => false,
                ];
            }

            return $items;
        }

        $filtered = [];

        foreach ($items as $item) {
            $result = $this->analyze_item($item);
            $item['_filter_result'] = $result;

            $symbol = $result['decision'] === 'delete'  ? '🗑️ Filtered:'
                    : ($result['decision'] === 'publish' ? '✅ PASSED' : '🤖 REVIEW');

            error_log(sprintf('%s: %s (score=%d, reason=%s)',
                $symbol, mb_substr($item['title'] ?? '', 0, 60), $result['score'], $result['reason']));

            if ($result['decision'] !== 'delete') $filtered[] = $item;
        }

        return $filtered;
    }

    /* ✅ Log filter decision for all news (including deleted ones) */
    private function log_filter_decision(array $item): void {
        if (empty($item['_filter_result'])) return;

        global $wpdb;

        $table = $wpdb->prefix . 'ns_news_filter_log';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return;

        $r = $item['_filter_result'];

        $wpdb->insert($table, [
            'news_title'       => sanitize_text_field(mb_substr($item['title'] ?? '', 0, 200)),
            'source_name'      => sanitize_text_field($item['source_name'] ?? ''),
            'score'            => (int) ($r['score'] ?? 0),
            'decision'         => sanitize_text_field($r['decision'] ?? 'unknown'),
            'reason'           => sanitize_text_field(mb_substr($r['reason'] ?? '', 0, 500)),
            'positive_matches' => maybe_serialize($r['positive_matches'] ?? []),
            'negative_matches' => maybe_serialize($r['negative_matches'] ?? []),
            'ai_checked'       => !empty($r['ai_checked']) ? 1 : 0,
        ]);
    }

    public function analyze_item(array $item): array {
        $text = ($item['title'] ?? '') . ' ' . ($item['content'] ?? '');
        $sd   = $this->calculate_keyword_score($text);
        $dec  = $this->make_decision($sd['score'], $sd['matches']);

        return [
            'score'            => $sd['score'],
            'decision'         => $dec['type'],
            'reason'           => $dec['reason'],
            'positive_matches' => $sd['matches']['positive'],
            'negative_matches' => $sd['matches']['negative'],
            'ai_checked'       => false,
        ];
    }

    public function get_ai_check_count(): int { return 0; } // AI no longer runs inside filter

    /* ========================================================================
       Scoring
       ======================================================================== */
    private function calculate_keyword_score(string $text): array {
        $score = 0;
        $matches = ['positive' => [], 'negative' => []];

        if ($this->keywords_cache === null) $this->load_keywords();

        if (empty($this->keywords_cache)) return ['score' => 0, 'matches' => $matches];

        $normalized = $this->normalize_text($text);

        if ($normalized === '') return ['score' => 0, 'matches' => $matches];

        foreach ($this->keywords_cache as $keyword) {
            if (empty($keyword['is_active']) || empty($keyword['keyword'])) continue;

            $kw = $this->normalize_text($keyword['keyword']);

            if ($kw === '' || mb_strlen($kw) < 2) continue;

            if (mb_strpos($normalized, $kw, 0, 'UTF-8') !== false) {
                $score += (int) ($keyword['weight'] ?? 0);

                $type = (($keyword['type'] ?? 'positive') === 'negative') ? 'negative' : 'positive';

                if (!in_array($keyword['keyword'], $matches[$type], true)) {
                    $matches[$type][] = $keyword['keyword'];
                }
            }
        }

        return ['score' => $score, 'matches' => $matches];
    }

    private function normalize_text(string $text): string {
        if ($text === '') return '';

        $text = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $text);
        $text = str_replace(['ي','ئ','ؤ','ة','ك','أ','إ','ٱ'], ['ی','ی','و','ه','ک','ا','ا','ا'], $text);
        $text = str_replace(["\u{200C}", "\u{FEFF}"], ' ', $text);
        $text = mb_strtolower($text, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Final decision — Version 3.0
     * delete  = definitive removal (no AI)
     * publish = direct publish
     * review  = AI check (including rejected items without strong negative keywords)
     */
    private function make_decision(int $score, array $matches): array {
        $publish_threshold = (int) get_option('nextsafar_news_filter_publish_threshold', 20);
        $delete_threshold  = (int) get_option('nextsafar_news_filter_delete_threshold', -10);

        $pc = count($matches['positive']);
        $nc = count($matches['negative']);

        if ($nc >= 2) {
            return ['type' => 'delete', 'reason' => 'کلمات منفی: ' . implode(', ', $matches['negative'])];
        }

        if ($score <= $delete_threshold) {
            return ['type' => 'delete', 'reason' => "امتیاز خیلی پایین ({$score}) - حذف قطعی"];
        }

        if ($score >= $publish_threshold) {
            return ['type' => 'publish', 'reason' => 'امتیاز عالی (' . $score . '): ' . implode(', ', $matches['positive'])];
        }

        if ($score >= 10 && $pc >= 2) {
            return ['type' => 'publish', 'reason' => "امتیاز متوسط ({$score}) با {$pc} کلمه مثبت"];
        }

        return ['type' => 'review', 'reason' => "امتیاز {$score} - نیاز به بررسی AI"];
    }

    /* ========================================================================
       Single keyword source
       ======================================================================== */
    private function load_keywords(): void {
        if ($this->keywords_cache !== null) return;

        global $wpdb;

        $table = $wpdb->prefix . 'ns_news_keywords';
        $rows = [];

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $rows = $wpdb->get_results(
                "SELECT keyword, type, weight, category, is_active FROM {$table} WHERE is_active = 1",
                ARRAY_A
            ) ?: [];
        }

        if (empty($rows)) {
            error_log('⚠️ NewsFilter: Keywords table is empty — using defaults');
            $rows = self::get_default_keyword_rows();
        }

        $this->keywords_cache = $rows;
    }

    /**
     * ✅ The single authoritative source of default keywords for the entire system
     * (NewsTables::seed_default_keywords and emergency filter also use this)
     */
    public static function get_default_keyword_rows(): array {
        $w = [
            'positive' => [
                // ═══════════ Persian - Core ═══════════
                'گردشگری' => ['w' => 25, 'c' => 'core'],
                'سفر' => ['w' => 15, 'c' => 'core'],
                'تور' => ['w' => 20, 'c' => 'core'],
                'تور مسافرتی' => ['w' => 25, 'c' => 'core'],
                'مسافر' => ['w' => 15, 'c' => 'core'],
                'هتل' => ['w' => 20, 'c' => 'core'],
                'اقامت' => ['w' => 15, 'c' => 'core'],
                'ویلا' => ['w' => 20, 'c' => 'core'],
                'بوم‌گردی' => ['w' => 25, 'c' => 'eco'],
                'بومگردی' => ['w' => 20, 'c' => 'eco'],
                'طبیعت‌گردی' => ['w' => 20, 'c' => 'eco'],
                'طبیعتگردی' => ['w' => 15, 'c' => 'eco'],
                'گردشگری سلامت' => ['w' => 15, 'c' => 'health'],
                'صنعت گردشگری' => ['w' => 20, 'c' => 'industry'],
                'پرواز' => ['w' => 20, 'c' => 'transport'],
                'بلیط' => ['w' => 15, 'c' => 'transport'],
                'فرودگاه' => ['w' => 15, 'c' => 'transport'],
                'ایرلاین' => ['w' => 20, 'c' => 'transport'],
                'ساحل' => ['w' => 15, 'c' => 'nature'],
                'جزیره' => ['w' => 15, 'c' => 'nature'],
                'دریاچه' => ['w' => 15, 'c' => 'nature'],
                'جنگل' => ['w' => 10, 'c' => 'nature'],
                'موزه' => ['w' => 10, 'c' => 'culture'],
                'جاذبه' => ['w' => 20, 'c' => 'culture'],
                'جاذبه گردشگری' => ['w' => 25, 'c' => 'culture'],
                'ثبت ملی' => ['w' => 15, 'c' => 'culture'],
                'میراث فرهنگی' => ['w' => 20, 'c' => 'culture'],
                'توریست' => ['w' => 20, 'c' => 'core'],
                'توریسم' => ['w' => 25, 'c' => 'core'],
                'کوهنوردی' => ['w' => 15, 'c' => 'eco'],
                'کمپ' => ['w' => 10, 'c' => 'eco'],
                'چادر' => ['w' => 10, 'c' => 'eco'],
                'کاروان' => ['w' => 15, 'c' => 'transport'],
                'اقامتگاه' => ['w' => 15, 'c' => 'core'],
                'مهمان‌خانه' => ['w' => 15, 'c' => 'core'],
                'هاستل' => ['w' => 15, 'c' => 'core'],
                'ریزورت' => ['w' => 20, 'c' => 'core'],
                'مسافرت' => ['w' => 15, 'c' => 'core'],
                'رستوران' => ['w' => 10, 'c' => 'food'],
                'کافه' => ['w' => 10, 'c' => 'food'],
                'غذای محلی' => ['w' => 15, 'c' => 'food'],
                'سوغات' => ['w' => 10, 'c' => 'food'],
                'ویزا' => ['w' => 20, 'c' => 'core'],
                'پاسپورت' => ['w' => 15, 'c' => 'core'],
                'توریستی' => ['w' => 20, 'c' => 'core'],
                'سیاحتی' => ['w' => 20, 'c' => 'core'],
                // ═══════════ ⭐ Persian - New Transport ═══════════
                'قطار' => ['w' => 15, 'c' => 'transport'],
                'اتوبوس' => ['w' => 10, 'c' => 'transport'],
                'کشتی کروز' => ['w' => 20, 'c' => 'cruise'],
                'کشتی' => ['w' => 10, 'c' => 'cruise'],
                'کروز' => ['w' => 20, 'c' => 'cruise'],
                'بند' => ['w' => 10, 'c' => 'cruise'],
                'اسکله' => ['w' => 10, 'c' => 'transport'],
                'ترمینال' => ['w' => 10, 'c' => 'transport'],
                'ایستگاه قطار' => ['w' => 15, 'c' => 'transport'],
                'تاکسی' => ['w' => 5, 'c' => 'transport'],
                'اجاره خودرو' => ['w' => 15, 'c' => 'transport'],
                'ترانسفر' => ['w' => 10, 'c' => 'transport'],
                // ═══════════ ⭐ Persian - Accommodation Types ═══════════
                'هتل بوتیک' => ['w' => 20, 'c' => 'boutique'],
                'هتل آپارتمان' => ['w' => 15, 'c' => 'core'],
                'سوئیت' => ['w' => 15, 'c' => 'core'],
                'پانسیون' => ['w' => 10, 'c' => 'core'],
                'متل' => ['w' => 10, 'c' => 'core'],
                'خانه روستایی' => ['w' => 15, 'c' => 'eco'],
                'کلبه' => ['w' => 15, 'c' => 'eco'],
                'کلبه جنگلی' => ['w' => 20, 'c' => 'eco'],
                'اقامتگاه بوم‌گردی' => ['w' => 25, 'c' => 'eco'],
                'کمپینگ' => ['w' => 15, 'c' => 'eco'],
                'کاروانسرا' => ['w' => 20, 'c' => 'heritage'],
                'هتل سنتی' => ['w' => 20, 'c' => 'heritage'],
                'خانه اجاره‌ای' => ['w' => 15, 'c' => 'core'],
                'ویلا اجاره‌ای' => ['w' => 20, 'c' => 'core'],
                // ═══════════ ⭐ Persian - Iran Places (Extended) ═══════════
                'شمال' => ['w' => 10, 'c' => 'place'],
                'کیش' => ['w' => 15, 'c' => 'place'],
                'قشم' => ['w' => 15, 'c' => 'place'],
                'مشهد' => ['w' => 10, 'c' => 'place'],
                'اصفهان' => ['w' => 10, 'c' => 'place'],
                'شیراز' => ['w' => 10, 'c' => 'place'],
                'یزد' => ['w' => 10, 'c' => 'place'],
                'تبریز' => ['w' => 10, 'c' => 'place'],
                'رامسر' => ['w' => 15, 'c' => 'place'],
                'چابهار' => ['w' => 20, 'c' => 'place'],
                'همدان' => ['w' => 10, 'c' => 'place'],
                'کرمان' => ['w' => 10, 'c' => 'place'],
                'کرمانشاه' => ['w' => 10, 'c' => 'place'],
                'لرستان' => ['w' => 10, 'c' => 'place'],
                'کردستان' => ['w' => 10, 'c' => 'place'],
                'گیلان' => ['w' => 10, 'c' => 'place'],
                'مازندران' => ['w' => 10, 'c' => 'place'],
                'رشت' => ['w' => 10, 'c' => 'place'],
                'ساری' => ['w' => 10, 'c' => 'place'],
                'بندرعباس' => ['w' => 10, 'c' => 'place'],
                'اهواز' => ['w' => 10, 'c' => 'place'],
                'ارومیه' => ['w' => 10, 'c' => 'place'],
                'زنجان' => ['w' => 10, 'c' => 'place'],
                'اردبیل' => ['w' => 10, 'c' => 'place'],
                'سمنان' => ['w' => 10, 'c' => 'place'],
                'بوشهر' => ['w' => 10, 'c' => 'place'],
                'هرمز' => ['w' => 15, 'c' => 'place'],
                'لارستان' => ['w' => 15, 'c' => 'place'],
                'تخت جمشید' => ['w' => 25, 'c' => 'heritage'],
                'نقش جهان' => ['w' => 20, 'c' => 'heritage'],
                'پاسارگاد' => ['w' => 20, 'c' => 'heritage'],
                'تخت سلیمان' => ['w' => 20, 'c' => 'heritage'],
                'بام ایران' => ['w' => 15, 'c' => 'nature'],
                'دماوند' => ['w' => 15, 'c' => 'nature'],
                'سبلان' => ['w' => 15, 'c' => 'nature'],
                'علم‌الکوه' => ['w' => 15, 'c' => 'nature'],
                'کویر لوت' => ['w' => 20, 'c' => 'nature'],
                'کویر مصر' => ['w' => 15, 'c' => 'nature'],
                'جنگل‌های هیرکانی' => ['w' => 25, 'c' => 'nature'],
                // ═══════════ ⭐ Persian - World Places (Extended) ═══════════
                'دبی' => ['w' => 15, 'c' => 'place'],
                'استانبول' => ['w' => 15, 'c' => 'place'],
                'آنتالیا' => ['w' => 15, 'c' => 'place'],
                'تفلیس' => ['w' => 15, 'c' => 'place'],
                'باکو' => ['w' => 15, 'c' => 'place'],
                'ارمنستان' => ['w' => 15, 'c' => 'place'],
                'تایلند' => ['w' => 15, 'c' => 'place'],
                'بالی' => ['w' => 15, 'c' => 'place'],
                'پاریس' => ['w' => 15, 'c' => 'place'],
                'لندن' => ['w' => 15, 'c' => 'place'],
                'رم' => ['w' => 15, 'c' => 'place'],
                'بارسلونا' => ['w' => 15, 'c' => 'place'],
                'آمستردام' => ['w' => 15, 'c' => 'place'],
                'پراگ' => ['w' => 15, 'c' => 'place'],
                'وین' => ['w' => 15, 'c' => 'place'],
                'بوداپست' => ['w' => 15, 'c' => 'place'],
                'مادرید' => ['w' => 15, 'c' => 'place'],
                'لیسبون' => ['w' => 15, 'c' => 'place'],
                'توکیو' => ['w' => 15, 'c' => 'place'],
                'سئول' => ['w' => 15, 'c' => 'place'],
                'سنگاپور' => ['w' => 15, 'c' => 'place'],
                'کوالالامپور' => ['w' => 15, 'c' => 'place'],
                'هانوی' => ['w' => 15, 'c' => 'place'],
                'کاتماندو' => ['w' => 15, 'c' => 'place'],
                'مراکش' => ['w' => 15, 'c' => 'place'],
                'قاهره' => ['w' => 15, 'c' => 'place'],
                'کیپ‌تاون' => ['w' => 15, 'c' => 'place'],
                'ریودوژانیرو' => ['w' => 15, 'c' => 'place'],
                'نیویورک' => ['w' => 15, 'c' => 'place'],
                'لس‌آنجلس' => ['w' => 15, 'c' => 'place'],
                'لاس‌وگاس' => ['w' => 15, 'c' => 'place'],
                'مالدیو' => ['w' => 20, 'c' => 'place'],
                'موریس' => ['w' => 20, 'c' => 'place'],
                'سیشل' => ['w' => 20, 'c' => 'place'],
                'زنگبار' => ['w' => 20, 'c' => 'place'],
                'میکونوس' => ['w' => 20, 'c' => 'place'],
                'سانتورینی' => ['w' => 20, 'c' => 'place'],
                'کاپادوکیا' => ['w' => 20, 'c' => 'place'],
                'پوکت' => ['w' => 15, 'c' => 'place'],
                'سامویی' => ['w' => 15, 'c' => 'place'],
                'باتومی' => ['w' => 15, 'c' => 'place'],
                'ایروان' => ['w' => 15, 'c' => 'place'],
                'دوحه' => ['w' => 15, 'c' => 'place'],
                'ابوظبی' => ['w' => 15, 'c' => 'place'],
                'مسقط' => ['w' => 15, 'c' => 'place'],
                // ═══════════ ⭐ Persian - Activities & Adventure ═══════════
                'غواصی' => ['w' => 20, 'c' => 'adventure'],
                'اسنورکلینگ' => ['w' => 15, 'c' => 'adventure'],
                'اسکی' => ['w' => 15, 'c' => 'winter'],
                'اسنوبرد' => ['w' => 15, 'c' => 'winter'],
                'پیست اسکی' => ['w' => 20, 'c' => 'winter'],
                'اسکی روی آب' => ['w' => 15, 'c' => 'adventure'],
                'پاراسل' => ['w' => 15, 'c' => 'adventure'],
                'پاراگلایدر' => ['w' => 15, 'c' => 'adventure'],
                'بانجی جامپینگ' => ['w' => 15, 'c' => 'adventure'],
                'سافاری' => ['w' => 20, 'c' => 'adventure'],
                'آفرود' => ['w' => 15, 'c' => 'adventure'],
                ' ATV' => ['w' => 15, 'c' => 'adventure'],
                'کوهپیمایی' => ['w' => 15, 'c' => 'eco'],
                'صخره‌نوردی' => ['w' => 15, 'c' => 'adventure'],
                'قایقرانی' => ['w' => 15, 'c' => 'adventure'],
                'رفتینگ' => ['w' => 15, 'c' => 'adventure'],
                'ماهیگیری' => ['w' => 10, 'c' => 'adventure'],
                'دوچرخه‌سواری' => ['w' => 10, 'c' => 'eco'],
                'پیاده‌گردی' => ['w' => 15, 'c' => 'eco'],
                'تور مجازی' => ['w' => 15, 'c' => 'digital'],
                'فتوگرافی' => ['w' => 10, 'c' => 'culture'],
                'عکاسی' => ['w' => 10, 'c' => 'culture'],
                // ═══════════ ⭐ Persian - Modern Tourism Concepts ═══════════
                'ساحلی' => ['w' => 15, 'c' => 'nature'],
                'کوهستانی' => ['w' => 10, 'c' => 'nature'],
                'تاریخی' => ['w' => 10, 'c' => 'culture'],
                'باستانی' => ['w' => 10, 'c' => 'culture'],
                'طبیعی' => ['w' => 10, 'c' => 'nature'],
                'لوکس' => ['w' => 10, 'c' => 'general'],
                'اقتصادی' => ['w' => 5, 'c' => 'general'],
                'ارزان' => ['w' => 5, 'c' => 'general'],
                'دیجیتال نومد' => ['w' => 20, 'c' => 'digital'],
                'نومد دیجیتال' => ['w' => 20, 'c' => 'digital'],
                'سفر کاری' => ['w' => 15, 'c' => 'business'],
                'گردشگری دیجیتال' => ['w' => 20, 'c' => 'digital'],
                'گردشگری ماجراجویانه' => ['w' => 20, 'c' => 'adventure'],
                'گردشگری پایدار' => ['w' => 20, 'c' => 'eco'],
                'گردشگری حلال' => ['w' => 15, 'c' => 'culture'],
                'گردشگری غذایی' => ['w' => 20, 'c' => 'food'],
                'توریسم غذایی' => ['w' => 20, 'c' => 'food'],
                'گردشگری فرهنگی' => ['w' => 20, 'c' => 'culture'],
                'سفر خانوادگی' => ['w' => 15, 'c' => 'family'],
                'ماه عسل' => ['w' => 20, 'c' => 'romantic'],
                'سفر دونفره' => ['w' => 15, 'c' => 'romantic'],
                'سفر انفرادی' => ['w' => 10, 'c' => 'solo'],
                'سفر گروهی' => ['w' => 10, 'c' => 'group'],
                'تور لحظه آخری' => ['w' => 20, 'c' => 'deal'],
                'تخفیف هتل' => ['w' => 15, 'c' => 'deal'],
                'رزرو آنلاین' => ['w' => 15, 'c' => 'digital'],
                'سفرنامه' => ['w' => 15, 'c' => 'blog'],
                'ولاگ سفر' => ['w' => 15, 'c' => 'blog'],
                'راهنمای سفر' => ['w' => 20, 'c' => 'guide'],
                // ═══════════ ⭐ Persian - Events & Festivals ═══════════
                'جشنواره' => ['w' => 15, 'c' => 'festival'],
                'فستیوال' => ['w' => 15, 'c' => 'festival'],
                'نمایشگاه گردشگری' => ['w' => 25, 'c' => 'festival'],
                'نمایشگاه توریسم' => ['w' => 25, 'c' => 'festival'],
                'کارناوال' => ['w' => 15, 'c' => 'festival'],
                'نوروز' => ['w' => 10, 'c' => 'festival'],
                'عید' => ['w' => 10, 'c' => 'festival'],
                'تعطیلات' => ['w' => 15, 'c' => 'festival'],
                'تعطیلات نوروزی' => ['w' => 20, 'c' => 'festival'],
                // ═══════════ ⭐ Persian - Local Food & Experiences ═══════════
                'آشپزی محلی' => ['w' => 15, 'c' => 'food'],
                'دمنوش' => ['w' => 10, 'c' => 'food'],
                'چایخانه' => ['w' => 10, 'c' => 'food'],
                'سنتی' => ['w' => 10, 'c' => 'culture'],
                'صنایع دستی' => ['w' => 15, 'c' => 'shopping'],
                'بازار محلی' => ['w' => 15, 'c' => 'shopping'],
                'بازارچه' => ['w' => 10, 'c' => 'shopping'],
                'خرید' => ['w' => 10, 'c' => 'shopping'],
                'پاساژ' => ['w' => 5, 'c' => 'shopping'],
                'مال' => ['w' => 5, 'c' => 'shopping'],
                'اسپا' => ['w' => 15, 'c' => 'wellness'],
                'ماساژ' => ['w' => 10, 'c' => 'wellness'],
                'حمام ترکی' => ['w' => 15, 'c' => 'wellness'],
                'یوگا' => ['w' => 15, 'c' => 'wellness'],
                'مدیتیشن' => ['w' => 15, 'c' => 'wellness'],
                'آبگرم' => ['w' => 15, 'c' => 'wellness'],
                'چشمه آبگرم' => ['w' => 20, 'c' => 'wellness'],
                // ═══════════ English ═══════════
                'tourism' => ['w' => 25, 'c' => 'en'],
                'tourist' => ['w' => 20, 'c' => 'en'],
                'travel' => ['w' => 15, 'c' => 'en'],
                'trip' => ['w' => 15, 'c' => 'en'],
                'vacation' => ['w' => 15, 'c' => 'en'],
                'holiday' => ['w' => 15, 'c' => 'en'],
                'journey' => ['w' => 15, 'c' => 'en'],
                'tour' => ['w' => 20, 'c' => 'en'],
                'hotel' => ['w' => 20, 'c' => 'en'],
                'resort' => ['w' => 20, 'c' => 'en'],
                'hostel' => ['w' => 15, 'c' => 'en'],
                'lodge' => ['w' => 15, 'c' => 'en'],
                'flight' => ['w' => 20, 'c' => 'en'],
                'airport' => ['w' => 15, 'c' => 'en'],
                'airline' => ['w' => 20, 'c' => 'en'],
                'aviation' => ['w' => 15, 'c' => 'en'],
                'destination' => ['w' => 15, 'c' => 'en'],
                'attraction' => ['w' => 15, 'c' => 'en'],
                'landmark' => ['w' => 15, 'c' => 'en'],
                'sightseeing' => ['w' => 15, 'c' => 'en'],
                'backpacking' => ['w' => 15, 'c' => 'en'],
                'camping' => ['w' => 10, 'c' => 'en'],
                'beach' => ['w' => 10, 'c' => 'en'],
                'island' => ['w' => 15, 'c' => 'en'],
                'mountain' => ['w' => 10, 'c' => 'en'],
                'hiking' => ['w' => 15, 'c' => 'en'],
                'museum' => ['w' => 10, 'c' => 'en'],
                'heritage' => ['w' => 15, 'c' => 'en'],
                'unesco' => ['w' => 20, 'c' => 'en'],
                'overtourism' => ['w' => 20, 'c' => 'en'],
                'ecotourism' => ['w' => 25, 'c' => 'en'],
                'agritourism' => ['w' => 20, 'c' => 'en'],
                // ═══════════ ⭐ English - Extended ═══════════
                'boutique hotel' => ['w' => 20, 'c' => 'en'],
                'all inclusive' => ['w' => 20, 'c' => 'en'],
                'cruise ship' => ['w' => 20, 'c' => 'en'],
                'river cruise' => ['w' => 20, 'c' => 'en'],
                'safari' => ['w' => 20, 'c' => 'en'],
                'wildlife' => ['w' => 15, 'c' => 'en'],
                'national park' => ['w' => 20, 'c' => 'en'],
                'wellness retreat' => ['w' => 20, 'c' => 'en'],
                'spa resort' => ['w' => 20, 'c' => 'en'],
                'ski resort' => ['w' => 20, 'c' => 'en'],
                'scuba diving' => ['w' => 20, 'c' => 'en'],
                'snorkeling' => ['w' => 15, 'c' => 'en'],
                'surfing' => ['w' => 15, 'c' => 'en'],
                'kite surfing' => ['w' => 15, 'c' => 'en'],
                'windsurfing' => ['w' => 15, 'c' => 'en'],
                'paragliding' => ['w' => 15, 'c' => 'en'],
                'skydiving' => ['w' => 15, 'c' => 'en'],
                'hot air balloon' => ['w' => 20, 'c' => 'en'],
                'road trip' => ['w' => 15, 'c' => 'en'],
                'campervan' => ['w' => 15, 'c' => 'en'],
                'RV' => ['w' => 15, 'c' => 'en'],
                'glamping' => ['w' => 20, 'c' => 'en'],
                'digital nomad' => ['w' => 20, 'c' => 'en'],
                'remote work' => ['w' => 15, 'c' => 'en'],
                'workation' => ['w' => 20, 'c' => 'en'],
                'honeymoon' => ['w' => 20, 'c' => 'en'],
                'family travel' => ['w' => 15, 'c' => 'en'],
                'solo travel' => ['w' => 15, 'c' => 'en'],
                'luxury travel' => ['w' => 20, 'c' => 'en'],
                'budget travel' => ['w' => 15, 'c' => 'en'],
                'last minute' => ['w' => 15, 'c' => 'en'],
                'travel insurance' => ['w' => 15, 'c' => 'en'],
                'visa free' => ['w' => 20, 'c' => 'en'],
                'e-visa' => ['w' => 15, 'c' => 'en'],
                'passport' => ['w' => 15, 'c' => 'en'],
                'immigration' => ['w' => 10, 'c' => 'en'],
                'customs' => ['w' => 10, 'c' => 'en'],
                'layover' => ['w' => 10, 'c' => 'en'],
                'stopover' => ['w' => 10, 'c' => 'en'],
                'first class' => ['w' => 15, 'c' => 'en'],
                'business class' => ['w' => 15, 'c' => 'en'],
                'economy class' => ['w' => 10, 'c' => 'en'],
                'low cost airline' => ['w' => 15, 'c' => 'en'],
                'ryanair' => ['w' => 10, 'c' => 'en'],
                'emirates' => ['w' => 15, 'c' => 'en'],
                'qatar airways' => ['w' => 15, 'c' => 'en'],
                'turkish airlines' => ['w' => 15, 'c' => 'en'],
                'airbnb' => ['w' => 20, 'c' => 'en'],
                'booking.com' => ['w' => 15, 'c' => 'en'],
                'tripadvisor' => ['w' => 15, 'c' => 'en'],
                'expedia' => ['w' => 15, 'c' => 'en'],
                'michelin star' => ['w' => 20, 'c' => 'en'],
                'street food' => ['w' => 15, 'c' => 'en'],
                'food tour' => ['w' => 20, 'c' => 'en'],
                'wine tasting' => ['w' => 15, 'c' => 'en'],
                'gastronomy' => ['w' => 20, 'c' => 'en'],
                'culinary' => ['w' => 15, 'c' => 'en'],
                'travel blogger' => ['w' => 15, 'c' => 'en'],
                'travel vlog' => ['w' => 15, 'c' => 'en'],
                'wanderlust' => ['w' => 15, 'c' => 'en'],
                'itinerary' => ['w' => 15, 'c' => 'en'],
                'off the beaten path' => ['w' => 20, 'c' => 'en'],
                'hidden gem' => ['w' => 20, 'c' => 'en'],
                'bucket list' => ['w' => 15, 'c' => 'en'],
                'sustainable travel' => ['w' => 20, 'c' => 'en'],
                'responsible tourism' => ['w' => 20, 'c' => 'en'],
                'carbon neutral' => ['w' => 15, 'c' => 'en'],
                'voluntourism' => ['w' => 15, 'c' => 'en'],
                'medical tourism' => ['w' => 20, 'c' => 'en'],
                'dark tourism' => ['w' => 10, 'c' => 'en'],
                'religious tourism' => ['w' => 15, 'c' => 'en'],
                'pilgrimage' => ['w' => 15, 'c' => 'en'],
                'carnival' => ['w' => 15, 'c' => 'en'],
                'oktoberfest' => ['w' => 15, 'c' => 'en'],
                'diwali' => ['w' => 10, 'c' => 'en'],
                'cherry blossom' => ['w' => 15, 'c' => 'en'],
                'northern lights' => ['w' => 20, 'c' => 'en'],
                'aurora borealis' => ['w' => 20, 'c' => 'en'],
                'santorini' => ['w' => 15, 'c' => 'en'],
                'maldives' => ['w' => 20, 'c' => 'en'],
                'patagonia' => ['w' => 20, 'c' => 'en'],
                'machu picchu' => ['w' => 25, 'c' => 'en'],
                'great wall' => ['w' => 20, 'c' => 'en'],
                'taj mahal' => ['w' => 25, 'c' => 'en'],
                'grand canyon' => ['w' => 20, 'c' => 'en'],
            ],
            'negative' => [
                // ═══════════ Military & War ═══════════
                'جنگ' => ['w' => -30, 'c' => 'military'],
                'حمله' => ['w' => -30, 'c' => 'military'],
                'حمله نظامی' => ['w' => -30, 'c' => 'military'],
                'بمباران' => ['w' => -30, 'c' => 'military'],
                'موشک' => ['w' => -30, 'c' => 'military'],
                'پهپاد' => ['w' => -20, 'c' => 'military'],
                'سلاح' => ['w' => -20, 'c' => 'military'],
                'تفنگ' => ['w' => -25, 'c' => 'military'],
                'تیراندازی' => ['w' => -25, 'c' => 'military'],
                'ترور' => ['w' => -30, 'c' => 'military'],
                'تروریسم' => ['w' => -30, 'c' => 'military'],
                'تروریست' => ['w' => -30, 'c' => 'military'],
                'انفجار' => ['w' => -30, 'c' => 'military'],
                'بمب' => ['w' => -30, 'c' => 'military'],
                'گروگان' => ['w' => -30, 'c' => 'military'],
                'ربایش' => ['w' => -25, 'c' => 'military'],
                'کودتا' => ['w' => -25, 'c' => 'military'],
                'شبه‌نظامی' => ['w' => -25, 'c' => 'military'],
                'پنتاگون' => ['w' => -15, 'c' => 'military'],
                'ارتش' => ['w' => -15, 'c' => 'military'],
                'سپاه' => ['w' => -15, 'c' => 'military'],
                'ناو' => ['w' => -15, 'c' => 'military'],
                'تانک' => ['w' => -20, 'c' => 'military'],
                'تحریم' => ['w' => -20, 'c' => 'politics'],
                // ═══════════ Accidents & Incidents ═══════════
                'کشته' => ['w' => -25, 'c' => 'accident'],
                'زخمی' => ['w' => -20, 'c' => 'accident'],
                'تصادف' => ['w' => -20, 'c' => 'accident'],
                'حادثه' => ['w' => -15, 'c' => 'accident'],
                'سقوط' => ['w' => -25, 'c' => 'accident'],
                'سقوط هواپیما' => ['w' => -30, 'c' => 'accident'],
                'واژگونی' => ['w' => -20, 'c' => 'accident'],
                'تصادف زنجیره‌ای' => ['w' => -25, 'c' => 'accident'],
                'غرق' => ['w' => -25, 'c' => 'accident'],
                'غرق شدن' => ['w' => -25, 'c' => 'accident'],
                'آتش‌سوزی' => ['w' => -25, 'c' => 'accident'],
                'آتش سوزی' => ['w' => -25, 'c' => 'accident'],
                'حریق' => ['w' => -20, 'c' => 'accident'],
                'مصدوم' => ['w' => -20, 'c' => 'accident'],
                'جان باخت' => ['w' => -25, 'c' => 'accident'],
                'فوتی' => ['w' => -25, 'c' => 'accident'],
                'ناپدید' => ['w' => -20, 'c' => 'accident'],
                'مفقود' => ['w' => -20, 'c' => 'accident'],
                'ناپدید شدن' => ['w' => -20, 'c' => 'accident'],
                // ═══════════ Sports (Unrelated) ═══════════
                'فوتبال' => ['w' => -30, 'c' => 'sports'],
                'لیگ' => ['w' => -25, 'c' => 'sports'],
                'ورزشگاه' => ['w' => -15, 'c' => 'sports'],
                'تیم ملی' => ['w' => -25, 'c' => 'sports'],
                'جام جهانی' => ['w' => -25, 'c' => 'sports'],
                'جام ملت‌ها' => ['w' => -25, 'c' => 'sports'],
                'پرسپولیس' => ['w' => -25, 'c' => 'sports'],
                'استقلال' => ['w' => -25, 'c' => 'sports'],
                'دربی' => ['w' => -25, 'c' => 'sports'],
                'لیگ برتر' => ['w' => -25, 'c' => 'sports'],
                'والیبال' => ['w' => -25, 'c' => 'sports'],
                'بسکتبال' => ['w' => -25, 'c' => 'sports'],
                'کشتی' => ['w' => -15, 'c' => 'sports'],
                'بوکس' => ['w' => -20, 'c' => 'sports'],
                'وزنه‌برداری' => ['w' => -20, 'c' => 'sports'],
                'مدال' => ['w' => -15, 'c' => 'sports'],
                'المپیک' => ['w' => -20, 'c' => 'sports'],
                'مربی' => ['w' => -15, 'c' => 'sports'],
                'بازیکن' => ['w' => -20, 'c' => 'sports'],
                'گلزن' => ['w' => -20, 'c' => 'sports'],
                'پنالتی' => ['w' => -20, 'c' => 'sports'],
                'داور' => ['w' => -15, 'c' => 'sports'],
                // ═══════════ Natural Disasters ═══════════
                'سیل' => ['w' => -20, 'c' => 'disaster'],
                'زلزله' => ['w' => -25, 'c' => 'disaster'],
                'زمین‌لرزه' => ['w' => -25, 'c' => 'disaster'],
                'توفان' => ['w' => -20, 'c' => 'disaster'],
                'طوفان' => ['w' => -20, 'c' => 'disaster'],
                'گردباد' => ['w' => -20, 'c' => 'disaster'],
                'سونامی' => ['w' => -30, 'c' => 'disaster'],
                'آتشفشان' => ['w' => -25, 'c' => 'disaster'],
                'خشکسالی' => ['w' => -20, 'c' => 'disaster'],
                'رانش زمین' => ['w' => -20, 'c' => 'disaster'],
                'بهمن' => ['w' => -20, 'c' => 'disaster'],
                'کولاک' => ['w' => -15, 'c' => 'disaster'],
                'یخبندان' => ['w' => -15, 'c' => 'disaster'],
                'مخاطرات جوی' => ['w' => -15, 'c' => 'disaster'],
                // ═══════════ Education ═══════════
                'دانشگاه' => ['w' => -20, 'c' => 'education'],
                'کنکور' => ['w' => -25, 'c' => 'education'],
                'مدرسه' => ['w' => -20, 'c' => 'education'],
                'آموزش' => ['w' => -10, 'c' => 'education'],
                'دانشجو' => ['w' => -15, 'c' => 'education'],
                'دانش‌آموز' => ['w' => -15, 'c' => 'education'],
                'امتحانات' => ['w' => -20, 'c' => 'education'],
                'معلم' => ['w' => -10, 'c' => 'education'],
                'استاد' => ['w' => -10, 'c' => 'education'],
                'پایان‌نامه' => ['w' => -20, 'c' => 'education'],
                'کنکور سراسری' => ['w' => -25, 'c' => 'education'],
                'قبولی' => ['w' => -15, 'c' => 'education'],
                // ═══════════ Politics ═══════════
                'مجلس' => ['w' => -25, 'c' => 'politics'],
                'دولت' => ['w' => -15, 'c' => 'politics'],
                'انتخابات' => ['w' => -30, 'c' => 'politics'],
                'سیاسی' => ['w' => -20, 'c' => 'politics'],
                'استیضاح' => ['w' => -25, 'c' => 'politics'],
                'وزیر' => ['w' => -20, 'c' => 'politics'],
                'رئیس جمهور' => ['w' => -20, 'c' => 'politics'],
                'پارلمان' => ['w' => -20, 'c' => 'politics'],
                'نماینده' => ['w' => -15, 'c' => 'politics'],
                'لایحه' => ['w' => -20, 'c' => 'politics'],
                'قانون' => ['w' => -15, 'c' => 'politics'],
                'مجلس شورای' => ['w' => -25, 'c' => 'politics'],
                'دیپلمات' => ['w' => -10, 'c' => 'politics'],
                'سفیر' => ['w' => -10, 'c' => 'politics'],
                'دیپلماسی' => ['w' => -10, 'c' => 'politics'],
                'مذاکره' => ['w' => -15, 'c' => 'politics'],
                'توافق' => ['w' => -15, 'c' => 'politics'],
                'تحریم‌ها' => ['w' => -20, 'c' => 'politics'],
                // ═══════════ Economy & Finance ═══════════
                'دلار' => ['w' => -25, 'c' => 'finance'],
                'تورم' => ['w' => -25, 'c' => 'finance'],
                'بورس' => ['w' => -20, 'c' => 'finance'],
                'سهام' => ['w' => -30, 'c' => 'finance'],
                'طلا' => ['w' => -15, 'c' => 'finance'],
                'نفت' => ['w' => -15, 'c' => 'finance'],
                'بنزین' => ['w' => -15, 'c' => 'finance'],
                'گرانی' => ['w' => -20, 'c' => 'finance'],
                'رکود' => ['w' => -25, 'c' => 'finance'],
                'رکود اقتصادی' => ['w' => -25, 'c' => 'finance'],
                'نرخ ارز' => ['w' => -20, 'c' => 'finance'],
                'ارز' => ['w' => -15, 'c' => 'finance'],
                'سکه' => ['w' => -15, 'c' => 'finance'],
                'بانک مرکزی' => ['w' => -15, 'c' => 'finance'],
                'بدهی' => ['w' => -20, 'c' => 'finance'],
                'ورشکستگی' => ['w' => -25, 'c' => 'finance'],
                'بحران اقتصادی' => ['w' => -30, 'c' => 'finance'],
                'بودجه' => ['w' => -10, 'c' => 'finance'],
                'مالیات' => ['w' => -15, 'c' => 'finance'],
                // ═══════════ Medical & Disease ═══════════
                'بیمارستان' => ['w' => -15, 'c' => 'health'],
                'پزشکی' => ['w' => -15, 'c' => 'health'],
                'جراحی' => ['w' => -20, 'c' => 'health'],
                'دارو' => ['w' => -15, 'c' => 'health'],
                'کرونا' => ['w' => -15, 'c' => 'health'],
                'کووید' => ['w' => -20, 'c' => 'health'],
                'ویروس' => ['w' => -20, 'c' => 'health'],
                'پاندمی' => ['w' => -25, 'c' => 'health'],
                'اپیدمی' => ['w' => -25, 'c' => 'health'],
                'واکسن' => ['w' => -15, 'c' => 'health'],
                'مرگ' => ['w' => -20, 'c' => 'health'],
                'سرطان' => ['w' => -20, 'c' => 'health'],
                'شیمی‌درمانی' => ['w' => -20, 'c' => 'health'],
                'بیماری' => ['w' => -15, 'c' => 'health'],
                'عفونت' => ['w' => -20, 'c' => 'health'],
                'وبا' => ['w' => -25, 'c' => 'health'],
                // ═══════════ Crime & Judiciary ═══════════
                'دادگاه' => ['w' => -20, 'c' => 'crime'],
                'حبس' => ['w' => -25, 'c' => 'crime'],
                'جرم' => ['w' => -25, 'c' => 'crime'],
                'جنایت' => ['w' => -30, 'c' => 'crime'],
                'قتل' => ['w' => -30, 'c' => 'crime'],
                'دزدی' => ['w' => -25, 'c' => 'crime'],
                'سرقت' => ['w' => -25, 'c' => 'crime'],
                'کلاهبرداری' => ['w' => -25, 'c' => 'crime'],
                'اختلاس' => ['w' => -25, 'c' => 'crime'],
                'رشوه' => ['w' => -25, 'c' => 'crime'],
                'فساد' => ['w' => -20, 'c' => 'crime'],
                'اعتیاد' => ['w' => -20, 'c' => 'crime'],
                'مواد مخدر' => ['w' => -25, 'c' => 'crime'],
                'قاچاق' => ['w' => -25, 'c' => 'crime'],
                'قاچاق انسان' => ['w' => -30, 'c' => 'crime'],
                'تجاوز' => ['w' => -30, 'c' => 'crime'],
                'خشونت' => ['w' => -25, 'c' => 'crime'],
                'خشونت خانگی' => ['w' => -25, 'c' => 'crime'],
                'دستگیری' => ['w' => -20, 'c' => 'crime'],
                'بازداشت' => ['w' => -20, 'c' => 'crime'],
                'زندانی' => ['w' => -20, 'c' => 'crime'],
                'زندان' => ['w' => -20, 'c' => 'crime'],
                'احکام' => ['w' => -15, 'c' => 'crime'],
                'محکومیت' => ['w' => -20, 'c' => 'crime'],
                // ═══════════ Protests & Unrest ═══════════
                'اعتصاب' => ['w' => -20, 'c' => 'politics'],
                'تظاهرات' => ['w' => -25, 'c' => 'politics'],
                'شورش' => ['w' => -30, 'c' => 'politics'],
                'آشوب' => ['w' => -30, 'c' => 'politics'],
                'اغتشاش' => ['w' => -25, 'c' => 'politics'],
                'ناآرامی' => ['w' => -20, 'c' => 'politics'],
                'بلوا' => ['w' => -25, 'c' => 'politics'],
                'شکایت' => ['w' => -15, 'c' => 'crime'],
                // ═══════════ International Politics ═══════════
                'کره شمالی' => ['w' => -20, 'c' => 'politics'],
                'اسرائیل' => ['w' => -15, 'c' => 'military'],
                'صهیونیست' => ['w' => -15, 'c' => 'military'],
                'پوتین' => ['w' => -15, 'c' => 'politics'],
                'ترامپ' => ['w' => -15, 'c' => 'politics'],
                'بایدن' => ['w' => -15, 'c' => 'politics'],
                'ناتو' => ['w' => -15, 'c' => 'military'],
                'اتحادیه اروپا' => ['w' => -10, 'c' => 'politics'],
                // ═══════════ Unrelated Entertainment ═══════════
                'سینما' => ['w' => -15, 'c' => 'entertainment'],
                'فیلم' => ['w' => -15, 'c' => 'entertainment'],
                'سریال' => ['w' => -15, 'c' => 'entertainment'],
                'بازیگر' => ['w' => -15, 'c' => 'entertainment'],
                'خواننده' => ['w' => -15, 'c' => 'entertainment'],
                'کنسرت' => ['w' => -15, 'c' => 'entertainment'],
                'اسکار' => ['w' => -20, 'c' => 'entertainment'],
                'جشنواره فیلم' => ['w' => -15, 'c' => 'entertainment'],
                'سلبریتی' => ['w' => -15, 'c' => 'entertainment'],
                'شایعه' => ['w' => -15, 'c' => 'entertainment'],
                'رسوایی' => ['w' => -20, 'c' => 'entertainment'],
                'طلاق' => ['w' => -15, 'c' => 'entertainment'],
                'ازدواج' => ['w' => -10, 'c' => 'entertainment'],
                // ═══════════ Technology ═══════════
                'گوشی' => ['w' => -10, 'c' => 'tech'],
                'موبایل' => ['w' => -10, 'c' => 'tech'],
                'آیفون' => ['w' => -15, 'c' => 'tech'],
                'سامسونگ' => ['w' => -15, 'c' => 'tech'],
                'اپل' => ['w' => -10, 'c' => 'tech'],
                'هوش مصنوعی' => ['w' => -10, 'c' => 'tech'],
                'بلاکچین' => ['w' => -15, 'c' => 'tech'],
                'کریپتو' => ['w' => -15, 'c' => 'tech'],
                'بیت‌کوین' => ['w' => -20, 'c' => 'tech'],
                'اتریوم' => ['w' => -15, 'c' => 'tech'],
                // ═══════════ ⭐ English - Negative ═══════════
                'war' => ['w' => -30, 'c' => 'en_neg'],
                'attack' => ['w' => -30, 'c' => 'en_neg'],
                'bombing' => ['w' => -30, 'c' => 'en_neg'],
                'terrorist' => ['w' => -30, 'c' => 'en_neg'],
                'terrorism' => ['w' => -30, 'c' => 'en_neg'],
                'killed' => ['w' => -25, 'c' => 'en_neg'],
                'dead' => ['w' => -20, 'c' => 'en_neg'],
                'injured' => ['w' => -20, 'c' => 'en_neg'],
                'crash' => ['w' => -25, 'c' => 'en_neg'],
                'plane crash' => ['w' => -30, 'c' => 'en_neg'],
                'earthquake' => ['w' => -25, 'c' => 'en_neg'],
                'tsunami' => ['w' => -30, 'c' => 'en_neg'],
                'hurricane' => ['w' => -25, 'c' => 'en_neg'],
                'tornado' => ['w' => -25, 'c' => 'en_neg'],
                'wildfire' => ['w' => -20, 'c' => 'en_neg'],
                'flood' => ['w' => -20, 'c' => 'en_neg'],
                'drought' => ['w' => -15, 'c' => 'en_neg'],
                'protest' => ['w' => -15, 'c' => 'en_neg'],
                'riot' => ['w' => -25, 'c' => 'en_neg'],
                'strikes' => ['w' => -15, 'c' => 'en_neg'],
                'scam' => ['w' => -20, 'c' => 'en_neg'],
                'fraud' => ['w' => -20, 'c' => 'en_neg'],
                'robbery' => ['w' => -25, 'c' => 'en_neg'],
                'murder' => ['w' => -30, 'c' => 'en_neg'],
                'arrest' => ['w' => -20, 'c' => 'en_neg'],
                'prison' => ['w' => -20, 'c' => 'en_neg'],
                'lawsuit' => ['w' => -15, 'c' => 'en_neg'],
                'recession' => ['w' => -20, 'c' => 'en_neg'],
                'inflation' => ['w' => -15, 'c' => 'en_neg'],
                'bankruptcy' => ['w' => -25, 'c' => 'en_neg'],
                'pollution' => ['w' => -15, 'c' => 'en_neg'],
                'climate crisis' => ['w' => -15, 'c' => 'en_neg'],
                'outbreak' => ['w' => -20, 'c' => 'en_neg'],
                'pandemic' => ['w' => -25, 'c' => 'en_neg'],
                'epidemic' => ['w' => -20, 'c' => 'en_neg'],
                'quarantine' => ['w' => -15, 'c' => 'en_neg'],
                'lockdown' => ['w' => -20, 'c' => 'en_neg'],
                'travel ban' => ['w' => -25, 'c' => 'en_neg'],
                'travel warning' => ['w' => -25, 'c' => 'en_neg'],
                'travel advisory' => ['w' => -20, 'c' => 'en_neg'],
                'cancellation' => ['w' => -15, 'c' => 'en_neg'],
                'flight cancellation' => ['w' => -25, 'c' => 'en_neg'],
                'bankrupt airline' => ['w' => -25, 'c' => 'en_neg'],
                'hotel closure' => ['w' => -20, 'c' => 'en_neg'],
                'overbooked' => ['w' => -15, 'c' => 'en_neg'],
                'bed bugs' => ['w' => -25, 'c' => 'en_neg'],
                'food poisoning' => ['w' => -25, 'c' => 'en_neg'],
                'lost luggage' => ['w' => -20, 'c' => 'en_neg'],
                'pickpocket' => ['w' => -20, 'c' => 'en_neg'],
                'mugging' => ['w' => -25, 'c' => 'en_neg'],
            ],
        ];

        $rows = [];

        foreach ($w as $type => $list) {
            foreach ($list as $kw => $meta) {
                $rows[] = [
                    'keyword'   => $kw,
                    'type'      => $type,
                    'weight'    => $meta['w'],
                    'category'  => $meta['c'],
                    'is_active' => 1,
                ];
            }
        }

        return $rows;
    }

    public static function get_keywords(): array {
        $out = ['positive' => [], 'negative' => []];

        foreach (self::get_default_keyword_rows() as $r) {
            $out[$r['type']][] = $r['keyword'];
        }

        // If database is populated, return the actual database list
        global $wpdb;

        $table = $wpdb->prefix . 'ns_news_keywords';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") === $table) {
            $rows = $wpdb->get_results("SELECT keyword, type FROM {$table} WHERE is_active = 1", ARRAY_A);

            if (!empty($rows)) {
                $out = ['positive' => [], 'negative' => []];
                foreach ($rows as $r) $out[$r['type']][] = $r['keyword'];
            }
        }

        return $out;
    }

    public static function save_keywords(array $positive, array $negative): void {
        global $wpdb;

        $table = $wpdb->prefix . 'ns_news_keywords';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            \NextSafar\Database\NewsTables::create_keywords_table();
        }

        $wpdb->query("TRUNCATE TABLE {$table}");

        $canon = [];

        foreach (self::get_default_keyword_rows() as $r) $canon[$r['type']][$r['keyword']] = $r;

        foreach (array_filter($positive) as $kw) {
            $kw = sanitize_text_field(trim($kw));
            $c  = $canon['positive'][$kw] ?? null;

            $wpdb->insert($table, [
                'keyword' => $kw, 'type' => 'positive',
                'weight' => $c['weight'] ?? 10, 'category' => $c['category'] ?? 'general', 'is_active' => 1,
            ]);
        }

        foreach (array_filter($negative) as $kw) {
            $kw = sanitize_text_field(trim($kw));
            $c  = $canon['negative'][$kw] ?? null;

            $wpdb->insert($table, [
                'keyword' => $kw, 'type' => 'negative',
                'weight' => $c['weight'] ?? -20, 'category' => $c['category'] ?? 'general', 'is_active' => 1,
            ]);
        }
    }

    public static function add_keyword(string $kw, string $type, int $weight = 10, string $cat = 'general'): bool {
        global $wpdb;

        $table = $wpdb->prefix . 'ns_news_keywords';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
            \NextSafar\Database\NewsTables::create_keywords_table();
        }

        return $wpdb->insert($table, [
            'keyword' => sanitize_text_field($kw), 'type' => $type,
            'weight' => $weight, 'category' => $cat, 'is_active' => 1,
        ]) !== false;
    }

    public static function delete_keyword(int $id): bool {
        global $wpdb;

        return $wpdb->delete($wpdb->prefix . 'ns_news_keywords', ['id' => $id], ['%d']) !== false;
    }

    public static function get_all_keywords(): array {
        global $wpdb;

        $table = $wpdb->prefix . 'ns_news_keywords';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return [];

        return $wpdb->get_results("SELECT * FROM {$table} ORDER BY type, category, keyword", ARRAY_A);
    }

    public static function get_filter_history(int $limit = 20): array {
        global $wpdb;

        $table = $wpdb->prefix . 'ns_news_filter_log';

        if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return [];

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT news_title, source_name, score, decision, reason,
                    positive_matches, negative_matches, ai_checked, created_at
             FROM {$table} ORDER BY created_at DESC LIMIT %d", $limit), ARRAY_A);

        if (empty($results)) return [];

        foreach ($results as &$row) {
            $row['positive_matches'] = maybe_unserialize($row['positive_matches']) ?: [];
            $row['negative_matches'] = maybe_unserialize($row['negative_matches']) ?: [];
            $row['ai_checked'] = (bool) $row['ai_checked'];
            $row['score'] = (int) $row['score'];
        }

        return $results;
    }
}