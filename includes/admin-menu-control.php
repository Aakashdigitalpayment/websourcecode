<?php
/**
 * Admin Menu Control — Superadmin can hide sidebar groups per cooperative deploy.
 * Default: empty hidden list → all menus visible (current behavior).
 * Save requires physical confirm code (not role alone) so stolen SA session cannot flip menus.
 */
if (!defined('ADMIN_MENU_CONTROL_CONFIRM_CODE')) {
    /* Physical / out-of-band confirm — required on every save (hardcoded by product owner). */
    define('ADMIN_MENU_CONTROL_CONFIRM_CODE', '9856026434');
}

if (!function_exists('admin_menu_control_catalog')) {
    /**
     * Controllable sidebar groups (stable keys match admin-header data-group).
     * @return array<string, array{np:string,en:string,hint_np:string,hint_en:string}>
     */
    function admin_menu_control_catalog(): array
    {
        return [
            'samgri' => [
                'np' => 'सामग्री',
                'en' => 'Content',
                'hint_np' => 'सूचना, समाचार, ग्यालरी, सेवा, रिपोर्ट…',
                'hint_en' => 'Notices, news, gallery, services, reports…',
            ],
            'toli' => [
                'np' => 'मानवीय स्रोत',
                'en' => 'Human Resources',
                'hint_np' => 'समिति, कर्मचारी, सूचना/गुनासो अधिकारी',
                'hint_en' => 'Committees, staff, officers',
            ],
            'rojgar' => [
                'np' => 'रोजगारी',
                'en' => 'Career',
                'hint_np' => 'जागिर पोस्ट र आवेदन',
                'hint_en' => 'Job posts and applications',
            ],
            'sadasya' => [
                'np' => 'सदस्य',
                'en' => 'Members',
                'hint_np' => 'सदस्य सूची, KYM, portal, import',
                'hint_en' => 'Member list, KYM, portal, import',
            ],
            'aavedan' => [
                'np' => 'अन्य आवेदन',
                'en' => 'Applications',
                'hint_np' => 'ऋण, खाता, डिजिटल, लिलाम, बजार…',
                'hint_en' => 'Loan, account, digital, auction, marketplace…',
            ],
            'program' => [
                'np' => 'कार्यक्रम व्यवस्थापन',
                'en' => 'Programs',
                'hint_np' => 'AGM उपस्थिति, दर्ता डेस्क, रिपोर्ट',
                'hint_en' => 'AGM attendance, desk, reports',
            ],
            'nirvachan' => [
                'np' => 'निर्वाचन',
                'en' => 'Election',
                'hint_np' => 'उम्मेदवार, मतदान उपस्थिति, नतिजा',
                'hint_en' => 'Candidates, voting attendance, results',
            ],
            'sampark' => [
                'np' => 'सम्पर्क',
                'en' => 'Contact',
                'hint_np' => 'सन्देश, गुनासो, कल्याण, भेटघाट',
                'hint_en' => 'Messages, grievances, welfare, visits',
            ],
            'sanstha' => [
                'np' => 'संस्था',
                'en' => 'Organization',
                'hint_np' => 'सेवा केन्द्र, सेटिङ, सूचना कोठा, AI…',
                'hint_en' => 'Centers, settings, info room, AI…',
            ],
            'prawidhi' => [
                'np' => 'प्रविधि',
                'en' => 'Technical',
                'hint_np' => 'System info, health, audit (SA tools रहन्छन्)',
                'hint_en' => 'System info, health, audit (SA tools stay)',
            ],
        ];
    }
}

if (!function_exists('admin_menu_control_load_hidden')) {
    /** @return list<string> */
    function admin_menu_control_load_hidden(bool $reload = false): array
    {
        static $cached = null;
        if ($reload) {
            $cached = null;
        }
        if ($cached !== null) {
            return $cached;
        }
        $raw = function_exists('getSetting') ? (string)getSetting('admin_hidden_menu_groups', '[]') : '[]';
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $cached = [];
            return $cached;
        }
        $allowed = array_keys(admin_menu_control_catalog());
        $out = [];
        foreach ($decoded as $g) {
            $g = trim((string)$g);
            if ($g !== '' && in_array($g, $allowed, true)) {
                $out[] = $g;
            }
        }
        $cached = array_values(array_unique($out));
        return $cached;
    }
}

if (!function_exists('admin_menu_control_save_hidden')) {
    /**
     * @param list<string> $groups
     */
    function admin_menu_control_save_hidden(array $groups): void
    {
        $allowed = array_keys(admin_menu_control_catalog());
        $clean = [];
        foreach ($groups as $g) {
            $g = trim((string)$g);
            if ($g !== '' && in_array($g, $allowed, true)) {
                $clean[] = $g;
            }
        }
        $clean = array_values(array_unique($clean));
        updateSetting('admin_hidden_menu_groups', json_encode($clean, JSON_UNESCAPED_UNICODE));
        admin_menu_control_load_hidden(true);
    }
}

if (!function_exists('admin_menu_control_verify_confirm_code')) {
    function admin_menu_control_verify_confirm_code(?string $code): bool
    {
        $code = trim((string)$code);
        if ($code === '') {
            return false;
        }
        return hash_equals(ADMIN_MENU_CONTROL_CONFIRM_CODE, $code);
    }
}

if (!function_exists('admin_menu_group_visible')) {
    /**
     * Superadmin always sees every group (so they can manage).
     * Regular admins: hidden groups from setting are off. Default empty = all on.
     */
    function admin_menu_group_visible(string $groupKey): bool
    {
        if (!empty($_SESSION['is_superadmin'])) {
            return true;
        }
        $hidden = admin_menu_control_load_hidden();
        return !in_array($groupKey, $hidden, true);
    }
}

if (!function_exists('admin_menu_page_allowed')) {
    /**
     * Direct-URL guard for non-superadmin.
     * Shared pages (e.g. appointments in two groups) stay allowed if any containing group is still visible.
     */
    function admin_menu_page_allowed(string $currentPage, array $pageGroups): bool
    {
        if (!empty($_SESSION['is_superadmin'])) {
            return true;
        }
        $always = [
            'dashboard', 'index', 'logout', 'change-password', 'manage-admins',
            'site-license-blocked', 'db-setup',
        ];
        if (in_array($currentPage, $always, true)) {
            return true;
        }
        $hidden = admin_menu_control_load_hidden();
        if ($hidden === []) {
            return true;
        }
        $containing = [];
        foreach ($pageGroups as $group => $pages) {
            if (in_array($currentPage, $pages, true)) {
                $containing[] = $group;
            }
        }
        if ($containing === []) {
            return true;
        }
        foreach ($containing as $g) {
            if (!in_array($g, $hidden, true)) {
                return true;
            }
        }
        return false;
    }
}
