<?php
/**
 * Simple site service expiry — Superadmin मात्र म्याद बदल्न सक्छ।
 *
 * स्रोत सत्य: site_settings.site_license_valid_until_bs (बि.सं. Latin Y-m-d)।
 * DB मirror (छान्दा/सेभ गर्दा अपडेट): site_license_valid_until_ad (ई.सं. Latin),
 * site_license_valid_until_bs_np (बि.सं. नेपाली अंक)। खाली = म्याद बन्द।
 *
 * म्याद सकियो: काठमाडौंको आजको बि.सं. > अन्तिम बि.सं. दिन → site_license_expired() === true
 */
declare(strict_types=1);

require_once __DIR__ . '/nepali-bs-convert.php';

if (!function_exists('site_license_sync_mirror_settings')) {
    /** बि.सं. Latin बाट ई.सं. र बि.सं. नेपाली अंक सेटिङमा लेख्छ (खाली BS = सबै खाली) */
    function site_license_sync_mirror_settings(string $bsLatin): void {
        if (!function_exists('updateSetting')) {
            return;
        }
        if ($bsLatin === '') {
            updateSetting('site_license_valid_until_ad', '');
            updateSetting('site_license_valid_until_bs_np', '');
            return;
        }
        $ad = nepali_bs_to_ad_string($bsLatin) ?? '';
        updateSetting('site_license_valid_until_ad', $ad);
        updateSetting('site_license_valid_until_bs_np', nepali_latin_digits_to_devanagari($bsLatin));
    }
}

if (!function_exists('site_license_until_bs')) {
    function site_license_until_bs(): string {
        if (!function_exists('getSetting')) {
            return '';
        }
        return trim((string) getSetting('site_license_valid_until_bs', ''));
    }
}

if (!function_exists('site_license_until_ad')) {
    /** ई.सं. अन्तिम दिन (Latin) — DB मा बच्छ; खाली भए BS बाट निकालिन्छ */
    function site_license_until_ad(): string {
        $bs = site_license_until_bs();
        if ($bs === '') {
            return '';
        }
        if (!function_exists('getSetting')) {
            return nepali_bs_to_ad_string($bs) ?? '';
        }
        $stored = trim((string) getSetting('site_license_valid_until_ad', ''));
        if ($stored !== '') {
            return $stored;
        }
        return nepali_bs_to_ad_string($bs) ?? '';
    }
}

if (!function_exists('site_license_until_bs_np')) {
    /** बि.सं. नेपाली अंकमा (उदा. २०८३-०१-१९) — DB मा बच्छ */
    function site_license_until_bs_np(): string {
        $bs = site_license_until_bs();
        if ($bs === '') {
            return '';
        }
        if (!function_exists('getSetting')) {
            return nepali_latin_digits_to_devanagari($bs);
        }
        $stored = trim((string) getSetting('site_license_valid_until_bs_np', ''));
        if ($stored !== '') {
            return $stored;
        }
        return nepali_latin_digits_to_devanagari($bs);
    }
}

if (!function_exists('site_license_is_configured')) {
    function site_license_is_configured(): bool {
        $d = site_license_until_bs();
        if ($d === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) !== 1) {
            return false;
        }
        $p = nepali_parse_bs_ymd($d);
        return $p !== null && nepali_bs_date_valid($p);
    }
}

if (!function_exists('site_license_expired')) {
    /**
     * म्याद सकियो (Expired): काठमाडौं आजको बि.सं. क्यालेन्डर दिन > सेट गरिएको अन्तिम बि.सं. दिन।
     * अन्तिम दिनसम्म वैध; त्यसपछिको दिनदेखि true।
     */
    function site_license_expired(): bool {
        if (!site_license_is_configured()) {
            return false;
        }
        $until = site_license_until_bs();
        $today = nepali_kathmandu_today_bs();
        return nepali_bs_ymd_compare($today, $until) === 1;
    }
}

if (!function_exists('site_license_login_blocked_for_user')) {
    function site_license_login_blocked_for_user(array $userRow): bool {
        if (!site_license_expired()) {
            return false;
        }
        return !admin_db_role_is_superadmin($userRow['role'] ?? '');
    }
}

if (!function_exists('site_license_public_guard')) {
    /**
     * सार्वजनिक / member — म्याद सकिएमा पूर्ण पृष्ठ सन्देश र exit।
     */
    function site_license_public_guard(): void {
        if (!function_exists('site_license_expired') || !site_license_expired()) {
            return;
        }
        $site = function_exists('getSetting') ? getSetting('site_name', 'सहकारी') : 'सहकारी';
        $siteH = htmlspecialchars($site, ENT_QUOTES, 'UTF-8');
        $bs = function_exists('site_license_until_bs') ? site_license_until_bs() : '';
        $bsNp = function_exists('site_license_until_bs_np') ? site_license_until_bs_np() : '';
        $ad = function_exists('site_license_until_ad') ? site_license_until_ad() : '';
        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: text/html; charset=UTF-8');
        }

        $baseUrl = defined('SITE_URL') ? (string) SITE_URL : '/';
        $baseUrlH = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');
        $svgIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M8 2v3M16 2v3M3.5 9.09h17" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><rect x="3.5" y="4.5" width="17" height="16" rx="2.5" stroke="currentColor" stroke-width="1.5"/><path d="M9.5 15.5l5-5M14.5 15.5l-5-5" stroke="currentColor" stroke-width="1.65" stroke-linecap="round"/></svg>';

        ob_start();
        if (function_exists('coopThemeRequireGlobal')) {
            coopThemeRequireGlobal();
        } elseif (defined('ROOT_PATH') && is_file(ROOT_PATH . 'assets/css/global-theme.php')) {
            require ROOT_PATH . 'assets/css/global-theme.php';
        }
        $brandDynamicStyle = ob_get_clean();

        $datesBlock = '';
        if ($bs !== '') {
            $datesBlock = '<div class="svc-expired-status"><span class="svc-expired-pill">स्थिति · म्याद सकियो · Expired</span></div>'
                . '<dl class="svc-expired-dates">'
                . '<div class="svc-expired-date-row"><dt>अन्तिम वैध दिन — बि.सं.</dt><dd>'
                . htmlspecialchars($bsNp !== '' ? $bsNp : $bs, ENT_QUOTES, 'UTF-8')
                . ' <span class="svc-expired-date-code">(' . htmlspecialchars($bs, ENT_QUOTES, 'UTF-8') . ')</span></dd></div>'
                . ($ad !== '' ? '<div class="svc-expired-date-row"><dt>अन्तिम वैध दिन — ई.सं. / AD</dt><dd>' . htmlspecialchars($ad, ENT_QUOTES, 'UTF-8') . '</dd></div>' : '')
                . '</dl>';
        } else {
            $datesBlock = '<div class="svc-expired-status"><span class="svc-expired-pill">स्थिति · म्याद सकियो · Expired</span></div>';
        }

        $layoutCss = <<<'CSS'
<style id="svc-expired-layout">
*,*::before,*::after{box-sizing:border-box}
.svc-expired-page{margin:0;min-height:100dvh;font-family:var(--font-primary,'Mukta','Noto Sans Devanagari',system-ui,sans-serif);color:var(--text-primary);-webkit-font-smoothing:antialiased;display:flex;align-items:center;justify-content:center;padding:clamp(1.25rem,5vw,2.5rem);position:relative}
.svc-expired-bg{position:fixed;inset:0;pointer-events:none;z-index:0;background:radial-gradient(ellipse 85% 55% at 50% -15%,rgba(var(--primary-rgb),0.12),transparent 58%),radial-gradient(ellipse 50% 45% at 100% 0%,rgba(var(--primary-rgb),0.05),transparent 42%),linear-gradient(165deg,var(--bg-soft,#f5faf6) 0%,var(--bg-page,#f8faf9) 45%,#f8fafc 100%)}
.svc-expired-shell{position:relative;z-index:1;width:100%;max-width:32rem}
.svc-expired-card{background:var(--bg-card,#fff);border-radius:var(--radius-xl,24px);border:1px solid var(--border-color,#e5e7eb);box-shadow:0 1px 2px rgba(15,23,42,.04),0 24px 48px -12px rgba(var(--primary-rgb),0.14),0 0 0 1px rgba(255,255,255,.6) inset;overflow:hidden}
.svc-expired-card__accent{height:4px;background:linear-gradient(90deg,var(--primary-color),var(--primary-light,var(--primary-color)))}
.svc-expired-head{text-align:center;padding:clamp(1.35rem,4vw,1.85rem) clamp(1.35rem,4vw,1.75rem) 1rem}
.svc-expired-icon{display:inline-flex;align-items:center;justify-content:center;width:4.25rem;height:4.25rem;border-radius:50%;color:var(--color-warning,#d97706);background:rgba(var(--primary-rgb),0.08);border:1px solid rgba(var(--primary-rgb),0.12);margin-bottom:1rem}
.svc-expired-eyebrow{margin:0 0 .35rem;font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-muted,#6b7280)}
.svc-expired-head h1{margin:0;font-size:clamp(1.35rem,4.2vw,1.6rem);font-weight:800;letter-spacing:-.03em;line-height:1.25;color:var(--text-primary)}
.svc-expired-site{margin:.65rem 0 0;font-size:.95rem;line-height:1.55;color:var(--text-secondary,#4a5a4f)}
.svc-expired-site strong{font-weight:700;color:var(--text-primary)}
.svc-expired-body{padding:0 clamp(1.35rem,4vw,1.75rem) clamp(1.5rem,4vw,1.85rem)}
.svc-expired-desc{margin:0 0 1.15rem;text-align:center;font-size:.92rem;line-height:1.65;color:var(--text-secondary,#4a5a4f)}
.svc-expired-status{display:flex;justify-content:center;margin-bottom:1rem}
.svc-expired-pill{display:inline-flex;align-items:center;gap:.35rem;font-size:.75rem;font-weight:600;padding:.4rem .85rem;border-radius:999px;background:linear-gradient(180deg,#fffbeb,#fef3c7);color:#92400e;border:1px solid rgba(251,191,36,.45);box-shadow:0 1px 2px rgba(146,64,14,.06)}
.svc-expired-dates{margin:0 0 1.25rem;padding:0;background:var(--bg-soft,#f5faf6);border:1px solid var(--border-color,#e5e7eb);border-radius:var(--radius-lg,16px);overflow:hidden}
.svc-expired-date-row{padding:.85rem 1.1rem}
.svc-expired-date-row+.svc-expired-date-row{border-top:1px solid var(--border-soft,#f0f0f0)}
.svc-expired-dates dt{margin:0 0 .35rem;font-size:.68rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--text-muted,#6b7280)}
.svc-expired-dates dd{margin:0;font-size:1.05rem;font-weight:700;color:var(--text-primary);line-height:1.35}
.svc-expired-date-code{font-weight:500;font-size:.88rem;color:var(--text-muted)}
.svc-expired-foot{margin:0;text-align:center;font-size:.88rem;line-height:1.65;color:var(--text-muted)}
.svc-expired-foot strong{color:var(--text-secondary);font-weight:600}
.svc-expired-hint{margin:.85rem 0 0;padding:.85rem 1rem;text-align:center;font-size:.8rem;line-height:1.55;color:var(--text-secondary);background:rgba(var(--primary-rgb),0.06);border-radius:var(--radius-md,10px);border:1px solid rgba(var(--primary-rgb),0.1)}
@media (prefers-reduced-motion:reduce){*{animation:none!important;transition:none!important}}
</style>
CSS;

        echo '<!DOCTYPE html><html lang="ne"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="robots" content="noindex,nofollow">'
            . '<title>सेवा अस्थायी उपलब्ध छैन</title>'
            . '<link rel="preconnect" href="https://fonts.googleapis.com">'
            . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
            . '<link href="https://fonts.googleapis.com/css2?family=Mukta:wght@400;500;600;700;800&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">'
            . '<link rel="stylesheet" href="' . $baseUrlH . 'assets/css/app-core.css">'
            . '<link rel="stylesheet" href="' . $baseUrlH . 'assets/css/app-public.css">'
            . $layoutCss
            . '</head><body class="svc-expired-page">'
            . '<div class="svc-expired-bg" aria-hidden="true"></div>'
            . '<main class="svc-expired-shell">'
            . '<div class="svc-expired-card">'
            . '<div class="svc-expired-card__accent" aria-hidden="true"></div>'
            . '<div class="svc-expired-head">'
            . '<div class="svc-expired-icon">' . $svgIcon . '</div>'
            . '<p class="svc-expired-eyebrow">सेवा स्थिति · Service status</p>'
            . '<h1>सेवा म्याद सकियो</h1>'
            . '<p class="svc-expired-site"><strong>' . $siteH . '</strong> को वेबसाइट सेवा हाल अस्थायी रूपमा उपलब्ध छैन।</p>'
            . '</div>'
            . '<div class="svc-expired-body">'
            . '<p class="svc-expired-desc">यो सन्देश साइट सेवा अवधि समाप्त भएपछि देखाइन्छ। नवीकरण पछि सेवा पुनः सामान्य हुन्छ।</p>'
            . $datesBlock
            . '<p class="svc-expired-foot">लाइसेन्स नवीकरण वा प्राविधिक सहयोगका लागि कृपया <strong>विक्रेता / प्राविधिक टोली</strong> लाई सम्पर्क गर्नुहोस्।</p>'
            . '<p class="svc-expired-hint">नवीकरण पुष्टि भएपछि सेवा पुनः सामान्य रूपमा सञ्चालन हुनेछ। थप जानकारीका लागि कार्यालय वा प्राविधिक टोली सम्पर्क गर्नुहोस्।</p>'
            . '</div></div></main></body></html>';
        exit;
    }
}
