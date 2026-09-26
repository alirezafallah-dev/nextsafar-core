<?php

namespace NextSafar\Admin;

/**
 * Settings and Live Search Engine (Flight / Hotel / Tour)
 *
 * Features:
 * - Supports SerpApi (primary) and SearchApi (fallback)
 * - Jalali to Gregorian date conversion system
 * - USD to Toman exchange rate management
 * - IATA code to Persian city mapping
 */
class LiveSearch {
    /* ========================================================================
       Option Names
       ======================================================================== */
    const KEY_SERPAPI = 'nextsafar_live_serpapi_key';
    const KEY_SEARCHAPI = 'nextsafar_live_searchapi_key';
    const KEY_PARTOCRS = 'nextsafar_live_partocrs_key';
    const KEY_IRSA = 'nextsafar_live_irsa_key';
    const OPTION_RATE = 'nextsafar_live_usd_to_toman_rate';
    const OPTION_PROVIDER = 'nextsafar_live_provider';

    /* ========================================================================
       IATA Code → Persian City Mapping
       ======================================================================== */
    const CITY_MAP = [
        /* Iran */
        'IKA' => 'تهران', 'THR' => 'تهران', 'MHD' => 'مشهد', 'SYZ' => 'شیراز',
        'IFN' => 'اصفهان', 'TBZ' => 'تبریز', 'AWZ' => 'اهواز', 'OMH' => 'ارومیه',
        'KSH' => 'کرمانشاه', 'ZBR' => 'زاهدان', 'KER' => 'کرمان', 'PGU' => 'عسلویه',
        'RZR' => 'رامسر', 'NSH' => 'نوشهر', 'ADU' => 'اردبیل', 'HDM' => 'همدان',

        /* Turkey */
        'IST' => 'استانبول', 'SAW' => 'استانبول', 'ESB' => 'آنکارا', 'ADA' => 'آدانا',
        'AYT' => 'آنتالیا', 'BJV' => 'بدروم', 'TZX' => 'ترابزون', 'VAN' => 'وان',

        /* Region */
        'EVN' => 'ایروان', 'GYD' => 'باکو', 'TBS' => 'تفلیس', 'KUT' => 'کوتائیسی',
        'DXB' => 'دبی', 'AUH' => 'ابوظبی', 'SHJ' => 'شارجه', 'DOH' => 'دوحه',
        'KWI' => 'کویت', 'BAH' => 'منامه', 'RUH' => 'ریاض', 'JED' => 'جده',
        'AMM' => 'امان', 'BEY' => 'بیروت', 'MCT' => 'مسقط', 'SLL' => 'صلاله',
        'NJF' => 'نجف', 'BGW' => 'بغداد', 'BSR' => 'بصره', 'KBL' => 'کابل',

        /* Asia */
        'BKK' => 'بانکوک', 'HKT' => 'پوکت', 'KUL' => 'کوالالامپور', 'SIN' => 'سنگاپور',
        'DEL' => 'دهلی', 'BOM' => 'بمبئی', 'PEK' => 'پکن', 'PVG' => 'شانگهای',
        'HKG' => 'هنگ‌کنگ', 'TPE' => 'تایپه', 'NRT' => 'توکیو', 'HND' => 'توکیو',
        'ICN' => 'سئول',

        /* Europe */
        'LHR' => 'لندن', 'LGW' => 'لندن', 'CDG' => 'پاریس', 'FRA' => 'فرانکفورت',
        'MUC' => 'مونیخ', 'AMS' => 'آمستردام', 'VIE' => 'وین', 'ZRH' => 'زوریخ',
        'GVA' => 'ژنو', 'FCO' => 'رم', 'MXP' => 'میلان', 'MAD' => 'مادرید',
        'BCN' => 'بارسلون', 'LIS' => 'لیسبون', 'ATH' => 'آتن', 'DUB' => 'دوبلین',
        'CPH' => 'کپنهاگ', 'ARN' => 'استکهلم', 'OSL' => 'اسلو', 'HEL' => 'هلسینکی',
        'SVO' => 'مسکو', 'DME' => 'مسکو', 'LED' => 'سن‌پترزبورگ',

        /* Americas / Oceania / Africa */
        'JFK' => 'نیویورک', 'LAX' => 'لس‌آنجلس', 'YYZ' => 'تورنتو', 'YVR' => 'ونکوور',
        'SYD' => 'سیدنی', 'MEL' => 'ملبورن', 'AKL' => 'اوکلند',
        'CAI' => 'قاهره', 'JNB' => 'ژوهانسبورگ', 'CPT' => 'کیپ‌تاون',
        'NBO' => 'نایروبی', 'CMN' => 'کازابلانکا', 'TUN' => 'تونس',
    ];

    /* ========================================================================
       PUBLIC API
       ======================================================================== */
    public static function get_provider(): string {
        return (string) get_option(self::OPTION_PROVIDER, 'serpapi');
    }

    public static function get_active_key(): string {
        switch (self::get_provider()) {
            case 'serpapi': return (string) get_option(self::KEY_SERPAPI, '');
            case 'searchapi': return (string) get_option(self::KEY_SEARCHAPI, '');
            case 'partocrs': return (string) get_option(self::KEY_PARTOCRS, '');
            case 'irsa': return (string) get_option(self::KEY_IRSA, '');
            default: return '';
        }
    }

    /**
     * USD to Toman rate.
     * Priority: manual > exchange > fallback.
     */
    public static function get_usd_rate(): float {
        $manual = floatval(get_option(self::OPTION_RATE, 0));

        if ($manual > 0) return $manual;

        if (class_exists('NextSafar\\Admin\\Exchange')) {
            $rial = Exchange::get_rate('USD');

            if ($rial > 0) return $rial / 10; /* Rial → Toman */
        }

        return 105000.0; // Fallback.
    }

    /**
     * Convert IATA code to Persian city name.
     */
    public static function city_fa(string $code, string $fallback = ''): string {
        $code = strtoupper($code);

        if (isset(self::CITY_MAP[$code])) return self::CITY_MAP[$code];

        $wp = self::airport_city_from_wp($code);

        if ($wp !== '') return $wp;

        return $fallback;
    }

    /**
     * Flight search with fallback system.
     */
    public static function search_flights(array $args): array|\WP_Error {
        $provider = self::get_provider();

        // Try primary provider.
        $result = self::search_flights_with_provider($provider, $args);

        // If it failed and the primary provider was serpapi, fallback to searchapi.
        if (is_wp_error($result) && $provider === 'serpapi') {
            error_log('[NS LiveSearch] SerpApi failed, trying SearchApi fallback: ' . $result->get_error_message());

            $fallback_key = get_option(self::KEY_SEARCHAPI, '');

            if ($fallback_key !== '') {
                $result = self::searchapi_flights($args, $fallback_key);
            }
        }

        return $result;
    }

    private static function search_flights_with_provider(string $provider, array $args): array|\WP_Error {
        switch ($provider) {
            case 'serpapi':
                $key = get_option(self::KEY_SERPAPI, '');

                if ($key === '') return new \WP_Error('no_key', 'کلید SerpApi تنظیم نشده');

                return self::serpapi_flights($args, $key);

            case 'searchapi':
                $key = get_option(self::KEY_SEARCHAPI, '');

                if ($key === '') return new \WP_Error('no_key', 'کلید SearchApi تنظیم نشده');

                return self::searchapi_flights($args, $key);

            case 'partocrs':
                return new \WP_Error('provider_not_ready', 'ارائه‌دهنده Partocrs هنوز متصل نشده است');

            case 'irsa':
                return new \WP_Error('provider_not_ready', 'ارائه‌دهنده Irsa هنوز متصل نشده است');

            default:
                return new \WP_Error('unknown_provider', 'ارائه‌دهنده ناشناخته: ' . $provider);
        }
    }

    /**
     * Hotel search with fallback system.
     */
    public static function search_hotels(array $args): array|\WP_Error {
        $provider = self::get_provider();

        // Try primary provider.
        $result = self::search_hotels_with_provider($provider, $args);

        // If it failed and the primary provider was serpapi, fallback to searchapi.
        if (is_wp_error($result) && $provider === 'serpapi') {
            error_log('[NS LiveSearch] SerpApi failed, trying SearchApi fallback: ' . $result->get_error_message());

            $fallback_key = get_option(self::KEY_SEARCHAPI, '');

            if ($fallback_key !== '') {
                $result = self::searchapi_hotels($args, $fallback_key);
            }
        }

        return $result;
    }

    private static function search_hotels_with_provider(string $provider, array $args): array|\WP_Error {
        switch ($provider) {
            case 'serpapi':
                $key = get_option(self::KEY_SERPAPI, '');

                if ($key === '') return new \WP_Error('no_key', 'کلید SerpApi تنظیم نشده');

                return self::serpapi_hotels($args, $key);

            case 'searchapi':
                $key = get_option(self::KEY_SEARCHAPI, '');

                if ($key === '') return new \WP_Error('no_key', 'کلید SearchApi تنظیم نشده');

                return self::searchapi_hotels($args, $key);

            case 'partocrs':
                return new \WP_Error('provider_not_ready', 'ارائه‌دهنده Partocrs هنوز متصل نشده است');

            case 'irsa':
                return new \WP_Error('provider_not_ready', 'ارائه‌دهنده Irsa هنوز متصل نشده است');

            default:
                return new \WP_Error('unknown_provider', 'ارائه‌دهنده ناشناخته');
        }
    }

    /* ========================================================================
       SERPAPI — Google Flights (Final Correct Version)
       ======================================================================== */
    private static function serpapi_flights(array $a, string $key): array|\WP_Error {
        $cabin_map = [
            'economy' => 1,
            'premium_economy' => 2,
            'business' => 3,
            'first' => 4,
        ];

        $params = [
            'engine' => 'google_flights',
            'departure_id' => strtoupper($a['origin']),
            'arrival_id' => strtoupper($a['dest']),
            'outbound_date' => $a['date'],
            'travel_class' => $cabin_map[$a['cabin']] ?? 1,
            'adults' => (int)($a['adults'] ?? 1),
            'children' => (int)($a['children'] ?? 0),
            'currency' => 'USD',
            'api_key' => $key,
        ];

        if ($a['trip_type'] === 'round_trip' && !empty($a['return_date'])) {
            $params['type'] = 1;
            $params['return_date'] = $a['return_date'];
        } else {
            $params['type'] = 2;
        }

        $url = 'https://serpapi.com/search.json';
        $full_url = $url . '?' . http_build_query($params);

        error_log("[NS/SerpApi] URL: " . substr($full_url, 0, 200));

        $response = wp_remote_get($full_url, [
            'timeout' => 45,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            $err = json_decode($body, true);
            $msg = $err['error'] ?? "HTTP $code";

            error_log("[NS/SerpApi] ERROR ($code): " . substr($body, 0, 500));

            return new \WP_Error('api_error', "SerpApi: $msg");
        }

        $data = json_decode($body, true);

        if (!$data) {
            error_log("[NS/SerpApi] PARSE ERROR: " . substr($body, 0, 300));

            return new \WP_Error('parse_error', 'خطا در تجزیه پاسخ SerpApi');
        }

        // Precise debug log.
        $best_count = count($data['best_flights'] ?? []);
        $other_count = count($data['other_flights'] ?? []);

        error_log("[NS/SerpApi] SUCCESS: best=$best_count, other=$other_count");

        // Log first flight structure.
        if ($best_count > 0) {
            $first = $data['best_flights'][0];

            error_log("[NS/SerpApi] FIRST FLIGHT KEYS: " . implode(', ', array_keys($first)));

            if (isset($first['flights'][0])) {
                error_log("[NS/SerpApi] FIRST SEGMENT: " . substr(json_encode($first['flights'][0]), 0, 500));
            }
        }

        return self::normalize_serpapi_flights($data, $a);
    }

    private static function normalize_serpapi_flights(array $data, array $a): array {
        $all_flights = [];

        if (isset($data['best_flights']) && is_array($data['best_flights'])) {
            $all_flights = array_merge($all_flights, $data['best_flights']);
        }

        if (isset($data['other_flights']) && is_array($data['other_flights'])) {
            $all_flights = array_merge($all_flights, $data['other_flights']);
        }

        if (empty($all_flights)) {
            error_log('[NS/SerpApi] No flights in response');

            return [];
        }

        error_log('[NS/SerpApi] Processing ' . count($all_flights) . ' flights');

        $rate = self::get_usd_rate();
        $flights = [];

        foreach ($all_flights as $idx => $f) {
            $flights_segments = $f['flights'] ?? [];

            // Skip if segments are empty.
            if (empty($flights_segments)) {
                error_log("[NS/SerpApi] Flight #$idx has no segments, skipping");
                continue;
            }

            $first_seg = $flights_segments[0];
            $last_seg = end($flights_segments);

            $dep_airport = $first_seg['departure_airport'] ?? [];
            $arr_airport = $last_seg['arrival_airport'] ?? [];

            // Extract time - SerpApi may have different formats.
            $dep_time_raw = $dep_airport['time'] ?? '';
            $arr_time_raw = $arr_airport['time'] ?? '';

            // Try extracting time with several formats.
            $dep_hm = '00:00';
            $arr_hm = '00:00';

            // Format 1: "2026-11-20 08:30".
            if (preg_match('/(\d{1,2}:\d{2})/', $dep_time_raw, $m)) {
                $dep_hm = strlen($m[1]) === 4 ? '0' . $m[1] : $m[1];
            }

            if (preg_match('/(\d{1,2}:\d{2})/', $arr_time_raw, $m)) {
                $arr_hm = strlen($m[1]) === 4 ? '0' . $m[1] : $m[1];
            }

            // Flight duration.
            $duration_min = (int) ($f['total_duration'] ?? 0);

            // If duration is missing, calculate from time.
            if ($duration_min <= 0 && $dep_hm !== '00:00' && $arr_hm !== '00:00') {
                $dep_parts = explode(':', $dep_hm);
                $arr_parts = explode(':', $arr_hm);

                $dep_minutes = (int)$dep_parts[0] * 60 + (int)$dep_parts[1];
                $arr_minutes = (int)$arr_parts[0] * 60 + (int)$arr_parts[1];

                $duration_min = $arr_minutes - $dep_minutes;

                if ($duration_min < 0) $duration_min += 1440;
            }

            // Departure and arrival dates to detect next_day.
            $dep_date = $dep_airport['date'] ?? '';
            $arr_date = $arr_airport['date'] ?? '';

            $next_day = ($dep_date !== '' && $arr_date !== '') ? ($arr_date > $dep_date) : false;

            // Price.
            $price_usd = (float) ($f['price'] ?? 0);
            $price_toman = (int) round($price_usd * $rate);

            $flights[] = [
                'id' => $idx + 1,
                'flight_number' => $first_seg['flight_number'] ?? ('NS-' . ($idx + 1)),
                'airline' => $first_seg['airline'] ?? 'نامشخص',
                'airline_logo' => $first_seg['airline_logo'] ?? ($f['airline_logo'] ?? null),
                'origin_code' => strtoupper($dep_airport['id'] ?? $a['origin']),
                'origin_city' => self::city_fa($dep_airport['id'] ?? '', $dep_airport['name'] ?? ''),
                'dest_code' => strtoupper($arr_airport['id'] ?? $a['dest']),
                'dest_city' => self::city_fa($arr_airport['id'] ?? '', $arr_airport['name'] ?? ''),
                'date' => $a['date'],
                'depart_time' => $dep_hm,
                'arrive_time' => $arr_hm,
                'next_day' => $next_day,
                'duration_min' => $duration_min,
                'stops' => max(0, count($flights_segments) - 1),
                'price' => $price_toman,
                'currency' => 'TOMAN',
                'cabin' => strtolower($first_seg['travel_class'] ?? 'Economy'),
                'aircraft' => $first_seg['airplane'] ?? '',
            ];
        }

        // Sort by departure time.
        usort($flights, function($x, $y) {
            return strcmp($x['depart_time'], $y['depart_time']);
        });

        error_log('[NS/SerpApi] Normalized ' . count($flights) . ' flights');

        return $flights;
    }

    /* ========================================================================
       SERPAPI — Google Hotels
       ======================================================================== */
    private static function serpapi_hotels(array $a, string $key): array|\WP_Error {
        $params = [
            'engine' => 'google_hotels',
            'q' => ($a['q_en'] ?? '') !== '' ? $a['q_en'] : $a['q'],
            'check_in_date' => $a['check_in'],
            'check_out_date' => $a['check_out'],
            'adults' => $a['adults'],
            'currency' => 'USD',
            'api_key' => $key,
        ];

        $url = 'https://serpapi.com/search.json';

        error_log("[NS LiveSearch/SerpApi/hotels] q={$params['q']} {$a['check_in']} → {$a['check_out']}");

        $response = wp_remote_get($url . '?' . http_build_query($params), [
            'timeout' => 60,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            $err = json_decode($body, true);

            error_log("[NS LiveSearch/SerpApi/hotels] API error ($code): " . substr($body, 0, 300));

            return new \WP_Error('api_error', 'SerpApi: ' . ($err['error'] ?? "HTTP $code"));
        }

        $data = json_decode($body, true);

        if (!$data) return new \WP_Error('parse_error', 'خطا در تجزیه پاسخ');

        $props = $data['properties'] ?? [];

        error_log('[NS LiveSearch/SerpApi/hotels] properties count: ' . count($props));

        // Debug: log first hotel structure for troubleshooting.
        if (!empty($props)) {
            error_log('[NS LiveSearch/SerpApi/hotels] first hotel keys: ' . implode(', ', array_keys($props[0])));
            error_log('[NS LiveSearch/SerpApi/hotels] first hotel sample: ' . substr(json_encode($props[0]), 0, 500));
        }

        if (empty($props)) return [];

        return self::normalize_serpapi_hotels($props, $a);
    }

    /**
     * Normalize SerpApi hotel results (final version - correct structure).
     */
    private static function normalize_serpapi_hotels(array $props, array $a): array {
        $rate = self::get_usd_rate();
        $out = [];

        foreach ($props as $idx => $p) {
            $name = $p['name'] ?? '';

            if ($name === '') continue;

            $link = $p['link'] ?? '';
            $pid = '';

            if (preg_match('/[?&]fid=([^&]+)/', $link, $m)) $pid = $m[1];

            if ($pid === '') $pid = 'u:' . md5($link . $name);

            // google_property_token - for getting exact details.
            $google_property_token = $p['property_token'] ?? '';

            // Extract image - SerpApi uses images array structure.
            $image = null;

            if (isset($p['images']) && is_array($p['images']) && !empty($p['images'])) {
                $first_img = $p['images'][0];

                if (is_array($first_img)) {
                    // Array structure of objects.
                    $image = $first_img['original_image']
                        ?? $first_img['link']
                        ?? $first_img['thumbnail']
                        ?? null;
                } elseif (is_string($first_img)) {
                    // Array of URLs.
                    $image = $first_img;
                }
            }

            // Fallback to thumbnail.
            if ($image === null && isset($p['thumbnail'])) {
                $image = $p['thumbnail'];
            }

            /* ====================================================================
               Extract price - correct SerpApi structure:
               rate_per_night.extracted_lowest (not extracted_lowest_price!)
               ==================================================================== */
            $price_usd = 0.0;

            // Priority 1: rate_per_night.extracted_lowest (correct structure).
            if (isset($p['rate_per_night']['extracted_lowest'])) {
                $price_usd = (float) $p['rate_per_night']['extracted_lowest'];
            }
            // Priority 2: rate_per_night.extracted_before_taxes_fees.
            elseif (isset($p['rate_per_night']['extracted_before_taxes_fees'])) {
                $price_usd = (float) $p['rate_per_night']['extracted_before_taxes_fees'];
            }
            // Priority 3: rate_per_night.lowest (string "$231").
            elseif (isset($p['rate_per_night']['lowest'])) {
                $raw = (string) $p['rate_per_night']['lowest'];

                if (preg_match('/[\d,]+(?:\.\d+)?/', $raw, $m)) {
                    $price_usd = (float) str_replace(',', '', $m[0]);
                }
            }
            // Priority 4: total_rate.extracted_lowest (divide by number of nights).
            elseif (isset($p['total_rate']['extracted_lowest'])) {
                // Calculate number of nights.
                $nights = 1;

                if (isset($a['check_in']) && isset($a['check_out'])) {
                    try {
                        $d1 = new \DateTime($a['check_in']);
                        $d2 = new \DateTime($a['check_out']);
                        $nights = max(1, $d1->diff($d2)->days);
                    } catch (\Exception $e) {}
                }

                $price_usd = (float) $p['total_rate']['extracted_lowest'] / $nights;
            }
            // Priority 5: lowest_price (fallback).
            elseif (isset($p['lowest_price'])) {
                if (is_numeric($p['lowest_price'])) {
                    $price_usd = (float) $p['lowest_price'];
                } elseif (preg_match('/[\d,]+(?:\.\d+)?/', (string) $p['lowest_price'], $m)) {
                    $price_usd = (float) str_replace(',', '', $m[0]);
                }
            }
            // Priority 6: price (last fallback).
            elseif (isset($p['price'])) {
                if (is_numeric($p['price'])) {
                    $price_usd = (float) $p['price'];
                } elseif (preg_match('/[\d,]+(?:\.\d+)?/', (string) $p['price'], $m)) {
                    $price_usd = (float) str_replace(',', '', $m[0]);
                }
            }

            // Extract geographic coordinates.
            $lat = null;
            $lng = null;

            if (isset($p['gps_coordinates'])) {
                $lat = $p['gps_coordinates']['latitude'] ?? null;
                $lng = $p['gps_coordinates']['longitude'] ?? null;
            } elseif (isset($p['latitude'])) {
                $lat = $p['latitude'];
                $lng = $p['longitude'] ?? null;
            }

            // Extract amenities.
            $amenities = [];

            if (isset($p['amenities']) && is_array($p['amenities'])) {
                foreach ($p['amenities'] as $am) {
                    if (is_string($am)) {
                        $amenities[] = strtolower(str_replace([' ', '-', '($)'], ['_', '_', ''], $am));
                    } elseif (isset($am['name'])) {
                        $amenities[] = strtolower(str_replace(' ', '_', $am['name']));
                    }
                }
            } elseif (isset($p['hotel_amenities']) && is_array($p['hotel_amenities'])) {
                foreach ($p['hotel_amenities'] as $am) {
                    if (is_string($am)) {
                        $amenities[] = strtolower(str_replace(' ', '_', $am));
                    }
                }
            }

            // Extract stars - SerpApi uses hotel_class.
            $stars = 0;

            if (isset($p['extracted_hotel_class']) && is_numeric($p['extracted_hotel_class'])) {
                $stars = (int) $p['extracted_hotel_class'];
            } elseif (isset($p['hotel_class']) && is_numeric($p['hotel_class'])) {
                $stars = (int) $p['hotel_class'];
            } elseif (isset($p['stars']) && is_numeric($p['stars'])) {
                $stars = (int) $p['stars'];
            } elseif (isset($p['class']) && is_numeric($p['class'])) {
                $stars = (int) $p['class'];
            }

            // Rating - SerpApi uses overall_rating.
            $rating = 0.0;

            if (isset($p['overall_rating']) && is_numeric($p['overall_rating'])) {
                $rating = (float) $p['overall_rating'];
            } elseif (isset($p['rating']) && is_numeric($p['rating'])) {
                $rating = (float) $p['rating'];
            }

            // Log price for the first hotel.
            if ($idx === 0) {
                error_log("[NS/SerpApi] ✅ Price extracted: \${$price_usd} (rate_per_night.extracted_lowest)");
            }

            $out[] = [
                'property_id' => $pid,
                'google_property_token' => $p['property_token'] ?? '',
                'name' => $name,
                'link' => $link,
                'image' => $image,
                'stars' => $stars,
                'rating' => $rating,
                'reviews' => (int) ($p['reviews'] ?? $p['reviews_count'] ?? 0),
                'address' => $p['address'] ?? $p['short_address'] ?? '',
                'lat' => $lat ? (float) $lat : null,
                'lng' => $lng ? (float) $lng : null,
                'amenities' => $amenities,
                'price_usd' => $price_usd,
                'per_night_toman' => (int) round($price_usd * $rate),
            ];
        }

        return $out;
    }

    /* ========================================================================
       Specific Hotel Details (For Online Hotel Page)
       ======================================================================== */
    public static function hotel_details(array $a): array|\WP_Error {
        $key = self::get_active_key();

        if ($key === '') return new \WP_Error('no_key', 'کلید جستجو تنظیم نشده');

        if (self::get_provider() !== 'serpapi') {
            return new \WP_Error('not_supported', 'جزئیات هتل فقط با SerpApi پشتیبانی می‌شود');
        }

        return self::serpapi_hotel_details($a, $key);
    }

    private static function serpapi_hotel_details(array $a, string $key): array|\WP_Error {
        $params = [
            'engine'          => 'google_hotels',
            'q'               => $a['hotel_name'] ?? 'Hotels', // q is required.
            'property_token'  => $a['token'],
            'check_in_date'   => $a['check_in'],
            'check_out_date'  => $a['check_out'],
            'adults'          => $a['adults'] ?? 2,
            'currency'        => 'USD',
            'hl'              => 'en',
            'gl'              => 'us',
            'api_key'         => $key,
        ];

        $url = 'https://serpapi.com/search.json?' . http_build_query($params);

        error_log('[NS/SerpApi/Details] token=' . substr($a['token'], 0, 24) . '...');

        $response = wp_remote_get($url, ['timeout' => 60, 'headers' => ['Accept' => 'application/json']]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            $err = json_decode($body, true);

            error_log('[NS/SerpApi/Details] ERROR (' . $code . '): ' . substr($body, 0, 300));

            return new \WP_Error('api_error', 'SerpApi: ' . ($err['error'] ?? "HTTP $code"));
        }

        $d = json_decode($body, true);

        if (!$d) return new \WP_Error('parse_error', 'خطا در تجزیه پاسخ');

        if (isset($d['error'])) {
            error_log('[NS/SerpApi/Details] API Error: ' . $d['error']);

            return new \WP_Error('api_error', 'SerpApi: ' . $d['error']);
        }

        // Log structure for debugging.
        error_log('[NS/SerpApi/Details] Response keys: ' . implode(', ', array_keys($d)));

        $rate = self::get_usd_rate();

        /* Name and type. */
        $name = $d['hotel_name'] ?? $d['name'] ?? '';
        $type = $d['type'] ?? 'hotel';

        /* Address and coordinates. */
        $address = '';
        $gps = null;

        if (isset($d['location']) && is_array($d['location'])) {
            $address = $d['location']['address'] ?? '';
            $gps = $d['location']['gps_coordinates'] ?? null;
        } elseif (isset($d['address']) && is_string($d['address'])) {
            $address = $d['address'];
            $gps = $d['gps_coordinates'] ?? null;
        }

        /* Images (with protection). */
        $images = [];
        $raw_images = $d['images'] ?? [];

        if (is_array($raw_images)) {
            foreach ($raw_images as $img) {
                if (is_string($img)) $images[] = $img;
                elseif (is_array($img)) $images[] = $img['original_image'] ?? $img['thumbnail'] ?? '';
            }
        }

        $images = array_values(array_filter($images));

        /* Rooms + price (with protection). */
        $rooms = [];
        $raw_rooms = $d['rooms'] ?? [];

        if (is_array($raw_rooms)) {
            foreach ($raw_rooms as $r) {
                if (!is_array($r)) continue;

                $price_usd = 0.0;

                if (isset($r['rate_per_night']['extracted_lowest'])) {
                    $price_usd = (float) $r['rate_per_night']['extracted_lowest'];
                } elseif (isset($r['price']['extracted'])) {
                    $price_usd = (float) $r['price']['extracted'];
                }

                $room_img = null;

                if (!empty($r['images']) && is_array($r['images'])) {
                    $ri = $r['images'][0];
                    $room_img = is_array($ri) ? ($ri['thumbnail'] ?? null) : $ri;
                }

                $rooms[] = [
                    'name'               => $r['name'] ?? '',
                    'description'        => $r['description'] ?? '',
                    'image'              => $room_img,
                    'beds'               => $r['beds'] ?? '',
                    'max_occupancy'      => $r['max_occupancy'] ?? null,
                    'price_usd'          => $price_usd,
                    'per_night_toman'    => (int) round($price_usd * $rate),
                    'free_cancellation'  => (bool) ($r['free_cancellation'] ?? false),
                    'breakfast_included' => (bool) ($r['breakfast_included'] ?? false),
                ];
            }
        }

        /* Reviews (with full protection). */
        $reviews = [];
        $reviews_count = 0;
        $raw_reviews = $d['reviews'] ?? [];

        if (is_array($raw_reviews)) {
            // Array of reviews.
            foreach (array_slice($raw_reviews, 0, 6) as $rv) {
                if (!is_array($rv)) continue;

                $reviews[] = [
                    'author'  => $rv['author'] ?? '',
                    'rating'  => (float) ($rv['rating'] ?? 0),
                    'date'    => $rv['date'] ?? '',
                    'snippet' => $rv['snippet'] ?? '',
                ];
            }

            $reviews_count = $d['reviews_count'] ?? count($raw_reviews);
        } elseif (is_numeric($raw_reviews)) {
            // If reviews is a number (review count).
            $reviews_count = (int) $raw_reviews;
        }

        // Check alternative review structures.
        if (empty($reviews)) {
            $review_sources = ['hotel_reviews', 'user_reviews', 'guest_reviews'];

            foreach ($review_sources as $src) {
                if (isset($d[$src]) && is_array($d[$src]) && !empty($d[$src])) {
                    foreach (array_slice($d[$src], 0, 6) as $rv) {
                        if (!is_array($rv)) continue;

                        $reviews[] = [
                            'author'  => $rv['author'] ?? ($rv['user'] ?? ''),
                            'rating'  => (float) ($rv['rating'] ?? 0),
                            'date'    => $rv['date'] ?? '',
                            'snippet' => $rv['snippet'] ?? ($rv['review'] ?? ''),
                        ];
                    }

                    break;
                }
            }
        }

        /* Amenities (with protection). */
        $amenities = [];
        $raw_amenities = $d['amenities'] ?? [];

        if (is_array($raw_amenities)) {
            foreach ($raw_amenities as $am) {
                if (is_string($am)) $amenities[] = $am;
                elseif (is_array($am) && isset($am['name'])) $amenities[] = $am['name'];
            }
        }

        /* Nearby places (with protection). */
        $nearby = [];
        $raw_nearby = $d['nearby_places'] ?? ($d['nearby'] ?? []);

        if (is_array($raw_nearby)) {
            $nearby = array_slice($raw_nearby, 0, 8);
        }

        /* Per-night price (several possible structures). */
        $price_usd = 0.0;

        if (isset($d['rate_per_night']['extracted_lowest'])) {
            $price_usd = (float) $d['rate_per_night']['extracted_lowest'];
        } elseif (isset($d['lowest_price'])) {
            if (is_numeric($d['lowest_price'])) {
                $price_usd = (float) $d['lowest_price'];
            } elseif (preg_match('/[\d,]+(?:\.\d+)?/', (string) $d['lowest_price'], $m)) {
                $price_usd = (float) str_replace(',', '', $m[0]);
            }
        } elseif (!empty($rooms)) {
            // Use room prices.
            $prices = array_filter(array_column($rooms, 'price_usd'));

            if (!empty($prices)) $price_usd = min($prices);
        }

        return [
            'name'            => $name,
            'type'            => $type,
            'address'         => $address,
            'rating'          => (float) ($d['overall_rating'] ?? $d['rating'] ?? 0),
            'reviews_count'   => (int) $reviews_count,
            'stars'           => (int) ($d['extracted_hotel_class'] ?? $d['hotel_class'] ?? 0),
            'images'          => array_slice($images, 0, 12),
            'rooms'           => $rooms,
            'reviews'         => $reviews,
            'amenities'       => $amenities,
            'gps'             => $gps,
            'check_in_time'   => $d['check_in_time'] ?? null,
            'check_out_time'  => $d['check_out_time'] ?? null,
            'nearby_places'   => $nearby,
            'price_usd'       => $price_usd,
            'per_night_toman' => (int) round($price_usd * $rate),
        ];
    }

    public static function get_hotel_property_details(string $property_token, string $check_in, string $check_out, int $adults = 2, string $key = ''): array|\WP_Error {
        if ($property_token === '') {
            return new \WP_Error('no_token', 'Property token الزامی است');
        }

        if ($key === '') {
            $key = self::get_active_key();
        }

        $params = [
            // New engine.
            'engine' => 'google_hotels',
            'property_token' => $property_token,
            'check_in_date' => $check_in,
            'check_out_date' => $check_out,
            'adults' => $adults,
            'currency' => 'USD',
            'hl' => 'en',
            'gl' => 'us',
            'api_key' => $key,
        ];

        $url = 'https://serpapi.com/search.json?' . http_build_query($params);

        error_log("[NS LiveSearch/SerpApi/PropertyDetails] Token: " . substr($property_token, 0, 30) . "...");

        $response = wp_remote_get($url, [
            'timeout' => 45,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            $err = json_decode($body, true);

            return new \WP_Error('api_error', 'SerpApi Property Details: ' . ($err['error'] ?? "HTTP $code"));
        }

        $data = json_decode($body, true);

        if (!$data) return new \WP_Error('parse_error', 'خطا در تجزیه پاسخ Property Details');

        // Extract price from response structure.
        $rate = self::get_usd_rate();
        $price_usd = 0.0;

        // New structure: rate_per_night.extracted_lowest.
        if (isset($data['rate_per_night']['extracted_lowest'])) {
            $price_usd = (float) $data['rate_per_night']['extracted_lowest'];
        } elseif (isset($data['prices'])) {
            $first_price = is_array($data['prices']) ? ($data['prices'][0] ?? null) : $data['prices'];

            if (isset($first_price['rate_per_night']['extracted_lowest'])) {
                $price_usd = (float) $first_price['rate_per_night']['extracted_lowest'];
            }
        }

        if ($price_usd === 0.0 && isset($data['lowest_price'])) {
            if (is_numeric($data['lowest_price'])) {
                $price_usd = (float) $data['lowest_price'];
            } elseif (preg_match('/[\d,]+(?:\.\d+)?/', (string) $data['lowest_price'], $m)) {
                $price_usd = (float) str_replace(',', '', $m[0]);
            }
        }

        return [
            'price_usd' => $price_usd,
            'per_night_toman' => (int) round($price_usd * $rate),
            'rooms' => $data['rooms'] ?? [],
            'images' => $data['images'] ?? $data['photos'] ?? [],
            'reviews' => $data['reviews'] ?? [],
            'raw_data' => $data,
        ];
    }

    /* ========================================================================
       SEARCHAPI — Google Flights (Fallback)
       ======================================================================== */
    private static function searchapi_flights(array $a, string $key): array|\WP_Error {
        $cabin_map = [
            'economy' => 'economy',
            'premium_economy' => 'premium_economy',
            'business' => 'business',
            'first' => 'first_class',
        ];

        $params = [
            'engine' => 'google_flights',
            'flight_type' => $a['trip_type'],
            'departure_id' => $a['origin'],
            'arrival_id' => $a['dest'],
            'outbound_date' => $a['date'],
            'travel_class' => $cabin_map[$a['cabin']] ?? 'economy',
            'adults' => $a['adults'],
            'children' => $a['children'] ?? 0,
            'currency' => 'USD',
            'api_key' => $key,
        ];

        if ($a['trip_type'] === 'round_trip' && !empty($a['return_date'])) {
            $params['return_date'] = $a['return_date'];
        }

        $url = 'https://www.searchapi.io/api/v1/search';

        error_log("[NS LiveSearch/SearchApi] {$a['origin']} → {$a['dest']} @ {$a['date']}");

        $response = wp_remote_get($url . '?' . http_build_query($params), [
            'timeout' => 45,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            $err = json_decode($body, true);
            $msg = $err['error'] ?? "HTTP $code";

            error_log("[NS LiveSearch/SearchApi] API error ($code): " . substr($body, 0, 300));

            return new \WP_Error('api_error', "SearchApi: $msg");
        }

        $data = json_decode($body, true);

        if (!$data) return new \WP_Error('parse_error', 'خطا در تجزیه پاسخ SearchApi');

        return self::normalize_searchapi_flights($data, $a);
    }

    private static function normalize_searchapi_flights(array $data, array $a): array {
        $all = array_merge($data['best_flights'] ?? [], $data['other_flights'] ?? []);
        $rate = self::get_usd_rate();
        $flights = [];

        foreach ($all as $idx => $f) {
            $segments = $f['flights'] ?? [];

            if (empty($segments)) continue;

            $first = $segments[0];
            $last = end($segments);

            $dep = $first['departure_airport'] ?? [];
            $arr = $last['arrival_airport'] ?? [];

            $dep_time = $dep['time'] ?? '';
            $arr_time = $arr['time'] ?? '';

            $dep_hm = preg_match('/(\d{2}:\d{2})/', $dep_time, $m) ? $m[1] : '00:00';
            $arr_hm = preg_match('/(\d{2}:\d{2})/', $arr_time, $m) ? $m[1] : '00:00';

            $duration_min = (int) ($f['total_duration'] ?? 0);

            if ($duration_min <= 0 && $dep_time && $arr_time) {
                $ts1 = strtotime($dep_time);
                $ts2 = strtotime($arr_time);

                if ($ts1 && $ts2) {
                    $duration_min = (int) (($ts2 - $ts1) / 60);

                    if ($duration_min < 0) $duration_min += 1440;
                }
            }

            $dep_date = $dep['date'] ?? '';
            $arr_date = $arr['date'] ?? '';

            $next_day = ($dep_date !== '' && $arr_date !== '') ? ($arr_date > $dep_date) : false;

            $flights[] = [
                'id' => $idx + 1,
                'flight_number' => $first['flight_number'] ?? ('NS-' . ($idx + 1)),
                'airline' => $first['airline'] ?? 'نامشخص',
                'airline_logo' => $first['airline_logo'] ?? ($f['airline_logo'] ?? null),
                'origin_code' => strtoupper($dep['id'] ?? $a['origin']),
                'origin_city' => self::city_fa($dep['id'] ?? '', $dep['name'] ?? ''),
                'dest_code' => strtoupper($arr['id'] ?? $a['dest']),
                'dest_city' => self::city_fa($arr['id'] ?? '', $arr['name'] ?? ''),
                'date' => $a['date'],
                'depart_time' => $dep_hm,
                'arrive_time' => $arr_hm,
                'next_day' => $next_day,
                'duration_min' => $duration_min,
                'stops' => max(0, count($segments) - 1),
                'price' => (int) round((float) ($f['price'] ?? 0) * $rate),
                'currency' => 'TOMAN',
                'cabin' => strtolower($first['travel_class'] ?? 'Economy'),
                'aircraft' => $first['airplane'] ?? '',
            ];
        }

        usort($flights, fn($x, $y) => strcmp($x['depart_time'], $y['depart_time']));

        return $flights;
    }

    /* ========================================================================
       SEARCHAPI — Google Hotels (Fallback)
       ======================================================================== */
    private static function searchapi_hotels(array $a, string $key): array|\WP_Error {
        $params = [
            'engine' => 'google_hotels',
            'q' => ($a['q_en'] ?? '') !== '' ? $a['q_en'] : $a['q'],
            'check_in' => $a['check_in'],
            'check_out' => $a['check_out'],
            'adults' => $a['adults'],
            'children' => $a['children'] ?? 0,
            'currency' => 'USD',
            'api_key' => $key,
        ];

        $url = 'https://www.searchapi.io/api/v1/search';

        error_log("[NS LiveSearch/SearchApi/hotels] q={$params['q']} {$a['check_in']} → {$a['check_out']}");

        $response = wp_remote_get($url . '?' . http_build_query($params), [
            'timeout' => 60,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            $err = json_decode($body, true);

            error_log("[NS LiveSearch/SearchApi/hotels] API error ($code): " . substr($body, 0, 300));

            return new \WP_Error('api_error', 'SearchApi: ' . ($err['error'] ?? "HTTP $code"));
        }

        $data = json_decode($body, true);

        if (!$data) return new \WP_Error('parse_error', 'خطا در تجزیه پاسخ');

        $props = $data['properties'] ?? $data['hotels'] ?? [];

        if (empty($props)) return [];

        return self::normalize_searchapi_hotels($props, $a);
    }

    private static function normalize_searchapi_hotels(array $props, array $a): array {
        $rate = self::get_usd_rate();
        $out = [];

        foreach ($props as $p) {
            $name = $p['name'] ?? '';
            $link = $p['link'] ?? '';

            if ($name === '' || $link === '') continue;

            $pid = '';

            if (preg_match('/[?&]fid=([^&]+)/', $link, $m)) $pid = $m[1];

            if ($pid === '') $pid = 'u:' . md5($link);

            $price_raw = $p['price'] ?? ($p['price_per_night'] ?? '');
            $price_usd = 0.0;

            if (is_numeric($price_raw)) {
                $price_usd = (float) $price_raw;
            } elseif (preg_match('/([\d,]+(?:\.\d+)?)/', (string) $price_raw, $m)) {
                $price_usd = (float) str_replace(',', '', $m[1]);
            }

            $lat = isset($p['latitude']) ? (float) $p['latitude'] : (isset($p['lat']) ? (float) $p['lat'] : null);
            $lng = isset($p['longitude']) ? (float) $p['longitude'] : (isset($p['lng']) ? (float) $p['lng'] : null);

            $out[] = [
                'property_id' => $pid,
                'name' => $name,
                'link' => $link,
                'image' => $p['thumbnail'] ?? ($p['image'] ?? null),
                'rating' => (float) ($p['rating'] ?? 0),
                'reviews' => (int) ($p['reviews'] ?? 0),
                'address' => $p['address'] ?? '',
                'lat' => $lat,
                'lng' => $lng,
                'price_usd' => $price_usd,
                'per_night_toman' => (int) round($price_usd * $rate),
            ];
        }

        return $out;
    }

    /* ========================================================================
       Jalali → Gregorian Conversion
       ======================================================================== */
    public static function jalali_to_gregorian(string $jalali): ?string {
        if (!preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $jalali, $m)) return null;

        $jy = (int) $m[1];
        $jm = (int) $m[2];
        $jd = (int) $m[3];

        if ($jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) return null;

        $jy -= 979; $jm -= 1; $jd -= 1;

        $j_day_no = 365 * $jy + (int) ($jy / 33) * 8 + (int) ((($jy % 33) + 3) / 4);

        for ($i = 0; $i < $jm; ++$i) $j_day_no += ($i < 6) ? 31 : 30;

        $j_day_no += $jd;

        $g_day_no = $j_day_no + 79;

        $gy = 1600 + 400 * (int) ($g_day_no / 146097);
        $g_day_no %= 146097;

        $leap = true;

        if ($g_day_no >= 36525) {
            $g_day_no--;
            $gy += 100 * (int) ($g_day_no / 36524);
            $g_day_no %= 36524;

            if ($g_day_no >= 365) $g_day_no++; else $leap = false;
        }

        $gy += 4 * (int) ($g_day_no / 1461);
        $g_day_no %= 1461;

        if ($g_day_no >= 366) {
            $leap = false; $g_day_no--;
            $gy += (int) ($g_day_no / 365);
            $g_day_no %= 365;
        }

        $g_days = [31, ($leap ? 29 : 28), 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $gm = 0;

        for ($gm = 0; $gm < 12; $gm++) {
            if ($g_day_no < $g_days[$gm]) break;
            $g_day_no -= $g_days[$gm];
        }

        return sprintf('%04d-%02d-%02d', $gy, $gm + 1, $g_day_no + 1);
    }

    /* ========================================================================
       Fallback: Read Persian City From WordPress Airport Posts
       ======================================================================== */
    private static function airport_city_from_wp(string $code): string {
        if ($code === '') return '';

        $cached = get_transient('ns_airport_city_' . $code);

        if ($cached !== false) return (string) $cached;

        $posts = get_posts([
            'post_type' => 'airport',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [
                'relation' => 'OR',
                ['key' => '_airport_iata', 'value' => $code],
                ['key' => '_iata_code', 'value' => $code],
                ['key' => 'iata', 'value' => $code],
            ],
        ]);

        $title = $posts ? get_the_title($posts[0]) : '';

        if ($title !== '') {
            $title = trim(preg_replace('/^فرودگاه\s+(بین‌المللی\s+)?/u', '', $title));
        }

        set_transient('ns_airport_city_' . $code, $title, 12 * HOUR_IN_SECONDS);

        return $title;
    }

    /* ========================================================================
       ADMIN PAGE
       ======================================================================== */
    public static function render_page(): void {
        if (!current_user_can('manage_options')) return;

        $message = '';
        $message_type = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'ns_live_search_action')) {
                $message = '⚠️ خطای امنیتی (Nonce). صفحه را رفرش کنید.';
                $message_type = 'error';
            } else {
                if (isset($_POST['save_live_settings'])) {
                    update_option(self::OPTION_PROVIDER, sanitize_text_field($_POST['live_provider'] ?? 'serpapi'));
                    update_option(self::KEY_SERPAPI, sanitize_text_field($_POST['live_serpapi_key'] ?? ''));
                    update_option(self::KEY_SEARCHAPI, sanitize_text_field($_POST['live_searchapi_key'] ?? ''));
                    update_option(self::KEY_PARTOCRS, sanitize_text_field($_POST['live_partocrs_key'] ?? ''));
                    update_option(self::KEY_IRSA, sanitize_text_field($_POST['live_irsa_key'] ?? ''));
                    update_option(self::OPTION_RATE, floatval($_POST['live_usd_rate'] ?? 0));

                    $message = '✅ تنظیمات جستجوی زنده ذخیره شد.';
                    $message_type = 'success';
                }

                if (isset($_POST['test_live_serpapi'])) {
                    $result = self::test_serpapi_connection();
                    $message = $result['message'];
                    $message_type = $result['ok'] ? 'success' : 'error';
                }

                if (isset($_POST['test_live_searchapi'])) {
                    $result = self::test_searchapi_connection();
                    $message = $result['message'];
                    $message_type = $result['ok'] ? 'success' : 'error';
                }
            }
        }

        $provider = self::get_provider();

        $serpapi_key = get_option(self::KEY_SERPAPI, '');
        $searchapi_key = get_option(self::KEY_SEARCHAPI, '');
        $partocrs_key = get_option(self::KEY_PARTOCRS, '');
        $irsa_key = get_option(self::KEY_IRSA, '');

        $usd_rate = self::get_usd_rate();
        $rate_manual = floatval(get_option(self::OPTION_RATE, 0));

        $has_serpapi = !empty($serpapi_key);
        $has_searchapi = !empty($searchapi_key);

        $status_icon = '❌';
        $status_text = 'کلید تنظیم نشده';
        $status_color = '#dc3232';

        if ($has_serpapi || $has_searchapi) {
            $status_icon = '✅';
            $status_text = 'فعال';
            $status_color = '#00a32a';
        }

        $labels = [
            'serpapi' => 'SerpApi (توصیه می‌شود)',
            'searchapi' => 'SearchApi (Fallback)',
            'partocrs' => 'Partocrs.ir',
            'irsa' => 'Irsa.ir'
        ];
        ?>
        <div class="wrap nextsafar-wrap">
            <h1>جستجوی زنده — پرواز / هتل / تور</h1>

            <div class="notice notice-info" style="margin:15px 0;">
                <p>
                    <strong>⚠️ مهم:</strong> این کلیدها فقط برای <b>جستجوی زنده</b> استفاده می‌شوند
                    و کاملاً جدا از کلید ساخت پست‌ها (<code>nextsafar_searchapi_key</code>) هستند.
                </p>

                <p>
                    <strong>💡 استراتژی:</strong> اولویت با <b>SerpApi</b> است. اگر SerpApi خطا داد، سیستم خودکار از <b>SearchApi</b> استفاده می‌کند (fallback).
                </p>
            </div>

            <?php if ($message): ?>
                <div class="notice notice-<?php echo esc_attr($message_type); ?> is-dismissible">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;margin:20px 0;">
                <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1);">
                    <div style="font-size:16px;font-weight:bold;color:#2271b1;">ارائه‌دهنده فعلی:</div>
                    <div style="font-size:20px;font-weight:bold;color:#1d2327;margin-top:8px;">
                        <?php echo esc_html($labels[$provider] ?? $provider); ?>
                    </div>
                </div>

                <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1);">
                    <div style="font-size:28px;font-weight:bold;color:<?php echo $status_color; ?>;"><?php echo $status_icon; ?></div>
                    <div style="color:#666;">وضعیت: <strong style="color:<?php echo $status_color; ?>"><?php echo $status_text; ?></strong></div>
                </div>

                <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1);">
                    <div style="font-size:20px;font-weight:bold;color:#1d2327;">
                        <?php echo number_format($usd_rate); ?> تومان
                    </div>
                    <div style="color:#666;">
                        نرخ فعلی USD
                        <?php echo $rate_manual > 0 ? '(دستی)' : '(زنده از صرافی)'; ?>
                    </div>
                </div>
            </div>

            <div style="background:#fff;padding:20px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1);">
                <h2>تنظیمات ارائه‌دهنده و کلیدها</h2>

                <form method="post" action="">
                    <?php wp_nonce_field('ns_live_search_action'); ?>

                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="live_provider">ارائه‌دهنده جستجو:</label></th>
                            <td>
                                <select id="live_provider" name="live_provider">
                                    <option value="serpapi" <?php selected($provider, 'serpapi'); ?>>✅ SerpApi (توصیه می‌شود - اولویت اصلی)</option>
                                    <option value="searchapi" <?php selected($provider, 'searchapi'); ?>>SearchApi (Fallback)</option>
                                    <option value="partocrs" <?php selected($provider, 'partocrs'); ?>>Partocrs.ir (به‌زودی)</option>
                                    <option value="irsa" <?php selected($provider, 'irsa'); ?>>Irsa.ir (به‌زودی)</option>
                                </select>

                                <p class="description">
                                    <b>SerpApi:</b> ارائه‌دهنده اصلی با کیفیت بالا.<br>
                                    <b>SearchApi:</b> ارائه‌دهنده جایگزین در صورت خطای SerpApi.
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="live_serpapi_key">کلید SerpApi (اصلی):</label></th>
                            <td>
                                <input type="password" id="live_serpapi_key" name="live_serpapi_key"
                                       value="<?php echo esc_attr($serpapi_key); ?>"
                                       class="regular-text" style="width:360px;direction:ltr;font-family:monospace;">

                                <?php if ($has_serpapi): ?>
                                    <span style="color:#00a32a;margin-left:10px;">✅ فعال</span>
                                <?php endif; ?>

                                <p class="description">
                                    کلید API از <a href="https://serpapi.com" target="_blank">serpapi.com</a> دریافت کنید.
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="live_searchapi_key">کلید SearchApi (Fallback):</label></th>
                            <td>
                                <input type="password" id="live_searchapi_key" name="live_searchapi_key"
                                       value="<?php echo esc_attr($searchapi_key); ?>"
                                       class="regular-text" style="width:360px;direction:ltr;font-family:monospace;">

                                <?php if ($has_searchapi): ?>
                                    <span style="color:#00a32a;margin-left:10px;">✅ فعال</span>
                                <?php endif; ?>

                                <p class="description">
                                    کلید API از <a href="https://searchapi.io" target="_blank">searchapi.io</a> دریافت کنید.
                                    این کلید به عنوان <b>جایگزین</b> در صورت خطای SerpApi استفاده می‌شود.
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="live_partocrs_key">کلید Partocrs (اختیاری):</label></th>
                            <td>
                                <input type="password" id="live_partocrs_key" name="live_partocrs_key"
                                       value="<?php echo esc_attr($partocrs_key); ?>"
                                       class="regular-text" style="width:360px;direction:ltr;font-family:monospace;">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="live_irsa_key">کلید Irsa (اختیاری):</label></th>
                            <td>
                                <input type="password" id="live_irsa_key" name="live_irsa_key"
                                       value="<?php echo esc_attr($irsa_key); ?>"
                                       class="regular-text" style="width:360px;direction:ltr;font-family:monospace;">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><label for="live_usd_rate">نرخ دستی USD → تومان:</label></th>
                            <td>
                                <input type="number" id="live_usd_rate" name="live_usd_rate"
                                       value="<?php echo esc_attr($rate_manual); ?>"
                                       class="regular-text" style="width:180px;direction:ltr;">

                                <p class="description">
                                    <b>0 = نرخ زنده از بخش صرافی.</b> فقط اگه می‌خوای دستی ثابت کنی عدد بذار.
                                </p>
                            </td>
                        </tr>
                    </table>

                    <p>
                        <input type="submit" name="save_live_settings" class="button button-primary button-large" value="ذخیره تنظیمات">
                        <input type="submit" name="test_live_serpapi" class="button button-secondary" value="🧪 تست اتصال SerpApi">
                        <input type="submit" name="test_live_searchapi" class="button button-secondary" value="🧪 تست اتصال SearchApi">
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    private static function test_serpapi_connection(): array {
        $api_key = get_option(self::KEY_SERPAPI, '');

        if (empty($api_key)) {
            return ['ok' => false, 'message' => '⚠️ کلید SerpApi تنظیم نشده است.'];
        }

        // Fully correct parameters according to documentation.
        $params = [
            'engine' => 'google_flights',
            'departure_id' => 'JFK',
            'arrival_id' => 'LAX',
            'outbound_date' => date('Y-m-d', strtotime('+7 days')),
            'type' => 2,            // One way = 2 (number!).
            'travel_class' => 1,    // Economy = 1 (number!).
            'adults' => 1,
            'currency' => 'USD',
            'api_key' => $api_key,
        ];

        $url = 'https://serpapi.com/search.json?' . http_build_query($params);

        error_log("[NS SerpApi Test] URL: " . substr($url, 0, 200));

        $response = wp_remote_get($url, ['timeout' => 30, 'headers' => ['Accept' => 'application/json']]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'message' => '❌ خطای شبکه: ' . $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            $err = json_decode($body, true);

            return ['ok' => false, 'message' => '❌ خطای SerpApi: ' . ($err['error'] ?? "HTTP $code") . " | Body: " . substr($body, 0, 200)];
        }

        $data = json_decode($body, true);
        $count = count($data['best_flights'] ?? []) + count($data['other_flights'] ?? []);

        return ['ok' => true, 'message' => "✅ اتصال SerpApi موفق — $count پرواز در مسیر JFK → LAX یافت شد."];
    }

    private static function test_searchapi_connection(): array {
        $api_key = get_option(self::KEY_SEARCHAPI, '');

        if (empty($api_key)) {
            return ['ok' => false, 'message' => '⚠️ کلید SearchApi تنظیم نشده است.'];
        }

        $url = 'https://www.searchapi.io/api/v1/search?' . http_build_query([
            'engine' => 'google_flights',
            'flight_type' => 'one_way',
            'departure_id' => 'JFK',
            'arrival_id' => 'LAX',
            'outbound_date' => date('Y-m-d', strtotime('+7 days')),
            'adults' => 1,
            'currency' => 'USD',
            'api_key' => $api_key,
        ]);

        $response = wp_remote_get($url, ['timeout' => 30, 'headers' => ['Accept' => 'application/json']]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'message' => '❌ خطای شبکه: ' . $response->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code !== 200) {
            $err = json_decode($body, true);

            return ['ok' => false, 'message' => '❌ خطای SearchApi: ' . ($err['error'] ?? "HTTP $code")];
        }

        $data = json_decode($body, true);
        $count = count($data['best_flights'] ?? []) + count($data['other_flights'] ?? []);

        return ['ok' => true, 'message' => "✅ اتصال SearchApi موفق — $count پرواز در مسیر JFK → LAX یافت شد."];
    }
}