<?php
/**
 * NRB Forex Rate Fetcher — नेपाल राष्ट्र बैंकको विनिमय दर
 *
 * Nepal Rastra Bank को Public API बाट Real-time Exchange Rates fetch गर्छ।
 * Data 6 घण्टासम्म cache गरिन्छ — हरेक page load मा API call हुँदैन।
 *
 * API Source: https://www.nrb.org.np/api/forex/v1/rates
 * Usage: $forexData = nrbFetchForex();
 *
 * Robust behaviour:
 *  - Last 10 दिनको range query (शनिबार/बिदामा empty हुने समस्या समाधान)
 *  - Most recent valid entry pick गर्छ (date desc sort)
 *  - cURL primary, file_get_contents fallback
 *  - कुनै step मा fail भए error_log मा कारण लेख्छ
 */

function nrbFetchForex(): array {
    /* Cache file path — 6 घण्टा cache */
    $cacheDir  = ROOT_PATH . 'cache/';
    $cacheFile = $cacheDir . 'nrb_forex_' . date('Y-m-d') . '.json';
    $cacheTTL  = 6 * 3600;

    /* ── आजको Cache valid छ कि? ── */
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTTL) {
        $cached = @json_decode((string)@file_get_contents($cacheFile), true);
        if ($cached && !empty($cached['rates'])) {
            return $cached;
        }
    }

    /* ── NRB API call: last 10 days range (weekend/holiday safe) ── */
    $endDate   = date('Y-m-d');
    $startDate = date('Y-m-d', strtotime('-10 days'));
    $apiUrl    = "https://www.nrb.org.np/api/forex/v1/rates?per_page=100&page=1&from={$startDate}&to={$endDate}";

    $raw = nrbHttpGet($apiUrl);

    if ($raw) {
        $json = json_decode($raw, true);
        if (!empty($json['data']['payload']) && is_array($json['data']['payload'])) {
            /* Latest date पहिला आउने गरी sort */
            $payloads = $json['data']['payload'];
            usort($payloads, static function ($a, $b) {
                return strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? ''));
            });

            /* Latest entry चयन गर्ने जसमा rates छन् */
            $picked = null;
            foreach ($payloads as $p) {
                if (!empty($p['rates']) && is_array($p['rates'])) {
                    $picked = $p;
                    break;
                }
            }

            if ($picked) {
                $publishedOn = $picked['date'] ?? $endDate;
                $rates       = nrbNormaliseRates($picked['rates']);

                $result = [
                    'source'       => 'nrb_live',
                    'published_on' => $publishedOn,
                    'fetched_at'   => date('Y-m-d H:i:s'),
                    'rates'        => $rates,
                ];

                if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
                @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE));
                return $result;
            }
            error_log('[nrb-forex] API returned payload without usable rates between ' . $startDate . ' and ' . $endDate);
        } else {
            error_log('[nrb-forex] API response missing data.payload — got: ' . substr((string)$raw, 0, 240));
        }
    } else {
        error_log('[nrb-forex] API request failed (network/SSL/timeout) for ' . $apiUrl);
    }

    /* ── Fallback 1: सबैभन्दा नयाँ पुरानो cache ── */
    $anyCache = glob($cacheDir . 'nrb_forex_*.json');
    if ($anyCache) {
        rsort($anyCache);
        foreach ($anyCache as $f) {
            $old = @json_decode((string)@file_get_contents($f), true);
            if ($old && !empty($old['rates'])) {
                $old['source'] = 'nrb_cached';
                return $old;
            }
        }
    }

    /* ── Fallback 2: Static (केवल API + cache दुवै नभेटिँदा) ── */
    return nrbStaticFallback();
}

/* ─────────────────────────────────────────────
   HTTP GET helper — cURL primary, fopen fallback
   ───────────────────────────────────────────── */
function nrbHttpGet(string $url): ?string {
    $headers = [
        'Accept: application/json',
        'Accept-Language: en-US,en;q=0.9,ne;q=0.7',
        'Cache-Control: no-cache',
    ];
    $ua = 'Mozilla/5.0 (compatible; CooperativeWebsite/1.0; +https://www.bandanasigdel.com.np)';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => $ua,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body !== false && $code >= 200 && $code < 400) {
            return (string)$body;
        }
        error_log('[nrb-forex] cURL failed http=' . $code . ' err=' . $err);
    }

    /* fopen fallback */
    if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
        $ctx = stream_context_create([
            'http' => [
                'timeout'    => 12,
                'method'     => 'GET',
                'user_agent' => $ua,
                'header'     => implode("\r\n", $headers),
            ],
            'ssl' => [
                'verify_peer'      => false,
                'verify_peer_name' => false,
            ],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body !== false) {
            return (string)$body;
        }
        error_log('[nrb-forex] file_get_contents failed for ' . $url);
    }

    return null;
}

/* ─────────────────────────────────────────────
   NRB rates → uniform shape
   ───────────────────────────────────────────── */
function nrbNormaliseRates(array $rates): array {
    $flagMap = [
        'USD' => 'us', 'EUR' => 'eu', 'GBP' => 'gb', 'AUD' => 'au',
        'CAD' => 'ca', 'CHF' => 'ch', 'JPY' => 'jp', 'CNY' => 'cn',
        'INR' => 'in', 'AED' => 'ae', 'MYR' => 'my', 'SAR' => 'sa',
        'QAR' => 'qa', 'KRW' => 'kr', 'SGD' => 'sg', 'THB' => 'th',
        'KWD' => 'kw', 'SEK' => 'se', 'DKK' => 'dk', 'HKD' => 'hk',
        'NOK' => 'no', 'PKR' => 'pk', 'BHD' => 'bh', 'OMR' => 'om',
    ];

    $out = [];
    foreach ($rates as $r) {
        $iso = $r['currency']['iso3'] ?? '';
        if ($iso === '') continue;
        $out[] = [
            'iso'  => $iso,
            'name' => $r['currency']['name'] ?? $iso,
            'unit' => (int)($r['currency']['unit'] ?? 1),
            'buy'  => number_format((float)($r['buy']  ?? 0), 2),
            'sell' => number_format((float)($r['sell'] ?? 0), 2),
            'flag' => $flagMap[$iso] ?? strtolower(substr($iso, 0, 2)),
        ];
    }
    return $out;
}

/* Static fallback — API + cache दुवै नभेटिँदा मात्र देखाइन्छ.
   यी indicative दर हुन्, exact होइनन् — banner ले स्पष्ट देखाउँछ. */
function nrbStaticFallback(): array {
    return [
        'source'       => 'static_fallback',
        'published_on' => date('Y-m-d'),
        'fetched_at'   => date('Y-m-d H:i:s'),
        'rates'        => [
            ['iso'=>'USD','name'=>'U.S. Dollar',          'unit'=>1,   'buy'=>'138.50','sell'=>'139.10','flag'=>'us'],
            ['iso'=>'EUR','name'=>'European Euro',         'unit'=>1,   'buy'=>'150.20','sell'=>'150.85','flag'=>'eu'],
            ['iso'=>'GBP','name'=>'UK Pound Sterling',     'unit'=>1,   'buy'=>'176.40','sell'=>'177.10','flag'=>'gb'],
            ['iso'=>'AUD','name'=>'Australian Dollar',     'unit'=>1,   'buy'=>'90.10', 'sell'=>'90.55', 'flag'=>'au'],
            ['iso'=>'CAD','name'=>'Canadian Dollar',       'unit'=>1,   'buy'=>'100.20','sell'=>'100.65','flag'=>'ca'],
            ['iso'=>'CHF','name'=>'Swiss Franc',           'unit'=>1,   'buy'=>'156.40','sell'=>'157.05','flag'=>'ch'],
            ['iso'=>'JPY','name'=>'Japanese Yen',          'unit'=>10,  'buy'=>'9.10',  'sell'=>'9.15',  'flag'=>'jp'],
            ['iso'=>'CNY','name'=>'Chinese Yuan',          'unit'=>1,   'buy'=>'19.05', 'sell'=>'19.15', 'flag'=>'cn'],
            ['iso'=>'INR','name'=>'Indian Rupee',          'unit'=>100, 'buy'=>'160.00','sell'=>'160.15','flag'=>'in'],
            ['iso'=>'AED','name'=>'UAE Dirham',            'unit'=>1,   'buy'=>'37.70', 'sell'=>'37.85', 'flag'=>'ae'],
            ['iso'=>'MYR','name'=>'Malaysian Ringgit',     'unit'=>1,   'buy'=>'31.95', 'sell'=>'32.10', 'flag'=>'my'],
            ['iso'=>'SAR','name'=>'Saudi Riyal',           'unit'=>1,   'buy'=>'36.90', 'sell'=>'37.05', 'flag'=>'sa'],
            ['iso'=>'QAR','name'=>'Qatari Riyal',          'unit'=>1,   'buy'=>'38.00', 'sell'=>'38.15', 'flag'=>'qa'],
            ['iso'=>'KRW','name'=>'South Korean Won',      'unit'=>100, 'buy'=>'10.00', 'sell'=>'10.05', 'flag'=>'kr'],
            ['iso'=>'SGD','name'=>'Singapore Dollar',      'unit'=>1,   'buy'=>'103.20','sell'=>'103.80','flag'=>'sg'],
            ['iso'=>'KWD','name'=>'Kuwaiti Dinar',         'unit'=>1,   'buy'=>'450.00','sell'=>'452.00','flag'=>'kw'],
        ],
    ];
}
