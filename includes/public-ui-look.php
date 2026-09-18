<?php
/**
 * Public site look presets — CSS-only chrome personality.
 * Soft = current live UI (zero overrides). Brand colors stay in global-theme.php.
 * Admin / member portals are out of scope.
 */
if (!defined('ADMIN_MENU_CONTROL_CONFIRM_CODE')) {
    require_once __DIR__ . '/admin-menu-control.php';
}

if (!function_exists('coopPublicUiLooks')) {
    /**
     * @return array<string, array{label:string, hint:string, best:string}>
     */
    function coopPublicUiLooks(): array
    {
        return [
            'soft' => [
                'label' => 'Soft',
                'hint'  => 'Live default — rounded cards, familiar hero, no look overrides.',
                'best'  => 'Recommended. Keep this so visitors see the site as they already know it.',
            ],
            'sharp' => [
                'label' => 'Sharp',
                'hint'  => 'Square edges, why-us then join CTA under institutional, rates later — formal ledger feel.',
                'best'  => 'Best for formal / office look. Same pages, text, and brand colors.',
            ],
            'editorial' => [
                'label' => 'Editorial',
                'hint'  => 'Full-bleed hero, why+app then join CTA, rates later — magazine rhythm.',
                'best'  => 'Best for a calmer story-led look. Same pages, text, and brand colors.',
            ],
            'compact' => [
                'label' => 'Compact',
                'hint'  => 'Tools+rates then join CTA early, denser gaps, contact last in nav.',
                'best'  => 'Best when you want more content visible at once. Same pages, text, and brand colors.',
            ],
            'bold' => [
                'label' => 'Bold',
                'hint'  => 'Join CTA right under institutional, then services+rates — punchy recruitment feel.',
                'best'  => 'Best when you want visitors to join sooner. Same pages, text, and brand colors.',
            ],
            'airy' => [
                'label' => 'Airy',
                'hint'  => 'Roomy spacing, leadership+news early, join CTA mid-page — people-first calm.',
                'best'  => 'Best for a calm people-led look. Same pages, text, and brand colors.',
            ],
            'cascade' => [
                'label' => 'Cascade',
                'hint'  => 'Services→tools→rates then why+join CTA — clear utility waterfall.',
                'best'  => 'Best for a services-then-tools path. Same pages, text, and brand colors.',
            ],
            'pulse' => [
                'label' => 'Pulse',
                'hint'  => 'Rates+news early, then join CTA — fresh updates first.',
                'best'  => 'Best when members check rates and news often. Same pages, text, and brand colors.',
            ],
            'summit' => [
                'label' => 'Summit',
                'hint'  => 'Awards+leadership early, then why+join CTA — prestige trust feel.',
                'best'  => 'Best for a leadership-led prestige look. Same pages, text, and brand colors.',
            ],
            'harbor' => [
                'label' => 'Harbor',
                'hint'  => 'Why-us then services then join CTA — warm welcome rhythm.',
                'best'  => 'Best for a friendly neighborhood coop feel. Same pages, text, and brand colors.',
            ],
        ];
    }
}

if (!function_exists('coopPublicUiLook')) {
    /** Normalized live look key; default soft for every existing coop. */
    function coopPublicUiLook(): string
    {
        /* Localhost-only preview for QA: ?ui_look=sharp (does not persist) */
        $preview = strtolower(trim((string) ($_GET['ui_look'] ?? '')));
        if ($preview !== '') {
            $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
            $hostOnly = preg_replace('/:\d+$/', '', $host) ?? '';
            $isLocal = in_array($hostOnly, ['localhost', '127.0.0.1', '::1'], true)
                || str_ends_with($hostOnly, '.localhost');
            $looks = coopPublicUiLooks();
            if ($isLocal && isset($looks[$preview])) {
                return $preview;
            }
        }
        $v = 'soft';
        if (function_exists('getSetting')) {
            $v = strtolower(trim((string) getSetting('public_ui_look', 'soft')));
        }
        $looks = coopPublicUiLooks();
        return isset($looks[$v]) ? $v : 'soft';
    }
}

if (!function_exists('coopPublicUiLookNormalize')) {
    function coopPublicUiLookNormalize(?string $raw): string
    {
        $v = strtolower(trim((string) $raw));
        $looks = coopPublicUiLooks();
        return isset($looks[$v]) ? $v : 'soft';
    }
}

if (!function_exists('coopVerifyPublicLookChangePin')) {
    /** Gate live look changes — same physical confirm code as admin menu control. */
    function coopVerifyPublicLookChangePin(?string $pin): bool
    {
        $pin = preg_replace('/\D+/', '', trim((string) $pin)) ?? '';
        if ($pin === '') {
            return false;
        }
        $expected = defined('ADMIN_MENU_CONTROL_CONFIRM_CODE')
            ? (string) ADMIN_MENU_CONTROL_CONFIRM_CODE
            : '9856026434';
        return hash_equals($expected, $pin);
    }
}
