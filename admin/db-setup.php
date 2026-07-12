<?php
/**
 * =====================================================
 * ADMIN: DB SETUP — Superadmin Only
 * =====================================================
 * ⚠️ VIEW ONLY FROM ADMIN PANEL
 * Database credentials edit garnu cPanel File Manager Bata garnus!
 *
 * Edit via cPanel:
 *   File Manager → public_html/includes/database.local.php
 * =====================================================
 */

$pageTitle  = 'DB Setup';
$currentPage = 'db-setup';

/* ════════════════════════════════════════════════════════════════
 * BOOTSTRAP MODE — DB connect हुन सकेन वा admin user छैन भने
 * login bypass — unlock: database.local.php भरिएको वा superadmin-config फर्म
 * ════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/superadmin-config.php';

$db = null;
$dbError = '';
try {
    $db = getDB();
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$adminDbCredPath = dirname(__DIR__) . '/includes/database.local.php';
$adminDbCredDir  = dirname(__DIR__) . '/includes';
$adminDbCredWritable = (is_file($adminDbCredPath) && is_writable($adminDbCredPath))
    || (!is_file($adminDbCredPath) && is_writable($adminDbCredDir));

$bootstrapMode = false;
if (DB_NAME === '' || $db === null) {
    $bootstrapMode = true;
} else {
    /* DB connect भयो — admin_users table छ कि छैन र कुनै admin छ कि छैन check गर */
    try {
        $cnt = (int)$db->query("SELECT COUNT(*) FROM admin_users")->fetchColumn();
        if ($cnt === 0) $bootstrapMode = true;
    } catch (Throwable $e) {
        $bootstrapMode = true;  // table छैन — fresh install
    }
}

$legacyDbCredPath = dirname(__DIR__) . '/includes/database.php';
/** cPanel मा DB details भरिसकेको local फाइल — यो भए DB Setup सिधै खुल्छ */
$dbCredentialsFileOnServer =
    (is_file($adminDbCredPath) && is_readable($adminDbCredPath))
    || (is_file($legacyDbCredPath) && is_readable($legacyDbCredPath));
$bootstrapAutoUnlockFromDbFile = false;

$bootstrapFileSuperUnlock =
    defined('SUPER_ADMIN_INITIAL_PASSWORD')
    && (string) SUPER_ADMIN_INITIAL_PASSWORD !== ''
    && defined('SUPER_ADMIN_USERNAME')
    && trim((string) SUPER_ADMIN_USERNAME) !== '';

$bootstrapSetupUnlocked = !$bootstrapMode;
/** @var null|'missing_local_config'|'need_superadmin_login' $bootstrapGateReason */
$bootstrapGateReason = null;

if ($bootstrapMode) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (function_exists('generateCSRFToken')) {
        generateCSRFToken();
    }

    /* फाइल-superadmin user/pass — DB अघि नै bootstrap unlock (तपाईंको install flow) */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bootstrap_unlock_superadmin') {
        if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            $_SESSION['bootstrap_unlock_flash'] = ['error', 'सुरक्षा जाँच असफल। पृष्ठ refresh गरेर पुनः प्रयास गर्नुहोस्।'];
        } else {
            $u = trim((string) ($_POST['su_username'] ?? ''));
            $p = (string) ($_POST['su_password'] ?? '');
            if ($bootstrapFileSuperUnlock
                && hash_equals((string) SUPER_ADMIN_USERNAME, $u)
                && hash_equals((string) SUPER_ADMIN_INITIAL_PASSWORD, $p)) {
                $_SESSION['db_bootstrap_unlocked'] = true;
                if (function_exists('session_regenerate_id')) {
                    @session_regenerate_id(true);
                }
                $_SESSION['bootstrap_unlock_flash'] = ['success', 'Unlock भयो — अब DB credentials save गरी install.sql चलाउन सक्नुहुन्छ।'];
            } else {
                $_SESSION['bootstrap_unlock_flash'] = ['error', 'युजरनेम वा पासवर्ड मिलेन। `includes/superadmin-config.local.php` जाँच गर्नुहोस्।'];
            }
        }
        header('Location: ' . (defined('ADMIN_URL') ? ADMIN_URL : '/admin/') . 'db-setup.php', true, 302);
        exit();
    }

    if (!empty($_SESSION['db_bootstrap_unlocked'])) {
        $bootstrapSetupUnlocked = true;
    } elseif (!empty($_SESSION['admin_local_debug_login'])) {
        $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if (str_starts_with($host, '127.0.0.1') || str_starts_with($host, 'localhost') || str_starts_with($host, '[::1]')) {
            $bootstrapSetupUnlocked = true;
        }
    } elseif ($bootstrapFileSuperUnlock) {
        $bootstrapGateReason = 'need_superadmin_login';
    } else {
        $bootstrapGateReason = 'missing_local_config';
    }

    if ($bootstrapSetupUnlocked) {
        define('IS_ADMIN_PAGE', true);
        define('BOOTSTRAP_MODE', true);
        $csrfToken = function_exists('generateCSRFToken') ? generateCSRFToken() : bin2hex(random_bytes(32));
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = $csrfToken;
        }
    }
} else {
    /* Normal mode — full admin-header + superadmin check */
    require_once 'includes/admin-header.php';
    if (empty($_SESSION['is_superadmin'])) {
        setFlash('error', 'यो page केवल Superadmin ले access गर्न सक्छ।');
        redirect('dashboard.php');
        exit();
    }
}

$lockFile    = dirname(__DIR__) . '/.setup.lock';
$setupLocked = file_exists($lockFile);
$sqlFile     = dirname(__DIR__) . '/database/install.sql';
$sqlExists   = file_exists($sqlFile);

/* ── database/ folder का सबै SQL files ── */
$dbFolderPath = dirname(__DIR__) . '/database';
$serverSqlFiles = [];
if (is_dir($dbFolderPath)) {
    foreach (glob($dbFolderPath . '/*.sql') as $f) {
        $serverSqlFiles[] = [
            'name'    => basename($f),
            'path'    => $f,
            'size'    => filesize($f),
            'mtime'   => filemtime($f),
        ];
    }
    usort($serverSqlFiles, fn($a,$b) => $b['mtime'] - $a['mtime']);
}

/* ── All tables to check ── */
$allTables = [
    'admin_users'              => 'Admin प्रयोगकर्ता',
    'site_settings'            => 'Site Settings',
    'notices'                  => 'सूचनाहरू',
    'election_cycles'          => 'निर्वाचन चक्र',
    'election_milestones'      => 'निर्वाचन कार्यतालिका',
    'news'                     => 'समाचार',
    'sliders'                  => 'Slider',
    'gallery'                  => 'Gallery',
    'services'                 => 'सेवाहरू',
    'interest_rates'           => 'ब्याज दर',
    'pages'                    => 'पृष्ठहरू',
    'downloads'                => 'डाउनलोड',
    'faqs'                     => 'FAQs',
    'useful_links'             => 'उपयोगी Links',
    'team_members'             => 'टोली',
    'committee_types'          => 'समिति',
    'careers'                  => 'रोजगारी',
    'job_applications'         => 'जागिर आवेदन',
    'kyc_applications'         => 'KYC आवेदन',
    'loan_applications'        => 'ऋण आवेदन',
    'account_applications'     => 'खाता आवेदन',
    'contact_messages'         => 'सन्देश',
    'member_feedback'          => 'सुझाव',
    'grievances'               => 'गुनासो',
    'appointments'             => 'भेटघाट',
    'member_welfare_claims'    => 'कल्याण दाबी',
    'auction_notices'          => 'लिलामी',
    'activity_log'             => 'Activity Log',
];

$tableStatus = [];
$tablesFound = 0;
if ($db) {
    $tblKeys = array_keys($allTables);
    try {
        $inList = implode(',', array_map(static fn (string $t): string => $db->quote($t), $tblKeys));
        $sql = 'SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (' . $inList . ')';
        $found = $db->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($tblKeys as $t) {
            $exists = in_array($t, $found, true);
            $tableStatus[$t] = $exists;
            if ($exists) {
                $tablesFound++;
            }
        }
    } catch (Throwable $e) {
        foreach ($allTables as $tbl => $label) {
            try {
                $r = $db->query("SHOW TABLES LIKE " . $db->quote($tbl));
                $exists = ($r && $r->fetch() !== false);
                $tableStatus[$tbl] = $exists;
                if ($exists) {
                    $tablesFound++;
                }
            } catch (Exception $e2) {
                $tableStatus[$tbl] = false;
            }
        }
    }
}
$tablesMissing = count($allTables) - $tablesFound;
$allowDangerousSqlRunner = defined('ALLOW_DANGEROUS_DB_SETUP_SQL') && ALLOW_DANGEROUS_DB_SETUP_SQL === true;

/* Bootstrap gate: unlock बाहेक कुनै POST चलाउन दिँदैन */
if ($bootstrapMode && !$bootstrapSetupUnlocked && $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') !== 'bootstrap_unlock_superadmin') {
    header('Location: ' . (defined('ADMIN_URL') ? ADMIN_URL : '/admin/') . 'db-setup.php', true, 302);
    exit();
}

/* ══════════════════════
   POST HANDLERS
══════════════════════ */
$actionResult    = '';
$actionResultOk  = false;

/* ── v2: Reset schema lock files (force re-run of ensure-tables) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'reset_schema_locks'
    && verifyCSRFToken()) {
    $root = dirname(__DIR__);
    $cleared = 0;
    foreach (['.schema.lock', '.member-schema.lock', '.admin-schema.lock'] as $lf) {
        $p = $root . '/' . $lf;
        if (file_exists($p) && @unlink($p)) $cleared++;
    }
    logSecurityEvent('schema_locks_reset', "Cleared {$cleared} schema lock files.");
    setFlash('success', "✅ {$cleared} schema lock files cleared। अर्को page load मा schema verify हुन्छ।");
    redirect('db-setup.php');
    exit();
}

/* ── Upload action — DB connection आवश्यक छैन — अलग्गै handle गर्ने ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'upload_to_db_folder'
    && verifyCSRFToken()) {

    $file = $_FILES['new_sql_file'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        setFlash('error', 'File छान्नुहोस् र पुनः प्रयास गर्नुहोस्।');
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'sql') {
            setFlash('error', 'केवल .sql file मात्र upload गर्न मिल्छ।');
        } elseif ($file['size'] > 50 * 1024 * 1024) {
            setFlash('error', 'File 50MB भन्दा बढी छ।');
        } else {
            $safeName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', basename($file['name']));
            $safeName = preg_replace('/\.sql$/i', '', $safeName) . '.sql';

            if (!is_dir($dbFolderPath)) {
                mkdir($dbFolderPath, 0755, true);
            }
            $dest = $dbFolderPath . '/' . $safeName;
            if (file_exists($dest)) {
                $safeName = pathinfo($safeName, PATHINFO_FILENAME) . '_' . date('Ymd_His') . '.sql';
                $dest = $dbFolderPath . '/' . $safeName;
            }
            if (move_uploaded_file($file['tmp_name'], $dest)) {
                logSecurityEvent('sql_file_uploaded', 'SQL file saved to database/ folder: ' . $safeName);
                setFlash('success', '"' . htmlspecialchars($safeName) . '" database/ folder मा save भयो! तल list मा देखिन्छ — Run गर्नुहोस्।');
            } else {
                setFlash('error', 'File save हुन सकेन। database/ folder को permission 755 छ कि छैन check गर्नुहोस्।');
            }
        }
    }
    redirect('db-setup.php');
    exit();
}

/* ── DB Credentials Update — DB connection आवश्यक छैन ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'update_db_credentials'
    && verifyCSRFToken()) {

    $newHost    = trim($_POST['db_host']     ?? '');
    $newName    = trim($_POST['db_name']     ?? '');
    $newUser    = trim($_POST['db_user']     ?? '');
    $newPass    = $_POST['db_pass']           ?? '';
    $newSiteUrl = rtrim(trim($_POST['site_url'] ?? ''), '/') . '/';

    // Form मा password echo गर्दैनौं — खाली = अहिलेको DB_PASS राख्ने (multi-project / HTML leak safe)
    if ($newPass === '' && defined('DB_PASS')) {
        $newPass = (string) DB_PASS;
    }

    if (empty($newHost) || empty($newName) || empty($newUser)) {
        setFlash('error', 'Host, DB Name र DB User अनिवार्य छ।');
        redirect('db-setup.php');
        exit();
    }

    $incDir     = dirname(__DIR__) . '/includes';
    $dbFilePath = $incDir . '/database.local.php';
    $newContent = "<?php\n/**\n * DB credentials — Admin DB Setup (" . date('Y-m-d H:i:s') . ")\n"
        . " * Gitignored — cPanel git pull safe\n */\n\n"
        . "if (!defined('DB_HOST')) define('DB_HOST', " . var_export($newHost, true) . ");\n"
        . "if (!defined('DB_NAME')) define('DB_NAME', " . var_export($newName, true) . ");\n"
        . "if (!defined('DB_USER')) define('DB_USER', " . var_export($newUser, true) . ");\n"
        . "if (!defined('DB_PASS')) define('DB_PASS', " . var_export($newPass, true) . ");\n\n"
        . "if (!defined('SITE_URL')) {\n    define('SITE_URL', " . var_export($newSiteUrl, true) . ");\n}\n";

    $canWrite = (is_file($dbFilePath) && is_writable($dbFilePath))
        || (!is_file($dbFilePath) && is_writable($incDir));
    if (!$canWrite) {
        setFlash('error', 'includes/database.local.php write गर्न सकिएन। includes/ folder writable (755) र file 644 check गर्नुहोस्।');
    } elseif (file_put_contents($dbFilePath, $newContent) !== false) {
        logSecurityEvent('db_credentials_updated', 'DB credentials saved to database.local.php from admin panel');
        setFlash('success', '✅ Database credentials `includes/database.local.php` मा save भयो! अब Migration Runner चलाउनुहोस्।');
    } else {
        setFlash('error', 'database.local.php save हुन सकेन। cPanel File Manager बाट manually बनाउनुहोस्।');
    }
    redirect('db-setup.php');
    exit();
}

/* ── Setup Lock Toggle — DB connection आवश्यक छैन ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'toggle_lock'
    && verifyCSRFToken()) {
    if ($setupLocked) {
        @unlink($lockFile);
        setFlash('info', 'setup.php unlock भयो।');
    } else {
        file_put_contents($lockFile, date('Y-m-d H:i:s') . ' — Locked by Superadmin from admin panel');
        setFlash('success', 'setup.php lock भयो।');
    }
    redirect('db-setup.php');
    exit();
}

require_once __DIR__ . '/includes/sql-utils.php'; /* splitSqlStatements() — shared utility */

/* ─────────────────────────────────────────────────────────────────────
 * execStatement — v10.3 FIX
 * "Cannot execute queries while there are pending result sets" बाट बच्न।
 *
 * MySQL CALL stored_procedure() ले inner SELECTs बाट rowsets return गर्छ।
 * PDO::exec() ले ती rowsets consume गर्दैन — फलस्वरूप अर्को statement fail हुन्छ।
 * Fix: CALL/SELECT जस्ता rowset-returning statements लाई query() ले run गर्ने,
 *      ani sabai pending rowsets nextRowset() le drain garne, ani closeCursor()।
 * ─────────────────────────────────────────────────────────────────────*/
if (!function_exists('execStatement')) {
    function execStatement(\PDO $db, string $stmt): void {
        $head = ltrim($stmt);
        // Rowset-returning statements (CALL / SELECT / SHOW / EXPLAIN) — query() सँग drain
        if (preg_match('/^\s*(CALL|SELECT|SHOW|EXPLAIN|DESCRIBE|DESC)\b/i', $head)) {
            $s = $db->query($stmt);
            if ($s) {
                // सबै pending rowsets drain गर्ने
                do {
                    try { $s->fetchAll(\PDO::FETCH_ASSOC); } catch (\Throwable $e) { /* no rows */ }
                } while ($s->nextRowset());
                $s->closeCursor();
            }
        } else {
            $db->exec($stmt);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $db) {

    $action = $_POST['action'] ?? '';
    $dangerousActions = ['reset_rebuild_testing', 'run_uploaded_sql', 'run_server_sql'];

    if (in_array($action, $dangerousActions, true) && !$allowDangerousSqlRunner) {
        $actionResult = '<div class="alert alert-danger"><i class="fas fa-ban me-2"></i>Security mode: यो SQL action default मा बन्द छ। आवश्यक परे अस्थायी रूपमा <code>ALLOW_DANGEROUS_DB_SETUP_SQL</code> true गर्नुहोस्।</div>';
    }

    /* ── ०. TESTING HARD RESET: पुरानो data+tables drop गरेर fresh rebuild ── */
    elseif ($action === 'reset_rebuild_testing') {
        $confirmText = trim((string)($_POST['confirm_text'] ?? ''));
        if ($confirmText !== 'RESET TEST DB') {
            $actionResult = '<div class="alert alert-danger"><i class="fas fa-ban me-2"></i>Confirmation text mismatch। <code>RESET TEST DB</code> ठीक टाइप गर्नुहोस्।</div>';
        } elseif (!$sqlExists) {
            $actionResult = '<div class="alert alert-danger"><i class="fas fa-ban me-2"></i><code>database/install.sql</code> नभएकोले reset पछि rebuild गर्न सकिँदैन।</div>';
        } else {
            try {
                $db->beginTransaction();
                $db->exec("SET FOREIGN_KEY_CHECKS=0");
                $tables = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) ?: [];
                foreach ($tables as $t) {
                    $db->exec("DROP TABLE IF EXISTS `" . str_replace('`', '``', $t) . "`");
                }
                $db->exec("SET FOREIGN_KEY_CHECKS=1");
                $db->commit();

                /* Schema lock files पनि हटाउने ताकि ensure scripts पुनः verify गरून् */
                $root = dirname(__DIR__);
                foreach (['.schema.lock', '.member-schema.lock', '.admin-schema.lock'] as $lf) {
                    $p = $root . '/' . $lf;
                    if (file_exists($p)) @unlink($p);
                }

                /* Fresh rebuild from install.sql */
                $sql = file_get_contents($sqlFile);
                $parts = splitSqlStatements($sql);
                $ok = 0; $skipped = 0; $errors = [];
                foreach ($parts as $stmt) {
                    if (preg_match('/^\s*(CREATE\s+DATABASE|USE\s+\w)/i', $stmt)) { $skipped++; continue; }
                    try { execStatement($db, $stmt); $ok++; }
                    catch (\PDOException $e) {
                        $msg = $e->getMessage();
                        if (stripos($msg,'Duplicate column')!==false || stripos($msg,'already exists')!==false || stripos($msg,'Duplicate key')!==false) {
                            $skipped++;
                        } else {
                            $errors[] = htmlspecialchars($msg);
                        }
                    }
                }
                if (empty($errors)) {
                    $actionResultOk = true;
                    $actionResult = '<div class="alert alert-success"><i class="fas fa-check-circle fa-lg me-2"></i><strong>Testing DB hard reset सफल!</strong> पुरानो data/table drop गरेर fresh schema rebuild भयो। '
                        . $ok . ' statements run भए' . ($skipped ? ' (' . $skipped . ' skip)' : '') . '.</div>';
                    logSecurityEvent('db_hard_reset_testing', 'All tables dropped and rebuilt from install.sql');
                } else {
                    $actionResult = '<div class="alert alert-danger"><i class="fas fa-times-circle fa-lg me-2"></i><strong>Reset पछिको rebuild मा errors आए:</strong><ul class="mb-0 small mt-2">'
                        . implode('', array_map(fn($e) => '<li>'.$e.'</li>', $errors)) . '</ul></div>';
                }
            } catch (Throwable $e) {
                if ($db->inTransaction()) $db->rollBack();
                try { $db->exec("SET FOREIGN_KEY_CHECKS=1"); } catch (Throwable $ie) {}
                $actionResult = '<div class="alert alert-danger"><i class="fas fa-times-circle me-2"></i>Hard reset असफल: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
    }

    /* ── १. install.sql run गर्ने ── */
    elseif ($action === 'run_full_setup') {
        if (!$sqlExists) {
            $actionResult   = '<div class="alert alert-danger"><i class="fas fa-ban me-2"></i><code>database/install.sql</code> file भेटिएन।</div>';
        } else {
            $sql = file_get_contents($sqlFile);
            try {
                $parts = splitSqlStatements($sql);

                $ok = 0; $skipped = 0; $errors = []; $notices = [];
                foreach ($parts as $stmt) {
                    if (preg_match('/^\s*(CREATE\s+DATABASE|USE\s+\w)/i', $stmt)) {
                        $skipped++; continue;
                    }
                    try { execStatement($db, $stmt); $ok++; }
                    catch (\PDOException $e) {
                        $msg = $e->getMessage();
                        if (stripos($msg,'Duplicate column')!==false || stripos($msg,'already exists')!==false || stripos($msg,'Duplicate key')!==false) {
                            $notices[] = htmlspecialchars($msg); $skipped++;
                        } else {
                            $errors[] = htmlspecialchars($msg);
                        }
                    }
                }
                if (empty($errors)) {
                    $actionResultOk = true;
                    $actionResult = '<div class="alert alert-success"><i class="fas fa-check-circle fa-lg me-2"></i>'
                        . '<strong>Database Setup सम्पन्न!</strong> '
                        . $ok . ' statements run भए।'
                        . ($skipped > 0 ? ' (' . $skipped . ' skip — already exist)' : '')
                        . '</div>';
                    logSecurityEvent('db_setup_run', 'Full DB setup run from db-setup.php by superadmin');
                    /* Lock file बनाउने */
                    if (!$setupLocked) {
                        file_put_contents($lockFile, date('Y-m-d H:i:s') . ' — DB Setup by Superadmin (admin panel)');
                        $setupLocked = true;
                    }
                    /* Table status refresh */
                    foreach ($allTables as $tbl => $label) {
                        try {
                            $r = $db->query("SHOW TABLES LIKE " . $db->quote($tbl));
                            $tableStatus[$tbl] = ($r && $r->fetch() !== false);
                        } catch (Exception $e) { $tableStatus[$tbl] = false; }
                    }
                    $tablesFound = count(array_filter($tableStatus));
                    $tablesMissing = count($allTables) - $tablesFound;
                } else {
                    $actionResult = '<div class="alert alert-danger"><i class="fas fa-times-circle fa-lg me-2"></i>'
                        . '<strong>केही errors आए:</strong><ul class="mb-0 small mt-2">'
                        . implode('', array_map(fn($e) => '<li>' . $e . '</li>', $errors))
                        . '</ul>' . ($ok > 0 ? '<small class="text-muted d-block mt-2">' . $ok . ' statements OK थिए।</small>' : '') . '</div>';
                }
            } catch (Exception $e) {
                $actionResult = '<div class="alert alert-danger"><i class="fas fa-times-circle me-2"></i>Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
    }

    /* ── २. Core tables मात्र बनाउने (admin_users + site_settings) ── */
    elseif ($action === 'create_core_tables') {
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS admin_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) NOT NULL UNIQUE,
                password VARCHAR(255) NOT NULL DEFAULT '',
                full_name VARCHAR(100) NOT NULL,
                email VARCHAR(100),
                role ENUM('super_admin','admin','editor') DEFAULT 'admin',
                is_active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_login TIMESTAMP NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->exec("CREATE TABLE IF NOT EXISTS site_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                setting_key VARCHAR(100) NOT NULL UNIQUE,
                setting_value TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $db->exec("CREATE TABLE IF NOT EXISTS activity_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                admin_id VARCHAR(50),
                action VARCHAR(100),
                description TEXT,
                ip_address VARCHAR(45),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            $actionResultOk = true;
            $actionResult = '<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><strong>Core tables बनाइयो!</strong> (admin_users, site_settings, activity_log)</div>';
            logSecurityEvent('core_tables_created', 'Core tables created from admin panel by superadmin');

            foreach (['admin_users','site_settings','activity_log'] as $tbl) {
                $tableStatus[$tbl] = true;
            }
            $tablesFound = count(array_filter($tableStatus));
            $tablesMissing = count($allTables) - $tablesFound;
        } catch (Exception $e) {
            $actionResult = '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }

    /* ── ३. SQL Upload गरेर run गर्ने ── */
    elseif ($action === 'run_uploaded_sql') {
        if (empty($_FILES['sql_file']) || $_FILES['sql_file']['error'] !== UPLOAD_ERR_OK) {
            $actionResult = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-2"></i>.sql file छान्नुहोस्।</div>';
        } else {
            $file = $_FILES['sql_file'];
            $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext !== 'sql') {
                $actionResult = '<div class="alert alert-danger">केवल <strong>.sql</strong> file मात्र।</div>';
            } elseif ($file['size'] > 25 * 1024 * 1024) {
                $actionResult = '<div class="alert alert-danger">File 25MB भन्दा बढी छ।</div>';
            } else {
                $sql = file_get_contents($file['tmp_name']);
                try {
                    $parts = splitSqlStatements($sql);
                    $ok = 0; $skipped = 0; $errors = [];
                    foreach ($parts as $stmt) {
                        if (preg_match('/^\s*(CREATE\s+DATABASE|USE\s+\w)/i', $stmt)) { $skipped++; continue; }
                        try { execStatement($db, $stmt); $ok++; }
                        catch (\PDOException $e) {
                            $msg = $e->getMessage();
                            if (stripos($msg,'Duplicate column')!==false || stripos($msg,'already exists')!==false) { $skipped++; }
                            else { $errors[] = htmlspecialchars($msg); }
                        }
                    }
                    if (empty($errors)) {
                        $actionResultOk = true;
                        $actionResult = '<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>'
                            . '<strong>' . htmlspecialchars($file['name']) . ' सफलतापूर्वक run भयो!</strong> '
                            . $ok . ' statements execute भए।' . ($skipped > 0 ? ' (' . $skipped . ' skip)' : '') . '</div>';
                        logSecurityEvent('sql_upload_run', 'Uploaded SQL run from db-setup: ' . $file['name']);
                    } else {
                        $actionResult = '<div class="alert alert-danger"><i class="fas fa-times-circle me-2"></i>'
                            . '<strong>Errors (' . count($errors) . '):</strong><ul class="mb-0 small mt-1">'
                            . implode('', array_map(fn($e) => '<li>' . $e . '</li>', $errors))
                            . '</ul></div>';
                    }
                } catch (Exception $e) {
                    $actionResult = '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
                }
            }
        }
    }

    /* ── ४. Server SQL File Run गर्ने (database/ folder बाट) ── */
    elseif ($action === 'run_server_sql') {
        $reqFile = $_POST['sql_filename'] ?? '';
        /* Path traversal / symlink escape बाट जोगाउने — basename + realpath `database/` भित्र मात्र */
        $safeFile = basename((string) $reqFile);
        $filePath = $dbFolderPath . '/' . $safeFile;
        $realBase = @realpath($dbFolderPath);
        $realFile = @is_file($filePath) ? @realpath($filePath) : false;

        if (empty($safeFile) || pathinfo($safeFile, PATHINFO_EXTENSION) !== 'sql') {
            $actionResult = '<div class="alert alert-danger"><i class="fas fa-ban me-2"></i>गलत file। केवल .sql files मात्र।</div>';
        } elseif ($realBase === false || $realFile === false || !str_starts_with($realFile, $realBase . DIRECTORY_SEPARATOR)) {
            $actionResult = '<div class="alert alert-danger"><i class="fas fa-ban me-2"></i><code>' . htmlspecialchars($safeFile, ENT_QUOTES, 'UTF-8') . '</code> — अनुमति छैन वा file भेटिएन।</div>';
        } else {
            $sql = file_get_contents($filePath);
            try {
                $parts = splitSqlStatements($sql);

                $ok = 0; $skipped = 0; $errors = [];
                foreach ($parts as $stmt) {
                    if (preg_match('/^\s*(CREATE\s+DATABASE|USE\s+\w)/i', $stmt)) { $skipped++; continue; }
                    try { execStatement($db, $stmt); $ok++; }
                    catch (\PDOException $e) {
                        $msg = $e->getMessage();
                        if (stripos($msg,'Duplicate column')!==false || stripos($msg,'already exists')!==false || stripos($msg,'Duplicate key')!==false) {
                            $skipped++;
                        } else {
                            $errors[] = htmlspecialchars($msg);
                        }
                    }
                }
                if (empty($errors)) {
                    $actionResultOk = true;
                    $actionResult = '<div class="alert alert-success"><i class="fas fa-check-circle fa-lg me-2"></i>'
                        . '<strong><code>' . htmlspecialchars($safeFile) . '</code> सफलतापूर्वक run भयो!</strong> '
                        . $ok . ' statements execute भए।'
                        . ($skipped > 0 ? ' (' . $skipped . ' skip — already exist)' : '')
                        . '</div>';
                    logSecurityEvent('server_sql_run', 'Server SQL file run from db-setup: ' . $safeFile);
                    /* Table status refresh */
                    foreach ($allTables as $tbl => $label) {
                        try {
                            $r = $db->query("SHOW TABLES LIKE " . $db->quote($tbl));
                            $tableStatus[$tbl] = ($r && $r->fetch() !== false);
                        } catch (Exception $e) { $tableStatus[$tbl] = false; }
                    }
                    $tablesFound   = count(array_filter($tableStatus));
                    $tablesMissing = count($allTables) - $tablesFound;
                } else {
                    $actionResult = '<div class="alert alert-danger"><i class="fas fa-times-circle fa-lg me-2"></i>'
                        . '<strong>Errors (' . count($errors) . '):</strong>'
                        . '<ul class="mb-0 small mt-2">'
                        . implode('', array_map(fn($e) => '<li>' . $e . '</li>', $errors))
                        . '</ul>'
                        . ($ok > 0 ? '<small class="text-muted d-block mt-2">' . $ok . ' statements OK थिए।</small>' : '')
                        . '</div>';
                }
            } catch (Exception $e) {
                $actionResult = '<div class="alert alert-danger">Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
    }

    /* update_db_credentials र toggle_lock अब माथि DB-independent block मा handle हुन्छ */
}

/* ── Bootstrap: unlock नभएसम्म — सुरक्षित locked UI ── */
if ($bootstrapMode && !$bootstrapSetupUnlocked) {
    header('Content-Type: text/html; charset=UTF-8');
    $flash = $_SESSION['bootstrap_unlock_flash'] ?? null;
    unset($_SESSION['bootstrap_unlock_flash']);
    ?>
<!DOCTYPE html>
<html lang="ne">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DB Setup — Locked</title>
<link href="assets/vendor/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light p-4">
<div class="container dbs-bootstrap-wrap">
  <h5 class="mb-3">DB Setup — पहिलो पटक unlock</h5>
  <?php if (is_array($flash) && count($flash) >= 2): ?>
  <div class="alert alert-<?php echo $flash[0] === 'success' ? 'success' : 'danger'; ?> small py-2"><?php echo htmlspecialchars((string) $flash[1], ENT_QUOTES, 'UTF-8'); ?></div>
  <?php endif; ?>

  <?php if ($bootstrapGateReason === 'need_superadmin_login'): ?>
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
      <p class="small text-muted mb-3">
        <strong>तपाईंको मुख्य flow:</strong> <code>includes/superadmin-config.local.php</code> मा राखेको
        Superadmin username र password (plaintext — login sync ले DB hash मिलाउँछ) यहाँ भर्नुहोस्।
        Unlock पछि तल credentials save गरी <code>install.sql</code> चलाउनुहोस्; अनि <code>admin/index.php</code> बाट login।
      </p>
      <form method="post" action="">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="bootstrap_unlock_superadmin">
        <div class="mb-2">
          <label class="form-label small mb-0">Username</label>
          <input type="text" name="su_username" class="form-control form-control-sm" required autocomplete="username">
        </div>
        <div class="mb-3">
          <label class="form-label small mb-0">Password</label>
          <input type="password" name="su_password" class="form-control form-control-sm" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn btn-primary btn-sm w-100">Unlock गरी DB Setup खोल्नुहोस्</button>
      </form>
    </div>
  </div>

  <?php else: /* missing_local_config */ ?>
  <div class="alert alert-danger small mb-0">
    <p class="mb-2"><strong>पहिले यी दुई local फाइल बनाउनुहोस्</strong> (example बाट copy, same <code>includes/</code> folder):</p>
    <ol class="mb-0 ps-3">
      <li><code>database.local.php.example</code> → <code>database.local.php</code> — <code>DB_HOST</code>, <code>DB_NAME</code>, <code>DB_USER</code>, <code>DB_PASS</code> भर्नुहोस्।</li>
      <li><code>superadmin-config.local.php.example</code> → <code>superadmin-config.local.php</code> — superadmin username र password।</li>
    </ol>
    <p class="mb-0 mt-2 small text-muted">Save गरेपछि यो पृष्ठ refresh गर्नुहोस्।</p>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
    <?php
    exit();
}

/* ── Bootstrap mode मा minimal HTML shell render ── */
if (defined('BOOTSTRAP_MODE') && BOOTSTRAP_MODE):
?>
<!DOCTYPE html>
<html lang="ne">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>DB Setup — Bootstrap Mode</title>
<link href="assets/vendor/bootstrap.min.css" rel="stylesheet">

<style>body{background:#f4f6f8;}.bootstrap-banner{background:linear-gradient(135deg,#f59e0b,#d97706);color:#fff;padding:12px 20px;text-align:center;font-weight:600;}</style>
</head>
<body>
<div class="bootstrap-banner">
  <i class="fas fa-tools me-2"></i>BOOTSTRAP MODE — Database connect भएन वा Admin user छैन। Setup पूरा भएपछि login पेज देखिनेछ।
  <?php if (!empty($bootstrapAutoUnlockFromDbFile)): ?>
  <div class="dbs-boot-note">
    <code>includes/database.local.php</code> मा DB name/user भरिएको छ — सिधै यहाँ credentials मिलाउनुहोस् वा <code>install.sql</code> चलाउनुहोस्।
  </div>
  <?php endif; ?>
</div>
<div>
<?php endif; ?>

<div class="container-fluid py-4">

    <!-- Page Header -->
    <div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
        <div class="dbs-page-icon">
            <i class="fas fa-database fa-xl"></i>
        </div>
        <div>
            <h4 class="mb-0 fw-bold">DB Setup <span class="badge bg-secondary">View Only</span></h4>
            <small class="text-muted">Database configuration र table setup — Superadmin only</small>
        </div>
        <div class="ms-auto d-flex gap-2 flex-wrap">
            <a href="run-migration.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-code-branch me-1"></i>Migration Runner
            </a>
            <form method="post" class="d-inline" onsubmit="return confirm('Schema lock files clear गर्ने? अर्को page load मा सबै tables verify हुन्छ।')">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="reset_schema_locks">
                <button type="submit" class="btn btn-outline-warning btn-sm" title="v2: Schema लाई पुनः verify गर्न lock files हटाउने">
                    <i class="fas fa-sync me-1"></i>Re-verify Schema
                </button>
            </form>
            <a href="site-setup.php" class="btn btn-outline-secondary btn-sm">
                <i class="fas fa-sliders me-1"></i>Site Setup
            </a>
        </div>
    </div>

    <!-- ⚠️ VIEW ONLY WARNING -->
    <div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
        <i class="fas fa-exclamation-triangle"></i>
        <div>
            <strong>⚠️ View Only:</strong> 
            Database credentials edit garnu cPanel File Manager <code>(includes/database.local.php)</code> bata garnus!
            Admin panel ma sirf table status herna मात्र सकिन्छ।
        </div>
    </div>

    <!-- Action result -->
    <?php if (!empty($actionResult)): ?>
    <div class="mb-3"><?php echo $actionResult; ?></div>
    <?php endif; ?>

    <!-- Flash -->
    <?php $flash = getFlash(); if ($flash): ?>
    <?php $flashTypeClass = in_array(($flash['type'] ?? ''), ['success', 'info', 'warning'], true) ? $flash['type'] : 'danger'; ?>
    <div class="alert alert-<?php echo $flashTypeClass; ?> alert-dismissible fade show mb-3">
        <i class="fas fa-<?php echo $flash['type']==='success'?'check-circle':($flash['type']==='info'?'info-circle':'exclamation-circle'); ?> me-2"></i>
        <?php echo htmlspecialchars($flash['message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- DB Connection Error Banner -->
    <?php if ($dbError): ?>
    <div class="alert alert-danger d-flex align-items-start gap-2 mb-4">
        <i class="fas fa-exclamation-circle fa-lg mt-1 flex-shrink-0"></i>
        <div>
            <strong>Database Connection असफल!</strong><br>
            <code class="small"><?php echo htmlspecialchars($dbError); ?></code><br>
            <small class="text-muted">
                <code>includes/database.local.php</code> (वा पुरानो <code>includes/database.php</code>) मा DB credentials check गर्नुहोस्।
            </small>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══ STATUS CARDS ══ -->
    <div class="row g-3 mb-4">

        <!-- DB Connection -->
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 text-center p-3">
                <?php if ($db): ?>
                    <div class="dbs-stat-icon is-ok"><i class="fas fa-check-circle"></i></div>
                    <div class="fw-bold mt-1">DB Connected</div>
                    <div class="text-muted small">Database जोडिएको छ</div>
                <?php else: ?>
                    <div class="dbs-stat-icon is-bad"><i class="fas fa-times-circle"></i></div>
                    <div class="fw-bold mt-1">DB Disconnected</div>
                    <div class="text-muted small">Connection छैन</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tables Status -->
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 text-center p-3">
                <div class="dbs-stat-icon <?php echo $tablesMissing>0 ? 'is-bad' : 'is-ok'; ?>">
                    <i class="fas fa-table"></i>
                </div>
                <div class="fw-bold mt-1"><?php echo $tablesFound; ?>/<?php echo count($allTables); ?> Tables</div>
                <div class="text-muted small">
                    <?php echo $tablesMissing>0 ? $tablesMissing.' tables छैनन्' : 'सबै tables OK'; ?>
                </div>
            </div>
        </div>

        <!-- SQL File -->
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 text-center p-3">
                <div class="dbs-stat-icon <?php echo $sqlExists ? 'is-info' : 'is-muted'; ?>">
                    <i class="fas fa-file-code"></i>
                </div>
                <div class="fw-bold mt-1"><?php echo $sqlExists?'install.sql OK':'install.sql छैन'; ?></div>
                <div class="text-muted small">
                    <?php echo $sqlExists ? number_format(filesize($sqlFile)).' bytes' : 'File upload गर्नुस्'; ?>
                </div>
            </div>
        </div>

        <!-- Setup Lock -->
        <div class="col-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 text-center p-3">
                <div class="dbs-stat-icon <?php echo $setupLocked ? 'is-ok' : 'is-bad'; ?>">
                    <i class="fas fa-<?php echo $setupLocked?'lock':'lock-open'; ?>"></i>
                </div>
                <div class="fw-bold mt-1"><?php echo $setupLocked?'setup.php Locked':'setup.php Unlocked'; ?></div>
                <div class="text-muted small">Public URL <?php echo $setupLocked?'बन्द':'खुल्ला'; ?> छ</div>
            </div>
        </div>
    </div>

    <div class="row g-4">

        <!-- ════ LEFT ════ -->
        <div class="col-lg-8">

            <!-- ── Full Database Setup ── -->
            <div class="card border-0 shadow-sm mb-4 dbs-card dbs-card-primary">
                <div class="card-header py-3 d-flex align-items-center gap-2"
                     >
                    <i class="fas fa-play-circle fa-lg"></i>
                    <div>
                        <h5 class="mb-0">Full Database Setup चलाउनुहोस्</h5>
                        <small class="opacity-75">install.sql — सबै tables एकैपटक बन्छन् (safe: repeat run OK)</small>
                    </div>
                </div>
                <div class="card-body">

                    <?php if ($sqlExists): ?>
                    <div class="d-flex align-items-start gap-3 p-3 rounded-3 mb-3 dbs-soft-ok">
                        <i class="fas fa-file-code fa-xl text-success mt-1"></i>
                        <div>
                            <div class="fw-semibold">install.sql</div>
                            <small class="text-muted">
                                <?php echo number_format(filesize($sqlFile)); ?> bytes —
                                <?php echo date('Y-m-d', filemtime($sqlFile)); ?>
                            </small>
                        </div>
                        <div class="ms-auto">
                            <?php if ($tablesMissing === 0): ?>
                                <span class="badge bg-success"><i class="fas fa-check me-1"></i>सबै tables छन्</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">
                                    <i class="fas fa-exclamation-triangle me-1"></i><?php echo $tablesMissing; ?> tables छैनन्
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <form method="POST"
                          onsubmit="return confirm('install.sql run गर्ने? (data delete हुँदैन — safe operation)\n\nOK = Run गर्नुहोस्')">
                        <input type="hidden" name="action" value="run_full_setup">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <button type="submit" class="btn btn-success btn-lg px-4">
                            <i class="fas fa-play-circle me-2"></i>Database Setup Run गर्नुहोस्
                        </button>
                        <span class="text-muted small ms-2">Repeat run गर्यो भने पनि data delete हुँदैन।</span>
                    </form>

                    <?php else: ?>
                    <div class="alert alert-warning mb-0">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>install.sql file भेटिएन।</strong><br>
                        तल "SQL File Upload" section बाट आफ्नो install.sql upload गरेर run गर्नुहोस्।
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── TESTING HARD RESET ── -->
            <div class="card border-0 shadow-sm mb-4 dbs-card dbs-card-danger">
                <div class="card-header py-3 d-flex align-items-center gap-2"
                     >
                    <i class="fas fa-skull-crossbones fa-lg"></i>
                    <div>
                        <h5 class="mb-0">Testing Hard Reset (पुरानो data हटाउने)</h5>
                        <small class="opacity-75">सबै tables drop गरेर install.sql बाट fresh rebuild — irreversible</small>
                    </div>
                </div>
                <div class="card-body">
                    <div class="alert alert-danger mb-3">
                        <i class="fas fa-triangle-exclamation me-1"></i>
                        यो action ले पुरानो data पूर्ण हटाउँछ। Testing environment मा मात्र प्रयोग गर्नुहोस्।
                    </div>
                    <form method="POST" onsubmit="return confirm('यो action irreversible छ। सबै data/table हटाएर fresh rebuild गर्ने?\n\nOK = जारी राख्नुहोस्')">
                        <input type="hidden" name="action" value="reset_rebuild_testing">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <label class="form-label small fw-semibold">Confirm text टाइप गर्नुहोस्: <code>RESET TEST DB</code></label>
                        <div class="input-group">
                            <input type="text" name="confirm_text" class="form-control" placeholder="RESET TEST DB" required>
                            <button type="submit" class="btn btn-danger">
                                <i class="fas fa-trash me-1"></i>Hard Reset + Rebuild
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ── Server SQL Files (database/ folder) ── -->
            <div class="card border-0 shadow-sm mb-4 dbs-card dbs-card-info">
                <div class="card-header py-3 d-flex align-items-center gap-2"
                     >
                    <i class="fas fa-folder-open fa-lg"></i>
                    <div>
                        <h5 class="mb-0">Database Folder SQL Files</h5>
                        <small class="opacity-75">
                            <code class="dbs-inline-code">
                                public_html/database/
                            </code>
                            — <?php echo count($serverSqlFiles); ?> .sql file<?php echo count($serverSqlFiles)!==1?'s':''; ?> भेटियो
                        </small>
                    </div>
                    <span class="ms-auto badge bg-light text-dark">
                        <?php echo count($serverSqlFiles); ?> files
                    </span>
                </div>
                <!-- ── Upload New SQL File → Save to database/ folder ── -->
                <div class="p-3 border-bottom dbs-upload-strip">
                    <form method="POST" enctype="multipart/form-data"
                          class="d-flex align-items-end gap-2 flex-wrap"
                          onsubmit="return confirm('यो SQL file database/ folder मा save गर्ने?\n\nSave भएपछि तल list मा देखिनेछ — त्यहाँबाट Run गर्न मिल्छ।')">
                        <input type="hidden" name="action"     value="upload_to_db_folder">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <div class="flex-grow-1">
                            <label class="form-label fw-semibold mb-1 small">
                                <i class="fas fa-file-arrow-up me-1 text-primary"></i>
                                नयाँ SQL File upload गर्नुहोस् <span class="text-muted fw-normal">(database/ folder मा save हुन्छ)</span>
                            </label>
                            <input type="file" name="new_sql_file" accept=".sql"
                                   class="form-control form-control-sm" required
                                   id="newSqlFileInput">
                            <div class="form-text dbs-mini-help">
                                .sql file मात्र — max 50MB। Save भएपछि तल list मा देखिन्छ।
                            </div>
                        </div>
                        <div>
                            <button type="submit" class="btn btn-primary btn-sm px-3">
                                <i class="fas fa-cloud-upload-alt me-1"></i>Upload &amp; Save
                            </button>
                        </div>
                    </form>
                </div>

                <div class="card-body p-0">

                    <?php if (!is_dir($dbFolderPath)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="fas fa-folder-xmark fa-2x mb-2 opacity-25 d-block"></i>
                        <strong>database/ folder भेटिएन।</strong><br>
                        <small>
                            माथिबाट SQL file upload गर्नुहोस् — folder automatically बन्नेछ।
                        </small>
                    </div>

                    <?php elseif (empty($serverSqlFiles)): ?>
                    <div class="p-4 text-center text-muted">
                        <i class="fas fa-file-arrow-up fa-2x mb-2 opacity-25 d-block"></i>
                        <strong>database/ folder खाली छ।</strong><br>
                        <small>माथिबाट SQL file upload गर्नुहोस् — यहाँ देखिनेछ।</small>
                    </div>

                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 small">
                            <thead class="dbs-soft-head">
                                <tr>
                                    <th class="ps-3"><i class="fas fa-file-code me-1 text-primary"></i>File Name</th>
                                    <th><i class="fas fa-weight-hanging me-1 text-muted"></i>Size</th>
                                    <th><i class="fas fa-clock me-1 text-muted"></i>Modified</th>
                                    <th class="text-center pe-3">Run</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($serverSqlFiles as $sf): ?>
                                <tr>
                                    <td class="ps-3">
                                        <div class="d-flex align-items-center gap-2">
                                            <i class="fas fa-file-code fa-lg text-primary opacity-75"></i>
                                            <div>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($sf['name']); ?></div>
                                                <?php if ($sf['name'] === 'install.sql'): ?>
                                                <span class="badge bg-success dbs-mini-badge">मुख्य file</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-muted">
                                        <?php
                                        $kb = $sf['size'] / 1024;
                                        echo $kb >= 1024
                                            ? round($kb/1024, 2) . ' MB'
                                            : round($kb, 1) . ' KB';
                                        ?>
                                    </td>
                                    <td class="text-muted">
                                        <?php echo date('Y-m-d H:i', $sf['mtime']); ?>
                                    </td>
                                    <td class="text-center pe-3">
                                        <form method="POST"
                                              onsubmit="return confirm('«<?php echo htmlspecialchars($sf['name'], ENT_QUOTES); ?>» run गर्ने?\n\nData delete हुँदैन — safe operation।\n\nOK = Run गर्नुहोस्')">
                                            <input type="hidden" name="action"       value="run_server_sql">
                                            <input type="hidden" name="sql_filename" value="<?php echo htmlspecialchars($sf['name'], ENT_QUOTES); ?>">
                                            <input type="hidden" name="csrf_token"   value="<?php echo $csrfToken; ?>">
                                            <button type="submit" class="btn btn-sm btn-primary">
                                                <i class="fas fa-play-circle me-1"></i>Run
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="p-2 text-muted small border-top dbs-table-footnote">
                        <i class="fas fa-info-circle me-1 text-primary"></i>
                        नयाँ .sql file थप्न: cPanel → File Manager → <code>public_html/database/</code> मा upload गर्नुहोस् — यहाँ automatically देखिनेछ।
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── SQL File Upload ── -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header py-2 d-flex align-items-center gap-2"
                     >
                    <i class="fas fa-file-upload"></i>
                    <div>
                        <h6 class="mb-0">Computer बाट SQL Upload गरेर Run गर्नुहोस्</h6>
                        <small class="opacity-75">आफ्नो computer बाट .sql file directly run गर्नुहोस्</small>
                    </div>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data"
                          onsubmit="return confirm('Upload गरिएको SQL file run गर्ने?\n\nOK = Run गर्नुहोस्')">
                        <input type="hidden" name="action" value="run_uploaded_sql">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <div class="row g-3 align-items-end">
                            <div class="col-md-8">
                                <label class="form-label fw-semibold small">
                                    <i class="fas fa-file-code me-1 text-primary"></i>.sql File छान्नुहोस्:
                                </label>
                                <input type="file" name="sql_file" accept=".sql" class="form-control" required>
                                <div class="form-text">केवल .sql — max 25MB। File server मा save हुँदैन, directly execute हुन्छ।</div>
                            </div>
                            <div class="col-md-4">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-play-circle me-1"></i>Run SQL File
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ── Core Tables Only ── -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header py-2"
                     >
                    <h6 class="mb-0"><i class="fas fa-table me-2"></i>Core Tables मात्र बनाउनुहोस्</h6>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        admin_users, site_settings, activity_log — यी minimum tables बनाउँछ।
                        install.sql नभएको अवस्थामा काम लाग्छ।
                    </p>
                    <form method="POST" onsubmit="return confirm('Core tables बनाउने? (already exist भए skip हुन्छन्)')">
                        <input type="hidden" name="action" value="create_core_tables">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <button type="submit" class="btn btn-outline-secondary">
                            <i class="fas fa-layer-group me-1"></i>Core Tables बनाउनुहोस्
                        </button>
                    </form>
                </div>
            </div>

        </div>

        <!-- ════ RIGHT ════ -->
        <div class="col-lg-4">

            <!-- setup.php Lock Control -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header py-2"
                     >
                    <h6 class="mb-0">
                        <i class="fas fa-<?php echo $setupLocked?'lock':'lock-open'; ?> me-2"></i>
                        setup.php Access Control
                    </h6>
                </div>
                <div class="card-body text-center">
                    <div class="mb-2 dbs-lock-icon <?php echo $setupLocked ? 'is-locked' : 'is-unlocked'; ?>">
                        <i class="fas fa-<?php echo $setupLocked?'shield-check':'shield-exclamation'; ?>"></i>
                    </div>
                    <p class="small text-muted mb-3">
                        <?php if ($setupLocked): ?>
                            setup.php public URL बाट access बन्द छ। (lock file छ)
                        <?php else: ?>
                            setup.php अझै public URL बाट खुल्छ!
                        <?php endif; ?>
                    </p>
                    <form method="POST" onsubmit="return confirm('<?php echo $setupLocked ? 'setup.php unlock गर्ने?' : 'setup.php lock गर्ने? Public URL बाट access बन्द हुनेछ।'; ?>')">
                        <input type="hidden" name="action" value="toggle_lock">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <button type="submit"
                                class="btn btn-sm <?php echo $setupLocked?'btn-outline-danger':'btn-danger'; ?> w-100">
                            <i class="fas fa-<?php echo $setupLocked?'lock-open':'lock'; ?> me-1"></i>
                            <?php echo $setupLocked ? 'Unlock गर्नुहोस्' : 'Lock गर्नुहोस् (सिफारिस)'; ?>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Quick Links -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header py-2 bg-light">
                    <h6 class="mb-0 text-muted"><i class="fas fa-link me-2"></i>सम्बन्धित Links</h6>
                </div>
                <div class="card-body p-0">
                    <div class="list-group list-group-flush">
                        <a href="site-setup.php" class="list-group-item list-group-item-action small py-2">
                            <i class="fas fa-sliders me-2 text-primary"></i>Site Setup Manager
                        </a>
                        <a href="run-migration.php" class="list-group-item list-group-item-action small py-2">
                            <i class="fas fa-code-branch me-2 text-warning"></i>Migration Runner
                        </a>
                        <a href="manage-admins.php" class="list-group-item list-group-item-action small py-2">
                            <i class="fas fa-users-gear me-2 text-success"></i>Admin User व्यवस्थापन
                        </a>
                        <a href="backup-restore.php" class="list-group-item list-group-item-action small py-2">
                            <i class="fas fa-shield-alt me-2 text-danger"></i>Backup / Restore
                        </a>
                        <a href="system-info.php" class="list-group-item list-group-item-action small py-2">
                            <i class="fas fa-server me-2 text-secondary"></i>System Info
                        </a>
                    </div>
                </div>
            </div>

            <!-- DB Credentials — Editable Form -->
            <div class="card border-0 shadow-sm">
                <div class="card-header py-2 d-flex align-items-center gap-2"
                     >
                    <i class="fas fa-key"></i>
                    <div>
                        <h6 class="mb-0">DB Credentials बदल्नुहोस्</h6>
                        <small class="opacity-75 dbs-mini-help">includes/database.local.php</small>
                    </div>
                    <span class="ms-auto badge dbs-head-badge">
                        <?php echo $adminDbCredWritable ? '✓ Writable' : '✗ Read-only'; ?>
                    </span>
                </div>
                <div class="card-body p-3">
                    <?php if (!$adminDbCredWritable): ?>
                    <div class="alert alert-warning py-2 small mb-3">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        <strong>includes/ folder वा database.local.php write गर्न मिल्दैन!</strong><br>
                        cPanel → File Manager: includes/ 755, database.local.php 644।
                    </div>
                    <?php endif; ?>

                    <form method="POST" action=""
                          onsubmit="return confirm('DB credentials अपडेट गर्ने?\n\nGalat values राख्यो भने site काम गर्न छाड्छ!\n\nOK = अपडेट गर्नुहोस्')">
                        <input type="hidden" name="action"     value="update_db_credentials">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                        <!-- DB Host -->
                        <div class="mb-2">
                            <label class="form-label fw-semibold mb-1 dbs-form-label-sm">
                                <i class="fas fa-server me-1 text-muted"></i>DB Host
                            </label>
                            <input type="text" name="db_host" class="form-control form-control-sm"
                                   value="<?php echo htmlspecialchars(defined('DB_HOST') ? DB_HOST : 'localhost'); ?>"
                                   placeholder="localhost" required>
                            <div class="form-text dbs-mini-help">Shared hosting मा सधैं <code>localhost</code></div>
                        </div>

                        <!-- DB Name -->
                        <div class="mb-2">
                            <label class="form-label fw-semibold mb-1 dbs-form-label-sm">
                                <i class="fas fa-database me-1 text-muted"></i>Database Name
                            </label>
                            <input type="text" name="db_name" class="form-control form-control-sm"
                                   value="<?php echo htmlspecialchars(defined('DB_NAME') ? DB_NAME : ''); ?>"
                                   placeholder="cpanelusername_dbname" required>
                        </div>

                        <!-- DB User -->
                        <div class="mb-2">
                            <label class="form-label fw-semibold mb-1 dbs-form-label-sm">
                                <i class="fas fa-user me-1 text-muted"></i>DB Username
                            </label>
                            <input type="text" name="db_user" class="form-control form-control-sm"
                                   value="<?php echo htmlspecialchars(defined('DB_USER') ? DB_USER : ''); ?>"
                                   placeholder="cpanelusername_dbuser" required>
                        </div>

                        <!-- DB Password -->
                        <div class="mb-2">
                            <label class="form-label fw-semibold mb-1 dbs-form-label-sm">
                                <i class="fas fa-lock me-1 text-muted"></i>DB Password
                            </label>
                            <div class="input-group input-group-sm">
                                <input type="password" name="db_pass" id="dbPassInput"
                                       class="form-control form-control-sm"
                                       value=""
                                       placeholder="<?php echo (defined('DB_PASS') && (string)DB_PASS !== '') ? 'खाली = अहिलेको password राख्ने' : 'DB password'; ?>"
                                       autocomplete="new-password">
                                <button type="button" class="btn btn-outline-secondary btn-sm"
                                        onclick="var i=document.getElementById('dbPassInput');i.type=i.type==='password'?'text':'password';" aria-label="View" title="View">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Site URL -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold mb-1 dbs-form-label-sm">
                                <i class="fas fa-globe me-1 text-muted"></i>Site URL
                            </label>
                            <input type="url" name="site_url" class="form-control form-control-sm"
                                   value="<?php echo htmlspecialchars(defined('SITE_URL') ? SITE_URL : ''); ?>"
                                   placeholder="https://yourdomain.com.np/">
                            <div class="form-text dbs-mini-help">Trailing slash (/) अनिवार्य — auto-add हुन्छ।</div>
                        </div>

                        <!-- Warning -->
                        <div class="p-2 rounded-2 mb-3 dbs-cred-warning">
                            <i class="fas fa-exclamation-triangle text-warning me-1"></i>
                            <strong>सावधान!</strong> गलत credentials राख्यो भने site काम गर्दैन। Save गर्नु अघि values confirm गर्नुहोस्।
                        </div>

                        <button type="submit" class="btn btn-sm btn-dark w-100 fw-semibold"
                                <?php echo !$adminDbCredWritable ? 'disabled' : ''; ?>>
                            <i class="fas fa-save me-1"></i>Credentials Save गर्नुहोस्
                        </button>
                    </form>
                </div>
            </div>

        </div>
    </div>

    <!-- ══ TABLE STATUS ══ -->
    <div class="card border-0 shadow-sm mt-2">
        <div class="card-header py-2 d-flex align-items-center justify-content-between"
             >
            <span><i class="fas fa-table me-2"></i>Database Tables Status</span>
            <span class="badge <?php echo $tablesMissing===0?'bg-success':'bg-warning text-dark'; ?>">
                <?php echo $tablesFound; ?>/<?php echo count($allTables); ?> Tables
            </span>
        </div>
        <?php if ($db): ?>
        <div class="card-body p-0">
            <div class="row g-0">
                <?php foreach ($allTables as $tbl => $label):
                    $exists = $tableStatus[$tbl] ?? false; ?>
                <div class="col-6 col-md-4 col-lg-3">
                    <div class="d-flex align-items-center gap-2 p-2 border-bottom border-end dbs-table-status-item">
                        <i class="fas fa-<?php echo $exists?'check-circle text-success':'times-circle text-danger'; ?>"></i>
                        <div>
                            <div class="fw-semibold text-truncate dbs-table-status-label"><?php echo htmlspecialchars($label); ?></div>
                            <code class="text-muted dbs-mini-help"><?php echo htmlspecialchars($tbl); ?></code>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php else: ?>
        <div class="card-body text-center py-3 text-muted">
            <i class="fas fa-database me-2"></i>Database connected भएपछि table status देखिनेछ।
        </div>
        <?php endif; ?>
    </div>

</div>

<?php
if (defined('BOOTSTRAP_MODE') && BOOTSTRAP_MODE) {
    echo '</div></body></html>';
} else {
    require_once 'includes/admin-footer.php';
}
?>
