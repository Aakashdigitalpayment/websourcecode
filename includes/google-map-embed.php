<?php
/**
 * Google Map URL → iframe-safe embed URL.
 * Admin often pastes maps.app.goo.gl share links; those refuse iframe embedding.
 */

if (!function_exists('resolveHttpRedirectUrl')) {
    /**
     * Follow Location headers without downloading the body.
     */
    function resolveHttpRedirectUrl(string $url, int $maxRedirects = 6): string
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return $url;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => $maxRedirects,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; CoopMapBot/1.0)',
                CURLOPT_NOBODY => true,
                CURLOPT_HEADER => true,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            curl_exec($ch);
            $final = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (is_string($final) && $final !== '' && $code > 0) {
                return $final;
            }
        }

        $current = $url;
        for ($i = 0; $i < $maxRedirects; $i++) {
            $headers = @get_headers($current, true);
            if ($headers === false) {
                break;
            }

            $statusLine = is_array($headers[0] ?? null) ? (string)end($headers[0]) : (string)($headers[0] ?? '');
            $code = 0;
            if (preg_match('/\s(\d{3})\s/', $statusLine, $m)) {
                $code = (int)$m[1];
            }

            $location = $headers['Location'] ?? $headers['location'] ?? null;
            if (is_array($location)) {
                $location = end($location);
            }
            $location = is_string($location) ? trim($location) : '';

            if ($location !== '' && ($code === 301 || $code === 302 || $code === 303 || $code === 307 || $code === 308)) {
                if (str_starts_with($location, '/')) {
                    $parts = parse_url($current);
                    $scheme = $parts['scheme'] ?? 'https';
                    $host = $parts['host'] ?? '';
                    $location = $scheme . '://' . $host . $location;
                }
                $current = $location;
                continue;
            }
            break;
        }

        return $current;
    }
}

if (!function_exists('normalizeGoogleMapEmbedUrl')) {
    /**
     * Convert share / place / short / iframe HTML into an embeddable maps URL.
     */
    function normalizeGoogleMapEmbedUrl(string $raw): string
    {
        $raw = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($raw === '') {
            return '';
        }

        // Full <iframe ...> pasted
        if (preg_match('/<iframe[^>]+src=["\']([^"\']+)["\']/i', $raw, $m)) {
            $raw = trim($m[1]);
        }

        // Already embeddable
        if (stripos($raw, 'google.com/maps/embed') !== false
            || stripos($raw, 'maps.google.com/maps/embed') !== false
            || (stripos($raw, 'output=embed') !== false && stripos($raw, 'google.') !== false)
        ) {
            return $raw;
        }

        // Short share links must be resolved first
        if (preg_match('#(?:maps\.app\.goo\.gl|goo\.gl/maps)/#i', $raw)) {
            $resolved = resolveHttpRedirectUrl($raw);
            if ($resolved !== '' && $resolved !== $raw) {
                $raw = $resolved;
            }
        }

        // Exact marker coords from place URLs: !3dLAT!4dLNG
        if (preg_match('/!3d(-?\d+\.?\d*)!4d(-?\d+\.?\d*)/', $raw, $m)) {
            return 'https://www.google.com/maps?q=' . rawurlencode($m[1] . ',' . $m[2]) . '&z=17&output=embed';
        }

        // Viewport @lat,lng,zoom
        if (preg_match('/@(-?\d+\.?\d*),(-?\d+\.?\d*)(?:,(\d+(?:\.\d*)?)z)?/', $raw, $m)) {
            $q = $m[1] . ',' . $m[2];
            $z = isset($m[3]) && $m[3] !== '' ? (int)round((float)$m[3]) : 17;
            return 'https://www.google.com/maps?q=' . rawurlencode($q) . '&z=' . max(1, min(21, $z)) . '&output=embed';
        }

        // ?q= query
        if (preg_match('/[?&]q=([^&]+)/i', $raw, $m)) {
            return 'https://www.google.com/maps?q=' . $m[1] . '&output=embed';
        }

        // /maps/place/Name/...
        if (preg_match('#/maps/place/([^/@]+)#i', $raw, $m)) {
            $place = rawurldecode(str_replace('+', ' ', $m[1]));
            return 'https://www.google.com/maps?q=' . rawurlencode($place) . '&output=embed';
        }

        // Generic google maps URL — try output=embed append
        if (preg_match('#^https?://(?:www\.)?(?:google\.[^/]+/maps|maps\.google\.[^/]+)#i', $raw)) {
            $sep = str_contains($raw, '?') ? '&' : '?';
            if (stripos($raw, 'output=embed') === false) {
                return $raw . $sep . 'output=embed';
            }
            return $raw;
        }

        // Still a short link that failed to resolve — not embeddable
        if (preg_match('#(?:maps\.app\.goo\.gl|goo\.gl/maps)/#i', $raw)) {
            return '';
        }

        return $raw;
    }
}
