<?php
/**
 * Custom admin roles — per-menu permission matrix (हेर्ने / थप्ने / सम्पादन / हटाउने).
 *
 * Who it applies to: only admin users that have a custom role (admin_users.custom_role_id).
 * Superadmin / admin / editor users without a custom role keep the existing behaviour.
 *
 * Model
 *   admin_roles.permissions = JSON { "notices.php": "vce", "members.php": "v", … }
 *     v = हेर्ने (view, incl. Excel export / print), c = थप्ने (create), e = सम्पादन (edit,
 *     status / approve / toggle), d = हटाउने (delete). c/e/d imply v.
 *   A "menu" is one admin sidebar link; sub-pages map to their menu (coop_perm_page_aliases).
 *
 * Enforcement (server side; the sidebar filter is only UX)
 *   - admin-page-boot.php → coop_perm_enforce_request(): page view + POST action
 *   - has_role() (auth-roles.php) defers to the matrix for custom-role users, so existing
 *     inline require_role('admin') checks follow the matrix of the page being used.
 *   - Superadmin-only pages (users, security, backup, DB, license …) are never grantable.
 */
declare(strict_types=1);

if (!function_exists('coop_perm_actions')) {
    /** @return array<string,string> flag => label */
    function coop_perm_actions(): array
    {
        return ['v' => 'हेर्ने', 'c' => 'थप्ने', 'e' => 'सम्पादन', 'd' => 'हटाउने'];
    }
}

if (!function_exists('coop_perm_registry')) {
    /**
     * Grantable menus, grouped like the sidebar. Keep in sync with admin/includes/admin-header.php
     * (scripts/smoke-admin-permissions.php fails when a sidebar link is missing here).
     *
     * @return array<string, array{label:string, items:array<string,string>}>
     */
    function coop_perm_registry(): array
    {
        return [
            'samgri' => ['label' => 'सामग्री (Content)', 'items' => [
                'notices.php' => 'सूचनाहरू', 'news.php' => 'समाचार', 'sliders.php' => 'स्लाइडर', 'gallery.php' => 'ग्यालरी',
                'services.php' => 'सेवाहरू', 'interest-rates.php' => 'ब्याज दर', 'pages.php' => 'गतिशील पृष्ठ',
                'downloads.php' => 'डाउनलोड', 'faqs.php' => 'प्रश्नोत्तर (FAQs)', 'useful-links.php' => 'उपयोगी लिंकहरू',
                'awards.php' => 'सम्मान/पुरस्कार', 'reports.php' => 'प्रतिवेदन', 'app-features.php' => 'एप सुविधाहरू',
                'why-choose.php' => 'किन हामीलाई छान्ने?', 'partner-facilities.php' => 'साझेदार सुविधा', 'designations.php' => 'पद मास्टर',
            ]],
            'toli' => ['label' => 'टोली (Team)', 'items' => [
                'team.php' => 'सञ्चालक / समिति', 'team-karmachari.php' => 'कर्मचारी / व्यवस्थापन', 'committees.php' => 'समिति/उपसमिति',
                'info-officer.php' => 'सूचना अधिकारी (RTI)', 'grievance-officer.php' => 'गुनासो अधिकारी',
            ]],
            'rojgar' => ['label' => 'रोजगारी', 'items' => [
                'careers.php' => 'रोजगारी पोस्ट', 'job-applications.php' => 'जागिर आवेदन',
            ]],
            'sadasya' => ['label' => 'सदस्य', 'items' => [
                'members.php' => 'सदस्य सूची', 'member-import.php' => 'Bulk Import (CBS)', 'member-ssot-duplicates.php' => 'दोहोरो Member ID',
                'membership-applications.php' => 'सदस्यता अनुरोध (नयाँ)', 'kyc-applications.php' => 'KYM फाइल (समीक्षा)',
                'kyc-risk-reviews.php' => 'KYM जोखिम समीक्षा', 'member-online-portal.php' => 'पोर्टल दर्ता unlock',
                'member-activities.php' => 'गतिविधि खोज', 'credentials.php' => 'स्मार्ट क्रेडेन्सियल म्यानेजर',
            ]],
            'aavedan' => ['label' => 'आवेदन', 'items' => [
                'loan-applications.php' => 'ऋण आवेदन', 'account-applications.php' => 'खाता आवेदन',
                'digital-service-requests.php' => 'डिजिटल सेवा', 'digital-service-types.php' => 'सेवा प्रकार',
                'honor-applications.php' => 'सम्मान आवेदन', 'honor-programs.php' => 'सम्मान कार्यक्रम',
                'appointments.php' => 'भेटघाट / सहकारी भ्रमण', 'auctions.php' => 'लिलामी / बोलपत्र', 'auction-bids.php' => 'बोलपत्र सूची',
                'vendor-enlistment.php' => 'भेन्डर सूचीकरण', 'member-marketplace.php' => 'सदस्य बजार / सीप',
            ]],
            'program' => ['label' => 'कार्यक्रम', 'items' => [
                'program-dashboard.php' => 'कार्यक्रम ड्यासबोर्ड', 'programs.php' => 'कार्यक्रमहरू',
                'program-registration-desk.php' => 'दर्ता डेस्क (उपस्थिति)', 'program-attendance.php' => 'अनुरोध / Pre-reg',
                'program-reports-consolidated.php' => 'कार्यक्रम रिपोर्ट', 'sahakari-calendar-events.php' => 'सहकारी पात्रो कार्यक्रम',
                'program-settings.php' => 'कार्यक्रम सेटिङ',
            ]],
            'nirvachan' => ['label' => 'निर्वाचन', 'items' => [
                'election-information.php' => 'निर्वाचन जानकारी', 'election-posts.php' => 'पद Master',
                'election-candidates.php' => 'उम्मेदवार/पद', 'election-voting-attendance.php' => 'मतदान उपस्थिति',
                'election-results.php' => 'निर्वाचन नतिजा',
            ]],
            'sampark' => ['label' => 'सम्पर्क / गुनासो', 'items' => [
                'messages.php' => 'सन्देशहरू', 'feedbacks.php' => 'सुझाव/प्रतिक्रिया', 'grievances.php' => 'गुनासो',
                'welfare-claims.php' => 'कल्याण दाबी', 'welfare-claim-types.php' => 'दाबी प्रकार', 'help-center.php' => 'सहायता केन्द्र',
            ]],
            'sanstha' => ['label' => 'संस्था / सेटिङ', 'items' => [
                'service-centers.php' => 'सेवा कार्यालयहरू', 'institutional-profile.php' => 'संस्थागत प्रोफाइल',
                'institutional-welfare-opening.php' => 'राहत Opening', 'information-room.php' => 'Information Room',
                'notification-settings.php' => 'सूचना सेटिङ्स', 'ai-settings.php' => 'AI Chat सेटिङ्स',
                'notification-templates.php' => 'सूचना Templates', 'push-notifications.php' => 'Push Notifications',
                'member-of-year.php' => 'वर्षको सर्वश्रेष्ठ सदस्य', 'member-success-stories.php' => 'सदस्य सफलताका कथा',
                'about-settings.php' => 'बारेमा पृष्ठ', 'satisfaction-settings.php' => 'सन्तुष्टि Widget', 'settings.php' => 'सेटिङ्स',
            ]],
            'prawidhi' => ['label' => 'प्रविधि', 'items' => [
                'analytics.php' => 'Analytics', 'system-info.php' => 'प्रणाली जानकारी', 'update-checklist.php' => 'अपडेट सूची',
                'site-health.php' => 'साइट स्वास्थ्य', 'audit-log.php' => 'अडिट लग', 'error-log.php' => 'त्रुटि लग',
            ]],
        ];
    }
}

if (!function_exists('coop_perm_page_aliases')) {
    /** Sub-pages and admin/api endpoints → the menu whose permission they use. */
    function coop_perm_page_aliases(): array
    {
        return [
            'program-detail.php' => 'programs.php', 'program-occurrences.php' => 'programs.php',
            'program-member-history.php' => 'program-reports-consolidated.php',
            'program-reports-absent.php' => 'program-reports-consolidated.php',
            'program-reports-breakdown.php' => 'program-reports-consolidated.php',
            'program-reports-duplicates.php' => 'program-reports-consolidated.php',
            'program-reports-location.php' => 'program-reports-consolidated.php',
            'program-reports-member.php' => 'program-reports-consolidated.php',
            'information-room-browse.php' => 'information-room.php', 'information-room-logs.php' => 'information-room.php',
            'information-room-view.php' => 'information-room.php',
            'kyc-import-sample.php' => 'kyc-applications.php', 'member-import-sample.php' => 'member-import.php',
            /* admin/api */
            'program-desk-lookup.php' => 'program-registration-desk.php',
            'member-monthly-saving.php' => 'program-registration-desk.php',
        ];
    }
}

if (!function_exists('coop_perm_always_allowed')) {
    /** Pages every signed-in admin may open (own account, help, entry points, retired redirects). */
    function coop_perm_always_allowed(string $page): bool
    {
        static $pages = [
            'index.php', 'login.php', 'logout.php', 'dashboard.php', 'profile.php', 'change-password.php',
            'help-guide.php', 'site-license-blocked.php', 'quick-search.php',
        ];
        return in_array($page, $pages, true) || str_starts_with($page, 'hrm-'); /* hrm-* only redirect to dashboard */
    }
}

if (!function_exists('coop_perm_ensure_schema')) {
    function coop_perm_ensure_schema(PDO $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS admin_roles (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                name        VARCHAR(100) NOT NULL,
                description VARCHAR(255) NOT NULL DEFAULT '',
                permissions MEDIUMTEXT NULL,
                created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at  TIMESTAMP NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            if (function_exists('safeAddColumn')) {
                safeAddColumn($db, 'admin_users', 'custom_role_id', 'INT NULL DEFAULT NULL');
            } else {
                $has = $db->query("SHOW COLUMNS FROM admin_users LIKE 'custom_role_id'");
                if ($has && !$has->fetch()) {
                    $db->exec('ALTER TABLE admin_users ADD COLUMN custom_role_id INT NULL DEFAULT NULL');
                }
            }
        } catch (Throwable $e) {
            error_log('[admin-permissions] schema: ' . $e->getMessage());
        }
    }
}

if (!function_exists('coop_perm_normalize')) {
    /**
     * Raw (JSON string or array page => flags/array) → page => "vced" subset, registry pages only.
     * c / e / d imply v.
     *
     * @return array<string,string>
     */
    function coop_perm_normalize($raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }
        $valid = [];
        foreach (coop_perm_registry() as $g) {
            foreach ($g['items'] as $page => $_) {
                $valid[$page] = true;
            }
        }
        $out = [];
        foreach ($raw as $page => $flags) {
            if (!isset($valid[$page])) {
                continue;
            }
            $f = is_array($flags) ? implode('', array_keys(array_filter($flags))) : (string)$flags;
            $set = '';
            foreach (['v', 'c', 'e', 'd'] as $ch) {
                if (str_contains($f, $ch)) {
                    $set .= $ch;
                }
            }
            if ($set !== '' && !str_contains($set, 'v')) {
                $set = 'v' . $set;
            }
            if ($set !== '') {
                $out[$page] = $set;
            }
        }
        return $out;
    }
}

if (!function_exists('coop_perm_presets')) {
    /** Starting points for a new role (editable afterwards). @return array<string, array{label:string, perms:array<string,string>}> */
    function coop_perm_presets(): array
    {
        $all = static function (string $flags, array $groups = []) : array {
            $o = [];
            foreach (coop_perm_registry() as $gk => $g) {
                if ($groups && !in_array($gk, $groups, true)) {
                    continue;
                }
                foreach ($g['items'] as $page => $_) {
                    $o[$page] = $flags;
                }
            }
            return $o;
        };
        return [
            'editor' => ['label' => 'Editor — Content (सूचना, समाचार, ग्यालरी …)', 'perms' => array_merge($all('vce', ['samgri']), ['gallery.php' => 'vced', 'notices.php' => 'vced', 'news.php' => 'vced'])],
            'viewer' => ['label' => 'हेर्ने मात्र (सबै menu, परिवर्तन गर्न नमिल्ने)', 'perms' => $all('v')],
            'member_desk' => ['label' => 'सदस्य सेवा (सदस्य, KYM, आवेदन — हटाउन बाहेक)', 'perms' => $all('vce', ['sadasya', 'aavedan'])],
            'program_desk' => ['label' => 'कार्यक्रम / दर्ता डेस्क staff', 'perms' => ['program-dashboard.php' => 'v', 'programs.php' => 'v', 'program-registration-desk.php' => 'vce', 'program-attendance.php' => 'vce', 'program-reports-consolidated.php' => 'v']],
            'full' => ['label' => 'सबै menu मा सबै काम (superadmin मात्रका पेज बाहेक)', 'perms' => $all('vced')],
        ];
    }
}

if (!function_exists('coop_perm_current')) {
    /**
     * Custom role of the signed-in admin, or null (no custom role → legacy role rules).
     *
     * @return array{id:int, name:string, perms:array<string,string>}|null
     */
    function coop_perm_current(): ?array
    {
        static $cache = [];
        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        if ($adminId < 1 || !empty($_SESSION['is_superadmin'])) {
            return null;
        }
        if (array_key_exists($adminId, $cache)) {
            return $cache[$adminId];
        }
        $cache[$adminId] = null;
        if (!function_exists('getDB')) {
            return null;
        }
        try {
            $db = getDB();
            coop_perm_ensure_schema($db);
            $st = $db->prepare('SELECT r.id, r.name, r.permissions FROM admin_users u JOIN admin_roles r ON r.id = u.custom_role_id WHERE u.id = ? LIMIT 1');
            $st->execute([$adminId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $cache[$adminId] = ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'perms' => coop_perm_normalize($row['permissions'] ?? '')];
            }
        } catch (Throwable $e) {
            error_log('[admin-permissions] current: ' . $e->getMessage());
        }
        return $cache[$adminId];
    }
}

if (!function_exists('coop_perm_resolve_page')) {
    /** Script basename (+ print-form type) → registry menu page, or '' when not a grantable menu. */
    function coop_perm_resolve_page(string $script): string
    {
        $script = strtolower(basename($script));
        if ($script === 'print-form.php') {
            $map = [
                'kyc' => 'kyc-applications.php', 'loan' => 'loan-applications.php', 'account' => 'account-applications.php',
                'digital' => 'digital-service-requests.php', 'grievance' => 'grievances.php', 'job' => 'job-applications.php',
                'welfare' => 'welfare-claims.php', 'honor' => 'honor-applications.php', 'membership' => 'membership-applications.php',
            ];
            return $map[strtolower((string)($_GET['type'] ?? ''))] ?? '';
        }
        $aliases = coop_perm_page_aliases();
        if (isset($aliases[$script])) {
            return $aliases[$script];
        }
        foreach (coop_perm_registry() as $g) {
            if (isset($g['items'][$script])) {
                return $script;
            }
        }
        return '';
    }
}

if (!function_exists('coop_perm_post_action')) {
    /**
     * Classify the current POST as c (create) / e (edit) / d (delete) from its field names and
     * action values. Unsure → e (edit). Used only for custom-role users.
     */
    function coop_perm_post_action(): string
    {
        $words = [];
        foreach ($_POST as $k => $v) {
            $k = strtolower((string)$k);
            if ($k === 'csrf_token') {
                continue;
            }
            $words[] = $k;
            if (in_array($k, ['action', 'do', 'op', 'bulk_action', 'mode'], true) && is_scalar($v)) {
                $words[] = strtolower((string)$v);
            }
        }
        $hay = ' ' . implode(' ', $words) . ' ';
        if (preg_match('/[\s_](delete|remove|trash|purge|destroy)(?=[\s_])/', $hay)) {
            return 'd';
        }
        if (preg_match('/[\s_](add|create|insert|new)(?=[\s_])/', $hay)) {
            return 'c';
        }
        /* save without an existing id = create (e.g. action=save&id=0, save_notice&notice_id=0) */
        if (preg_match('/[\s_](save)(?=[\s_])/', $hay)) {
            $idKeys = ['id'];
            foreach (array_keys($_POST) as $k) {
                if (preg_match('/^save_([a-z]+)$/', strtolower((string)$k), $m)) {
                    $idKeys[] = $m[1] . '_id';
                }
            }
            foreach ($idKeys as $ik) {
                if (array_key_exists($ik, $_POST)) {
                    return ((int)$_POST[$ik] > 0) ? 'e' : 'c';
                }
            }
        }
        return 'e';
    }
}

if (!function_exists('coop_perm_can')) {
    /** For custom-role users: may they do $flag (v|c|e|d) on menu $page? Non-custom users: true. */
    function coop_perm_can(string $page, string $flag = 'v'): bool
    {
        $cur = coop_perm_current();
        if ($cur === null) {
            return true;
        }
        return $page !== '' && str_contains($cur['perms'][$page] ?? '', $flag);
    }
}

if (!function_exists('coop_perm_current_request_allowed')) {
    /**
     * Matrix decision for the running script. Always-allowed pages pass for view only.
     *
     * @return array{ok:bool, flag:string, page:string}
     */
    function coop_perm_current_request_allowed(): array
    {
        $script = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
        $page = coop_perm_resolve_page($script);
        $isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
        $flag = $isPost ? coop_perm_post_action() : 'v';
        if ($page === '') {
            /* own account pages: change-password / profile POST are fine too */
            return ['ok' => coop_perm_always_allowed($script), 'flag' => $flag, 'page' => $script];
        }
        return ['ok' => coop_perm_can($page, $flag), 'flag' => $flag, 'page' => $page];
    }
}

if (!function_exists('coop_perm_enforce_request')) {
    /** Called from admin/includes/admin-page-boot.php after login. No-op for users without a custom role. */
    function coop_perm_enforce_request(): void
    {
        if (coop_perm_current() === null) {
            return;
        }
        $r = coop_perm_current_request_allowed();
        if ($r['ok']) {
            return;
        }
        $what = coop_perm_actions()[$r['flag']] ?? 'हेर्ने';
        if (function_exists('setFlash')) {
            setFlash('error', 'तपाईंको भूमिकामा यो काम (' . $what . ') गर्ने अनुमति छैन। आवश्यक भए superadmin लाई भन्नुहोस्।');
        }
        $back = 'dashboard.php';
        if ($r['flag'] !== 'v' && coop_perm_can($r['page'], 'v')) {
            $back = $r['page'];   /* could view, just not change — return to the same menu */
        }
        $isAjax = !empty($_GET['ajax']) || !empty($_POST['ajax'])
            || stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
        if ($isAjax) {
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'error' => 'अनुमति छैन (' . $what . ')'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Location: ' . (defined('ADMIN_URL') ? ADMIN_URL : '') . $back);
        exit;
    }
}

if (!function_exists('coop_perm_allowed_menu_pages')) {
    /** For the sidebar filter: menu pages the custom-role user may view (null = no filtering). */
    function coop_perm_allowed_menu_pages(): ?array
    {
        $cur = coop_perm_current();
        if ($cur === null) {
            return null;
        }
        $pages = array_keys(array_filter($cur['perms'], static fn ($f) => str_contains($f, 'v')));
        return array_values(array_merge($pages, ['dashboard.php', 'profile.php', 'change-password.php', 'help-guide.php', 'logout.php']));
    }
}
