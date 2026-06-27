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
        $svgIcon = '<svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 8v5" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/><circle cx="12" cy="16.4" r="1.15" fill="#fff"/></svg>';

        $phone = function_exists('getSetting') ? trim((string) getSetting('phone', '')) : '';
        $email = function_exists('getSetting') ? trim((string) getSetting('email', '')) : '';
        $phoneH = htmlspecialchars($phone, ENT_QUOTES, 'UTF-8');
        $emailH = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
        $phoneTel = htmlspecialchars(preg_replace('/[^0-9+]/', '', $phone), ENT_QUOTES, 'UTF-8');

        ob_start();
        if (function_exists('coopThemeRequireGlobal')) {
            coopThemeRequireGlobal();
        } elseif (defined('ROOT_PATH') && is_file(ROOT_PATH . 'assets/css/global-theme.php')) {
            require ROOT_PATH . 'assets/css/global-theme.php';
        }
        $brandDynamicStyle = ob_get_clean();

        $dateLineParts = [];
        if ($bs !== '') {
            $dateLineParts[] = htmlspecialchars($bsNp !== '' ? $bsNp : $bs, ENT_QUOTES, 'UTF-8') . ' (बि.सं.)';
        }
        if ($ad !== '') {
            $dateLineParts[] = htmlspecialchars($ad, ENT_QUOTES, 'UTF-8') . ' (AD)';
        }
        $dateLine = !empty($dateLineParts)
            ? '<p class="svc-expired-date-line">अन्तिम वैध मिति · ' . implode(' &nbsp;·&nbsp; ', $dateLineParts) . '</p>'
            : '';

        $actionsHtml = '';
        if ($phone !== '' || $email !== '') {
            $actionsHtml = '<div class="svc-expired-actions">';
            if ($phone !== '') {
                $actionsHtml .= '<a class="svc-expired-btn svc-expired-btn--primary" href="tel:' . $phoneTel . '">फोन गर्नुहोस् · ' . $phoneH . '</a>';
            }
            if ($email !== '') {
                $actionsHtml .= '<a class="svc-expired-btn svc-expired-btn--outline" href="mailto:' . $emailH . '">इमेल गर्नुहोस्</a>';
            }
            $actionsHtml .= '</div>';
        }

        $layoutCss = <<<'CSS'
<style id="svc-expired-layout">
*,*::before,*::after{box-sizing:border-box}
.svc-expired-page{margin:0;min-height:100dvh;font-family:var(--font-primary,'Mukta','Noto Sans Devanagari',system-ui,sans-serif);color:var(--text-primary);-webkit-font-smoothing:antialiased;display:flex;align-items:center;justify-content:center;padding:clamp(1.25rem,5vw,2.5rem);position:relative;background:#f4f5f7}
.svc-expired-shell{position:relative;z-index:1;width:100%;max-width:27rem}
.svc-expired-card{background:#fff;border-radius:20px;border:1px solid #eceef1;box-shadow:0 1px 2px rgba(15,23,42,.04),0 18px 40px -16px rgba(15,23,42,.16);overflow:hidden;padding:clamp(1.75rem,5vw,2.5rem) clamp(1.5rem,5vw,2.25rem);text-align:center}
.svc-expired-icon{display:inline-flex;align-items:center;justify-content:center;width:4.5rem;height:4.5rem;border-radius:50%;background:var(--danger,#dc3545);margin-bottom:1.4rem;box-shadow:0 6px 16px -4px rgba(220,53,69,.4)}
.svc-expired-head h1{margin:0 0 .9rem;font-size:clamp(1.5rem,5vw,1.85rem);font-weight:800;letter-spacing:-.02em;line-height:1.25;color:var(--text-primary,#1a1d23)}
.svc-expired-desc{margin:0 0 1.5rem;font-size:.96rem;line-height:1.7;color:var(--text-secondary,#5b6470)}
.svc-expired-desc strong{color:var(--text-primary,#1a1d23);font-weight:700}
.svc-expired-date-line{margin:0 0 1.6rem;font-size:.85rem;line-height:1.6;color:var(--text-muted,#8993a1);background:var(--bg-soft,#f5faf6);border:1px solid var(--border-color,#eceef1);border-radius:10px;padding:.7rem .9rem}
.svc-expired-actions{display:flex;flex-direction:column;gap:.65rem;margin-bottom:1.4rem}
.svc-expired-btn{display:block;width:100%;padding:.85rem 1rem;border-radius:11px;font-size:.98rem;font-weight:700;text-decoration:none;transition:transform .15s ease, opacity .15s ease}
.svc-expired-btn--primary{background:var(--primary-color,#1a5f2a);color:#fff;box-shadow:0 6px 16px -6px rgba(var(--primary-rgb,26,95,42),.5)}
.svc-expired-btn--primary:active{transform:scale(.98);opacity:.92}
.svc-expired-btn--outline{background:#fff;color:var(--text-secondary,#5b6470);border:1.5px solid var(--border-color,#dfe3e8)}
.svc-expired-btn--outline:active{transform:scale(.98);background:#f8f9fa}
.svc-expired-foot{margin:1.4rem 0 0;padding-top:1.2rem;border-top:1px solid var(--border-color,#eceef1);font-size:.82rem;line-height:1.6;color:var(--text-muted,#8993a1)}
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
            . '<main class="svc-expired-shell">'
            . '<div class="svc-expired-card">'
            . '<div class="svc-expired-icon">' . $svgIcon . '</div>'
            . '<div class="svc-expired-head"><h1>सेवा बन्द भयो</h1></div>'
            . '<p class="svc-expired-desc"><strong>' . $siteH . '</strong> को वेबसाइट सेवा हाल नवीकरण नभएकोले अस्थायी रूपमा बन्द छ।</p>'
            . $dateLine
            . $actionsHtml
            . '<p class="svc-expired-foot">नवीकरणका लागि कृपया माथिको नम्बर/इमेलमा सम्पर्क गर्नुहोस्। पुष्टि भएपछि सेवा पुनः सामान्य हुनेछ।<br>© ' . date('Y') . ' ' . $siteH . '। सर्वाधिकार सुरक्षित।</p>'
            . '</div></main></body></html>';
        exit;
    }
}
