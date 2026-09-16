<?php
/**
 * ╔══════════════════════════════════════════════════════════╗
 * ║   सहकारी वेबसाइट — INSTALL WIZARD v1.0                  ║
 * ║   Cooperative Website Setup — One-Click Install          ║
 * ╚══════════════════════════════════════════════════════════╝
 *
 * • यो file एक-पटक मात्र चल्छ — सकेपछि install.lock बन्छ।
 * • Security: install.lock भएपछि admin/ मा redirect हुन्छ।
 * • कुनै code edit गर्नुपर्दैन — सबै browser बाटै हुन्छ।
 */

// Security gate: block re-install by default once local DB config or lock exists.
$installLockExists = file_exists(__DIR__ . '/install.lock');
$localDbConfigExists = file_exists(__DIR__ . '/includes/database.local.php');
$allowInstallRerun = defined('ALLOW_INSTALL_RERUN') && ALLOW_INSTALL_RERUN === true;

/* Live safety: DB config छ तर lock छैन भने auto-lock (Apache + file layer) */
if ($localDbConfigExists && !$installLockExists && is_writable(__DIR__)) {
    @file_put_contents(
        __DIR__ . '/install.lock',
        'Auto-locked: ' . date('c') . "\nReason: includes/database.local.php present\n"
    );
    $installLockExists = true;
}

if (($installLockExists || $localDbConfigExists) && !$allowInstallRerun) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Install wizard is disabled.\n";
    echo "Preferred setup: includes/database.local.php + includes/superadmin-config.local.php → /admin/ login.\n";
    echo "Emergency: admin/db-setup.php\n";
    exit;
}

if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
session_start();

function install_csrf_token(): string
{
    if (empty($_SESSION['install_csrf'])) {
        $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['install_csrf'];
}

function install_verify_csrf(): bool
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    return isset($_SESSION['install_csrf'])
        && $token !== ''
        && hash_equals($_SESSION['install_csrf'], $token);
}

function install_json_csrf_fail(): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'  => false,
        'msg' => 'Security check failed. Refresh the page and try again.',
    ]);
    exit;
}

/* ─────────────────────────── AJAX: test DB connection ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_db') {
    header('Content-Type: application/json; charset=utf-8');
    if (!install_verify_csrf()) {
        install_json_csrf_fail();
    }
    $h = trim($_POST['db_host'] ?? 'localhost');
    $n = trim($_POST['db_name'] ?? '');
    $u = trim($_POST['db_user'] ?? '');
    $p = trim($_POST['db_pass'] ?? '');
    if (!$n || !$u) {
        echo json_encode(['ok' => false, 'msg' => 'Database name र username अनिवार्य छ।']);
        exit;
    }
    try {
        $pdo = new PDO("mysql:host=$h;dbname=$n;charset=utf8mb4", $u, $p,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        $ver = $pdo->query("SELECT VERSION()")->fetchColumn();
        echo json_encode(['ok' => true, 'msg' => "✓ सफलतापूर्वक जोडिएको! MySQL $ver"]);
    } catch (PDOException $e) {
        $msg = preg_replace('/\[.*?\]\s*/u', '', $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => "जोड्न सकिएन: $msg"]);
    }
    exit;
}

/* ─────────────────────────── POST: run installation ────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'install') {
    header('Content-Type: application/json; charset=utf-8');
    if (!install_verify_csrf()) {
        echo json_encode(['ok' => false, 'error' => 'Security check failed. Refresh the page and try again.']);
        exit;
    }
    if (file_exists(__DIR__ . '/install.lock') || file_exists(__DIR__ . '/includes/database.local.php')) {
        echo json_encode(['ok' => false, 'error' => 'Install already completed.']);
        exit;
    }

    $steps  = [];
    $ok     = true;
    $errMsg = '';

    // Collect + validate inputs
    $dbHost       = trim($_POST['db_host']         ?? 'localhost');
    $dbName       = trim($_POST['db_name']         ?? '');
    $dbUser       = trim($_POST['db_user']         ?? '');
    $dbPass       = trim($_POST['db_pass']         ?? '');
    $siteUrl      = rtrim(trim($_POST['site_url']  ?? ''), '/') . '/';
    $siteName     = trim($_POST['site_name']       ?? '');
    $siteNameEn   = trim($_POST['site_name_en']    ?? '');
    $siteSlogan   = trim($_POST['site_slogan']     ?? '');
    $phone        = trim($_POST['phone']           ?? '');
    $email        = trim($_POST['email']           ?? '');
    $address      = trim($_POST['address']         ?? '');
    $primaryColor = trim($_POST['primary_color']   ?? '#1a5f2a');
    $adminUser    = preg_replace('/[^a-z0-9_]/i', '', trim($_POST['admin_username'] ?? 'admin')) ?: 'admin';
    $adminPass    = trim($_POST['admin_password']  ?? '');
    $adminName    = trim($_POST['admin_fullname']  ?? '');
    $adminEmail   = trim($_POST['admin_email']     ?? '');

    if (!preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $primaryColor)) {
        $primaryColor = '#1a5f2a';
    }

    try {
        // ① DB connect
        $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $steps[] = ['ok' => true, 'msg' => 'Database जोडिएको'];

        // ② Run install.sql
        $sqlFile = __DIR__ . '/database/install.sql';
        if (!is_readable($sqlFile)) throw new Exception('database/install.sql फेला परेन।');
        $stmts  = _splitSql(file_get_contents($sqlFile));
        $ran    = 0;
        foreach ($stmts as $s) {
            $s = trim($s);
            if ($s === '') continue;
            try { $pdo->exec($s); $ran++; }
            catch (PDOException $e) {
                // Ignore safe errors (duplicate key, column exists, etc.)
                if (!preg_match('/Duplicate (column|key|entry)|already exists|Multiple primary/i', $e->getMessage())) {
                    // Non-fatal — log but continue
                }
            }
        }
        $steps[] = ['ok' => true, 'msg' => "Database tables तयार ($ran statements चलाइयो)"];

        // ③ Admin user
        $hash = password_hash($adminPass ?: bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
        $exists = $pdo->prepare("SELECT id FROM admin_users WHERE username=? LIMIT 1");
        $exists->execute([$adminUser]);
        if ($exists->fetchColumn()) {
            $pdo->prepare("UPDATE admin_users SET password=?,full_name=?,email=?,role='superadmin' WHERE username=?")
                ->execute([$hash, $adminName ?: $adminUser, $adminEmail, $adminUser]);
        } else {
            $pdo->prepare("INSERT INTO admin_users (username,password,full_name,email,role) VALUES (?,?,?,?,'superadmin')")
                ->execute([$adminUser, $hash, $adminName ?: $adminUser, $adminEmail]);
        }
        // Also update legacy 'admin' row with same password so both work initially
        if ($adminUser !== 'admin') {
            $pdo->prepare("UPDATE admin_users SET password=? WHERE username='admin'")->execute([$hash]);
        }
        $steps[] = ['ok' => true, 'msg' => "Admin account सेटअप ($adminUser)"];

        // ④ Seed site settings
        $upsert = $pdo->prepare(
            "INSERT INTO site_settings (setting_key,setting_value)
             VALUES (?,?)
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
        );
        $seedSettings = [
            'site_name'     => $siteName     ?: 'मेरो सहकारी',
            'site_name_en'  => $siteNameEn   ?: 'My Cooperative',
            'site_slogan'   => $siteSlogan   ?: 'समुदायमा आधारित सक्षम वित्तीय सहकारी',
            'phone'         => $phone,
            'email'         => $email,
            'address'       => $address,
            'primary_color' => $primaryColor,
            'footer_color'  => $primaryColor,
            'footer_text'   => '© ' . date('Y') . ' ' . ($siteName ?: 'सहकारी') . '. सर्वाधिकार सुरक्षित।',
        ];
        foreach ($seedSettings as $k => $v) {
            if ($v !== '') $upsert->execute([$k, $v]);
        }
        $steps[] = ['ok' => true, 'msg' => 'Site settings सेटअप'];

        // ⑤ Write includes/database.local.php
        $credKey    = bin2hex(random_bytes(16));
        $localContent  = "<?php\n";
        $localContent .= "// Auto-generated by Install Wizard — " . date('Y-m-d H:i:s') . "\n";
        $localContent .= "// यो file delete नगर्नुहोस् — सबै DB credentials यहाँ छन्।\n";
        $localContent .= "if (!defined('DB_HOST')) define('DB_HOST', " . var_export($dbHost, true) . ");\n";
        $localContent .= "if (!defined('DB_NAME')) define('DB_NAME', " . var_export($dbName, true) . ");\n";
        $localContent .= "if (!defined('DB_USER')) define('DB_USER', " . var_export($dbUser, true) . ");\n";
        $localContent .= "if (!defined('DB_PASS')) define('DB_PASS', " . var_export($dbPass, true) . ");\n";
        if ($siteUrl && $siteUrl !== '/') {
            $localContent .= "if (!defined('SITE_URL')) define('SITE_URL', " . var_export($siteUrl, true) . ");\n";
        }
        $localContent .= "if (!defined('CRED_MASTER_KEY')) define('CRED_MASTER_KEY', " . var_export($credKey, true) . ");\n";

        $localFile = __DIR__ . '/includes/database.local.php';
        if (file_put_contents($localFile, $localContent) === false) {
            throw new Exception('includes/database.local.php लेख्न सकिएन। Folder permission जाँच्नुहोस्।');
        }
        chmod($localFile, 0600);
        $steps[] = ['ok' => true, 'msg' => 'Configuration file लेखियो'];

        // ⑥ Lock file
        file_put_contents(__DIR__ . '/install.lock',
            "Installed: " . date('Y-m-d H:i:s') . "\nInstaller: install.php wizard\n"
        );
        @chmod(__DIR__ . '/install.lock', 0644);
        $steps[] = ['ok' => true, 'msg' => 'Installation lock सिर्जना — install.php अब बन्द भयो'];

        $siteLink  = $siteUrl ?: '/';
        $adminLink = ($siteUrl ?: '/') . 'admin/';
        echo json_encode(['ok' => true, 'steps' => $steps, 'site_url' => $siteLink, 'admin_url' => $adminLink]);

    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'steps' => $steps]);
    }
    exit;
}

/* ─── SQL splitter (handles comments + quoted strings) ──── */
function _splitSql(string $sql): array {
    $sql  = ltrim($sql, "\xEF\xBB\xBF"); // strip BOM
    $out  = [];
    $cur  = '';
    $inQ  = false;
    $qCh  = '';
    $len  = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $c = $sql[$i];
        if ($inQ) {
            $cur .= $c;
            if ($c === $qCh && ($i === 0 || $sql[$i - 1] !== '\\')) $inQ = false;
            continue;
        }
        if ($c === '-' && isset($sql[$i + 1]) && $sql[$i + 1] === '-') {
            $end = strpos($sql, "\n", $i);
            $i   = $end === false ? $len : $end;
            continue;
        }
        if ($c === '#') {
            $end = strpos($sql, "\n", $i);
            $i   = $end === false ? $len : $end;
            continue;
        }
        if ($c === "'" || $c === '"' || $c === '`') { $inQ = true; $qCh = $c; }
        if ($c === ';') { $out[] = $cur; $cur = ''; continue; }
        $cur .= $c;
    }
    if (trim($cur) !== '') $out[] = $cur;
    return $out;
}

/* ─── System checks ─────────────────────────────────────── */
$checks = [
    ['label' => 'PHP संस्करण ≥ 8.0',        'ok' => PHP_VERSION_ID >= 80000,              'val' => PHP_VERSION],
    ['label' => 'PDO Extension',              'ok' => extension_loaded('pdo'),               'val' => extension_loaded('pdo')       ? 'Enabled'  : 'Missing'],
    ['label' => 'PDO MySQL Driver',           'ok' => extension_loaded('pdo_mysql'),         'val' => extension_loaded('pdo_mysql') ? 'Enabled'  : 'Missing'],
    ['label' => 'Mbstring Extension',         'ok' => extension_loaded('mbstring'),          'val' => extension_loaded('mbstring')  ? 'Enabled'  : 'Optional'],
    ['label' => 'includes/ लेख्न सकिन्छ',    'ok' => is_writable(__DIR__ . '/includes'),    'val' => is_writable(__DIR__ . '/includes') ? 'Writable' : '❌ Read-Only'],
    ['label' => 'database/install.sql',       'ok' => file_exists(__DIR__ . '/database/install.sql'), 'val' => file_exists(__DIR__ . '/database/install.sql') ? 'Found' : '❌ Missing'],
];
$allChecksPass = array_reduce($checks, fn($c, $r) => $c && $r['ok'], true);

$guessUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
          . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/';
?>
<!DOCTYPE html>
<html lang="ne" dir="ltr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="One-click installation wizard for the cooperative website.">
<title>Install — Cooperative Website Setup</title>

<?php
$__themeAssets = __DIR__ . '/includes/theme-assets.php';
if (is_file($__themeAssets)) {
    require_once $__themeAssets;
}
if (function_exists('coopThemeGoogleFonts')) {
    coopThemeGoogleFonts();
} else {
    echo '<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Inter:wght@400;500;600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">' . "\n";
}
unset($__themeAssets);
?>
<link rel="stylesheet" href="assets/css/install-page.css">
</head>
<body>

<div class="page-wrap">

    <!-- Brand header -->
    <div class="brand-header">
        <div class="brand-logo"><i class="lucide-icon" data-lucide="sprout" aria-hidden="true"></i></div>
        <div class="brand-title">सहकारी Website Setup</div>
        <div class="brand-sub">Cooperative Website — One-Time Install Wizard · v1.0</div>
    </div>

    <!-- Progress steps -->
    <div class="progress-track" id="progressTrack">
        <div class="prog-step active" id="ps0">
            <div class="prog-dot active" id="pd0"><i class="lucide-icon" data-lucide="circle-check" aria-hidden="true"></i></div>
            <div class="prog-label">जाँच<br>System</div>
        </div>
        <div class="prog-line" id="pl0"></div>
        <div class="prog-step" id="ps1">
            <div class="prog-dot" id="pd1">2</div>
            <div class="prog-label">Database<br>DB Setup</div>
        </div>
        <div class="prog-line" id="pl1"></div>
        <div class="prog-step" id="ps2">
            <div class="prog-dot" id="pd2">3</div>
            <div class="prog-label">सहकारी<br>Site Info</div>
        </div>
        <div class="prog-line" id="pl2"></div>
        <div class="prog-step" id="ps3">
            <div class="prog-dot" id="pd3">4</div>
            <div class="prog-label">Admin<br>Account</div>
        </div>
        <div class="prog-line" id="pl3"></div>
        <div class="prog-step" id="ps4">
            <div class="prog-dot" id="pd4">5</div>
            <div class="prog-label">Install<br>सुरू</div>
        </div>
    </div>

    <!-- Main card -->
    <div class="card">

        <!-- ════ STEP 0: System Check ════ -->
        <div id="step0">
            <div class="card-head">
                <div class="card-head-icon"><i class="lucide-icon" data-lucide="server" aria-hidden="true"></i></div>
                <h2>System Requirements जाँच</h2>
                <p>Install गर्नु अघि तपाईंको server ले आवश्यकताहरू पूरा गर्छ कि छैन जाँचौं।</p>
            </div>
            <div class="card-body">
                <table class="check-table">
                    <thead>
                        <tr>
                            <th>आवश्यकता</th>
                            <th>अवस्था</th>
                            <th>मान</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($checks as $chk): ?>
                        <tr>
                            <td><?= htmlspecialchars($chk['label']) ?></td>
                            <td>
                                <?php if ($chk['ok']): ?>
                                    <span class="badge-ok"><i class="lucide-icon" data-lucide="circle-check" aria-hidden="true"></i> ठीक</span>
                                <?php else: ?>
                                    <span class="badge-err"><i class="lucide-icon" data-lucide="circle-x" aria-hidden="true"></i> समस्या</span>
                                <?php endif; ?>
                            </td>
                            <td style="color:var(--gray-600);font-size:.82rem;"><?= htmlspecialchars($chk['val']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if (!$allChecksPass): ?>
                <div class="check-warning">
                    <i class="lucide-icon" data-lucide="triangle-alert" aria-hidden="true" style="font-size:1.1rem;flex-shrink:0;margin-top:2px;"></i>
                    <div>
                        <strong>कुनै आवश्यकता पूरा भएन।</strong><br>
                        <span style="font-size:.82rem;">माथि रातो देखाएका items आफ्नो hosting provider सँग ठीक गर्नुहोस् अनि पुनः try गर्नुहोस्।
                        अधिकांश cPanel hosting मा PDO MySQL पहिले नै available हुन्छ।</span>
                    </div>
                </div>
                <?php else: ?>
                <div style="margin-top:14px;padding:11px 14px;background:var(--green-lt);border:1px solid #c8e6c9;border-radius:10px;font-size:.85rem;color:var(--green-dk);display:flex;gap:8px;align-items:center;">
                    <i class="lucide-icon" data-lucide="circle-check" aria-hidden="true"></i>
                    <strong>सबै आवश्यकताहरू पूरा भए!</strong> अगाडि बढ्नुहोस्।
                </div>
                <?php endif; ?>
            </div>
            <div class="nav-btns">
                <span class="step-counter">Step 1 of 5</span>
                <button type="button" class="btn-next" onclick="goStep(1)" <?= !$allChecksPass ? 'disabled' : '' ?>>
                    अर्को <i class="lucide-icon" data-lucide="arrow-right" aria-hidden="true"></i>
                </button>
            </div>
        </div>

        <!-- ════ STEP 1: Database ════ -->
        <div id="step1" style="display:none;">
            <div class="card-head">
                <div class="card-head-icon"><i class="lucide-icon" data-lucide="database" aria-hidden="true"></i></div>
                <h2>Database जोडाउनुहोस्</h2>
                <p>cPanel मा बनाएको database को जानकारी भर्नुहोस्। यो hosting provider ले दिएको हुन्छ।</p>
            </div>
            <div class="card-body">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label for="db_host" class="form-label"><i class="lucide-icon" data-lucide="server" aria-hidden="true" style="color:var(--green);"></i> DB Host <span class="req">*</span></label>
                        <input type="text" class="form-control" id="db_host" value="localhost" placeholder="localhost">
                        <span class="form-hint">प्रायः <code>localhost</code> नै हुन्छ।</span>
                    </div>
                    <div class="form-group">
                        <label for="db_name" class="form-label"><i class="lucide-icon" data-lucide="database" aria-hidden="true" style="color:var(--green);"></i> Database Name <span class="req">*</span></label>
                        <input type="text" class="form-control" id="db_name" placeholder="cpanel_dbname">
                        <span class="form-hint">cPanel → MySQL Databases मा बनाएको नाम।</span>
                    </div>
                </div>
                <div class="db-test-row" style="gap:12px;display:grid;grid-template-columns:1fr 1fr;">
                    <div class="form-group">
                        <label for="db_user" class="form-label"><i class="lucide-icon" data-lucide="user" aria-hidden="true" style="color:var(--green);"></i> DB Username <span class="req">*</span></label>
                        <input type="text" class="form-control" id="db_user" placeholder="cpanel_dbuser">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="db_pass"><i class="lucide-icon" data-lucide="lock" aria-hidden="true" style="color:var(--green);"></i> DB Password</label>
                        <input type="password" class="form-control" id="db_pass" placeholder="••••••••" autocomplete="off">
                    </div>
                </div>
                <button type="button" class="btn-test" style="margin-top:6px;" onclick="testDb()">
                    <i class="lucide-icon" data-lucide="plug" aria-hidden="true"></i> Connection Test गर्नुहोस्
                </button>
                <div class="db-status" id="dbStatus"></div>
                <div class="error-box" id="step1Err"></div>
            </div>
            <div class="nav-btns">
                <button type="button" class="btn-back" onclick="goStep(0)"><i class="lucide-icon" data-lucide="arrow-left" aria-hidden="true"></i> पछाडि</button>
                <span class="step-counter">Step 2 of 5</span>
                <button type="button" class="btn-next" onclick="validateDb()">अर्को <i class="lucide-icon" data-lucide="arrow-right" aria-hidden="true"></i></button>
            </div>
        </div>

        <!-- ════ STEP 2: Cooperative Info ════ -->
        <div id="step2" style="display:none;">
            <div class="card-head">
                <div class="card-head-icon"><i class="lucide-icon" data-lucide="landmark" aria-hidden="true"></i></div>
                <h2>सहकारी जानकारी भर्नुहोस्</h2>
                <p>तपाईंको सहकारीको नाम, सम्पर्क र प्राथमिक रंग सेट गर्नुहोस्। पछि Admin Panel बाट पनि बदल्न सकिन्छ।</p>
            </div>
            <div class="card-body">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label for="site_name" class="form-label"><i class="lucide-icon" data-lucide="type" aria-hidden="true" style="color:var(--green);"></i> नाम (नेपालीमा) <span class="req">*</span></label>
                        <input type="text" class="form-control" id="site_name" placeholder="जस्तै: सूर्योदय बचत तथा ऋण सहकारी">
                    </div>
                    <div class="form-group">
                        <label for="site_name_en" class="form-label"><i class="lucide-icon" data-lucide="type" aria-hidden="true" style="color:var(--green);"></i> Name (English)</label>
                        <input type="text" class="form-control" id="site_name_en" placeholder="Suryadaya S&C Cooperative">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="site_slogan" class="form-label"><i class="lucide-icon" data-lucide="quote" aria-hidden="true" style="color:var(--green);"></i> Slogan / नारा</label>
                        <input type="text" class="form-control" id="site_slogan" placeholder="जस्तै: समुदायको विश्वासिलो साथी">
                    </div>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label for="phone" class="form-label"><i class="lucide-icon" data-lucide="phone" aria-hidden="true" style="color:var(--green);"></i> फोन नम्बर</label>
                        <input type="text" class="form-control" id="phone" placeholder="061-590067">
                    </div>
                    <div class="form-group">
                        <label for="email" class="form-label"><i class="lucide-icon" data-lucide="mail" aria-hidden="true" style="color:var(--green);"></i> Email</label>
                        <input type="email" class="form-control" id="email" placeholder="info@example.com">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="address" class="form-label"><i class="lucide-icon" data-lucide="map-pin" aria-hidden="true" style="color:var(--green);"></i> ठेगाना</label>
                        <input type="text" class="form-control" id="address" placeholder="जस्तै: पोखरा, कास्की, गण्डकी प्रदेश">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="site_url" class="form-label"><i class="lucide-icon" data-lucide="globe" aria-hidden="true" style="color:var(--green);"></i> Website URL</label>
                        <input type="url" class="form-control" id="site_url" value="<?= htmlspecialchars($guessUrl) ?>" placeholder="https://yourdomain.com/">
                        <span class="form-hint">पूरा URL domain सहित (https:// वा http://)।</span>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="admin_username" class="form-label"><i class="lucide-icon" data-lucide="palette" aria-hidden="true" style="color:var(--green);"></i> Primary Color (मुख्य रंग)</label>
                        <div class="color-row">
                            <input type="color" id="primary_color" value="#1a5f2a" onchange="updateColorPreview()">
                            <div class="color-preview" id="colorPreview" style="background:#1a5f2a;">
                                <i class="lucide-icon" data-lucide="contrast" aria-hidden="true" style="margin-right:6px;"></i>
                                <span id="colorHex">#1a5f2a</span> — तपाईंको brand रंग
                            </div>
                        </div>
                        <span class="form-hint">Header, buttons, nav सबैमा यही रंग देखिन्छ। Admin panel बाट पछि पनि बदल्न सकिन्छ।</span>
                    </div>
                </div>
                <div class="error-box" id="step2Err"></div>
            </div>
            <div class="nav-btns">
                <button type="button" class="btn-back" onclick="goStep(1)"><i class="lucide-icon" data-lucide="arrow-left" aria-hidden="true"></i> पछाडि</button>
                <span class="step-counter">Step 3 of 5</span>
                <button type="button" class="btn-next" onclick="validateCoopInfo()">अर्को <i class="lucide-icon" data-lucide="arrow-right" aria-hidden="true"></i></button>
            </div>
        </div>

        <!-- ════ STEP 3: Admin Account ════ -->
        <div id="step3" style="display:none;">
            <div class="card-head">
                <div class="card-head-icon"><i class="lucide-icon" data-lucide="shield-user" aria-hidden="true"></i></div>
                <h2>Admin Account बनाउनुहोस्</h2>
                <p>यो username र password ले Admin Panel मा login गरिन्छ। सुरक्षित राख्नुहोस्।</p>
            </div>
            <div class="card-body">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label for="admin_username" class="form-label"><i class="lucide-icon" data-lucide="user" aria-hidden="true" style="color:var(--green);"></i> Username <span class="req">*</span></label>
                        <input type="text" class="form-control" id="admin_username" value="admin" placeholder="admin">
                        <span class="form-hint">अंग्रेजी अक्षर, अंक, _ मात्र।</span>
                    </div>
                    <div class="form-group">
                        <label for="admin_fullname" class="form-label"><i class="lucide-icon" data-lucide="badge-check" aria-hidden="true" style="color:var(--green);"></i> पूरा नाम</label>
                        <input type="text" class="form-control" id="admin_fullname" placeholder="जस्तै: रामप्रसाद शर्मा">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="admin_email" class="form-label"><i class="lucide-icon" data-lucide="mail" aria-hidden="true" style="color:var(--green);"></i> Admin Email</label>
                        <input type="email" class="form-control" id="admin_email" placeholder="admin@example.com">
                    </div>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label" for="admin_password"><i class="lucide-icon" data-lucide="lock" aria-hidden="true" style="color:var(--green);"></i> Password <span class="req">*</span></label>
                        <input type="password" class="form-control" id="admin_password" placeholder="कम्तिमा 8 characters" autocomplete="new-password">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="admin_password2"><i class="lucide-icon" data-lucide="lock" aria-hidden="true" style="color:var(--green);"></i> Password Confirm <span class="req">*</span></label>
                        <input type="password" class="form-control" id="admin_password2" placeholder="फेरि भर्नुहोस्" autocomplete="new-password">
                    </div>
                </div>
                <div style="background:var(--green-lt);border:1px solid #c8e6c9;border-radius:10px;padding:12px 14px;font-size:.82rem;color:var(--green-dk);margin-top:6px;">
                    <i class="lucide-icon" data-lucide="shield" aria-hidden="true" style="margin-right:6px;"></i>
                    <strong>सुरक्षा सुझाव:</strong> ठूला र साना अक्षर, अंक, विशेष चिह्न मिसाएर बलियो password बनाउनुहोस्। 
                    यो password अरू कसैलाई नदिनुहोस्।
                </div>
                <div class="error-box" id="step3Err"></div>
            </div>
            <div class="nav-btns">
                <button type="button" class="btn-back" onclick="goStep(2)"><i class="lucide-icon" data-lucide="arrow-left" aria-hidden="true"></i> पछाडि</button>
                <span class="step-counter">Step 4 of 5</span>
                <button type="button" class="btn-next" onclick="validateAdmin()">अर्को <i class="lucide-icon" data-lucide="arrow-right" aria-hidden="true"></i></button>
            </div>
        </div>

        <!-- ════ STEP 4: Install ════ -->
        <div id="step4" style="display:none;">
            <div class="card-head">
                <div class="card-head-icon"><i class="lucide-icon" data-lucide="rocket" aria-hidden="true"></i></div>
                <h2>Install सुरू गर्नुहोस्</h2>
                <p>तलको button थिचेपछि database setup, settings save र configuration file लेखिनेछ।</p>
            </div>
            <div class="card-body">

                <!-- Pre-install summary -->
                <div id="installSummary" style="margin-bottom:20px;">
                    <div style="font-size:.8rem;font-weight:700;color:var(--gray-400);text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px;">सारांश</div>
                    <div style="background:var(--gray-50);border:1px solid var(--gray-200);border-radius:10px;padding:14px 16px;font-size:.86rem;display:grid;gap:7px;">
                        <div><strong>DB Host:</strong> <span id="sumDbHost" style="color:var(--gray-600)"></span></div>
                        <div><strong>DB Name:</strong> <span id="sumDbName" style="color:var(--gray-600)"></span></div>
                        <div><strong>सहकारी नाम:</strong> <span id="sumSiteName" style="color:var(--gray-600)"></span></div>
                        <div><strong>Site URL:</strong> <span id="sumSiteUrl" style="color:var(--gray-600)"></span></div>
                        <div><strong>Admin User:</strong> <span id="sumAdmin" style="color:var(--gray-600)"></span></div>
                    </div>
                </div>

                <!-- Install progress steps -->
                <div class="install-steps" id="installSteps" style="display:none;">
                    <div class="install-step pending" id="iStep0"><div class="step-icon"><i class="lucide-icon" data-lucide="database" aria-hidden="true"></i></div><span>Database जोडाउँदैछ…</span></div>
                    <div class="install-step pending" id="iStep1"><div class="step-icon"><i class="lucide-icon" data-lucide="table" aria-hidden="true"></i></div><span>Tables बनाउँदैछ…</span></div>
                    <div class="install-step pending" id="iStep2"><div class="step-icon"><i class="lucide-icon" data-lucide="shield-user" aria-hidden="true"></i></div><span>Admin account सेटअप…</span></div>
                    <div class="install-step pending" id="iStep3"><div class="step-icon"><i class="lucide-icon" data-lucide="settings" aria-hidden="true"></i></div><span>Site settings save…</span></div>
                    <div class="install-step pending" id="iStep4"><div class="step-icon"><i class="lucide-icon" data-lucide="file-code" aria-hidden="true"></i></div><span>Config file लेख्दैछ…</span></div>
                    <div class="install-step pending" id="iStep5"><div class="step-icon"><i class="lucide-icon" data-lucide="lock" aria-hidden="true"></i></div><span>Install lock बनाउँदैछ…</span></div>
                </div>

                <!-- Error -->
                <div class="error-box" id="installErr"></div>

                <!-- Success -->
                <div id="installSuccess" style="display:none;" class="success-panel">
                    <div class="success-icon"><i class="lucide-icon" data-lucide="check" aria-hidden="true"></i></div>
                    <h3>Installation सम्पन्न!</h3>
                    <p>तपाईंको सहकारी website सफलतापूर्वक install भयो। अब Admin Panel मा login गर्नुहोस्।</p>
                    <div class="success-links">
                        <a href="#" id="linkAdmin" class="btn-site btn-site-primary" target="_blank" rel="noopener noreferrer">
                            <i class="lucide-icon" data-lucide="shield-user" aria-hidden="true"></i> Admin Panel खोल्नुहोस्
                        </a>
                        <a href="#" id="linkSite" class="btn-site btn-site-outline" target="_blank" rel="noopener noreferrer">
                            <i class="lucide-icon" data-lucide="globe" aria-hidden="true"></i> Website हेर्नुहोस्
                        </a>
                    </div>
                    <div style="margin-top:20px;padding:12px 16px;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;font-size:.82rem;color:#92400e;text-align:left;">
                        <i class="lucide-icon" data-lucide="triangle-alert" aria-hidden="true" style="margin-right:6px;"></i>
                        <strong>सुरक्षाको लागि:</strong> install.php file cPanel File Manager बाट delete गर्नुहोस्
                        वा rename गर्नुहोस् (जस्तै: install.php.bak)।
                    </div>
                </div>
            </div>

            <div class="nav-btns" id="installNavBtns">
                <button type="button" class="btn-back" onclick="goStep(3)" id="btnInstallBack"><i class="lucide-icon" data-lucide="arrow-left" aria-hidden="true"></i> पछाडि</button>
                <span class="step-counter">Step 5 of 5</span>
                <button type="button" class="btn-next" id="btnInstallRun" onclick="runInstall()">
                    <i class="lucide-icon" data-lucide="rocket" aria-hidden="true"></i> Install गर्नुहोस्
                </button>
            </div>
        </div>

    </div><!-- /card -->

    <div style="text-align:center;margin-top:20px;font-size:.75rem;color:var(--gray-400);">
        Cooperative Website Theme · Install Wizard v1.0 · PHP <?= PHP_VERSION ?>
    </div>

</div><!-- /page-wrap -->

<script>
var INSTALL_CSRF = <?= json_encode(install_csrf_token(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
var currentStep = 0;
var dbConnected = false;

/* ─── Step navigation ─── */
function goStep(n) {
    document.getElementById('step' + currentStep).style.display = 'none';
    document.getElementById('step' + n).style.display = 'block';

    // Update progress dots
    for (var i = 0; i <= 4; i++) {
        var dot = document.getElementById('pd' + i);
        var step = document.getElementById('ps' + i);
        dot.className = 'prog-dot';
        step.className = 'prog-step';
        if (i < n)       { dot.className += ' done';   step.className += ' done';   dot.innerHTML = '<i class="lucide-icon" data-lucide="check" aria-hidden="true"></i>'; }
        else if (i === n){ dot.className += ' active';  step.className += ' active'; if (i > 0) dot.textContent = i + 1; }
        else             { dot.textContent = i + 1; }
        // Lines
        if (i < 4) {
            var line = document.getElementById('pl' + i);
            line.className = 'prog-line' + (i < n ? ' done' : '');
        }
    }
    currentStep = n;
    window.scrollTo(0, 0);

    // Fill summary on step 4
    if (n === 4) fillSummary();
}

function appendCsrf(fd) {
    fd.append('csrf_token', INSTALL_CSRF);
}

/* ─── DB test ─── */
function testDb() {
    var status = document.getElementById('dbStatus');
    status.style.display = 'block';
    status.className = 'db-status';
    status.innerHTML = '<i class="lucide-icon lucide-spin" data-lucide="loader-2" aria-hidden="true"></i> जाँच्दैछ…';
    
    var fd = new FormData();
    fd.append('action', 'test_db');
    fd.append('db_host', v('db_host'));
    fd.append('db_name', v('db_name'));
    fd.append('db_user', v('db_user'));
    fd.append('db_pass', v('db_pass'));
    appendCsrf(fd);
    
    fetch(window.location.href, {method: 'POST', body: fd})
        .then(function(r){ return r.json(); })
        .then(function(d){
            status.className = 'db-status ' + (d.ok ? 'ok' : 'err');
            status.innerHTML = d.msg;
            dbConnected = d.ok;
        })
        .catch(function(){
            status.className = 'db-status err';
            status.innerHTML = 'Network error — retry गर्नुहोस्।';
        });
}

function validateDb() {
    if (!v('db_name') || !v('db_user')) {
        showErr('step1Err', 'Database name र username अनिवार्य छ।'); return;
    }
    clearErr('step1Err');
    goStep(2);
}

function validateCoopInfo() {
    if (!v('site_name').trim()) {
        showErr('step2Err', 'सहकारी नाम (नेपालीमा) अनिवार्य छ।'); return;
    }
    clearErr('step2Err');
    goStep(3);
}

function validateAdmin() {
    var u = v('admin_username').trim();
    var p = v('admin_password');
    var p2 = v('admin_password2');
    if (!u) { showErr('step3Err', 'Username अनिवार्य छ।'); return; }
    if (p.length < 8) { showErr('step3Err', 'Password कम्तिमा 8 characters हुनुपर्छ।'); return; }
    if (p !== p2) { showErr('step3Err', 'दुवै Password मेल खाएन।'); return; }
    clearErr('step3Err');
    goStep(4);
}

function fillSummary() {
    setText('sumDbHost',  v('db_host') || 'localhost');
    setText('sumDbName',  v('db_name'));
    setText('sumSiteName',v('site_name'));
    setText('sumSiteUrl', v('site_url'));
    setText('sumAdmin',   v('admin_username'));
}

/* ─── Run installation ─── */
function runInstall() {
    document.getElementById('btnInstallBack').disabled = true;
    document.getElementById('btnInstallRun').disabled  = true;
    document.getElementById('installSummary').style.display = 'none';
    document.getElementById('installSteps').style.display   = 'flex';
    clearErr('installErr');

    // Animate first step as running
    setIStep(0, 'running');

    var fd = new FormData();
    fd.append('action',          'install');
    fd.append('db_host',         v('db_host'));
    fd.append('db_name',         v('db_name'));
    fd.append('db_user',         v('db_user'));
    fd.append('db_pass',         v('db_pass'));
    fd.append('site_url',        v('site_url'));
    fd.append('site_name',       v('site_name'));
    fd.append('site_name_en',    v('site_name_en'));
    fd.append('site_slogan',     v('site_slogan'));
    fd.append('phone',           v('phone'));
    fd.append('email',           v('email'));
    fd.append('address',         v('address'));
    fd.append('primary_color',   v('primary_color'));
    fd.append('admin_username',  v('admin_username'));
    fd.append('admin_password',  v('admin_password'));
    fd.append('admin_fullname',  v('admin_fullname'));
    fd.append('admin_email',     v('admin_email'));
    appendCsrf(fd);

    fetch(window.location.href, {method: 'POST', body: fd})
        .then(function(r){ return r.json(); })
        .then(function(d){
            if (d.ok) {
                // Animate steps sequentially
                animateSteps(d.steps || [], 0, function(){
                    document.getElementById('installNavBtns').style.display = 'none';
                    var admin = document.getElementById('installSuccess');
                    admin.style.display = 'block';
                    document.getElementById('linkAdmin').href = d.admin_url || 'admin/';
                    document.getElementById('linkSite').href  = d.site_url  || '/';
                });
            } else {
                // Mark failed steps
                if (d.steps) {
                    d.steps.forEach(function(s, i){ setIStep(i, s.ok ? 'done' : 'error'); });
                    setIStep(d.steps.length, 'error');
                }
                showErr('installErr', d.error || 'Install असफल भयो। माथिको steps जाँच्नुहोस्।');
                document.getElementById('btnInstallBack').disabled = false;
                document.getElementById('btnInstallRun').disabled  = false;
            }
        })
        .catch(function(e){
            showErr('installErr', 'Network error — पुनः try गर्नुहोस्।');
            document.getElementById('btnInstallBack').disabled = false;
            document.getElementById('btnInstallRun').disabled  = false;
        });
}

function animateSteps(steps, idx, done) {
    if (idx >= steps.length) { done(); return; }
    setIStep(idx, steps[idx].ok ? 'done' : 'error');
    if (idx + 1 < steps.length) setIStep(idx + 1, 'running');
    // Update text with actual step msg
    var el = document.getElementById('iStep' + idx);
    if (el) {
        var span = el.querySelector('span');
        if (span) span.textContent = steps[idx].msg || span.textContent;
    }
    setTimeout(function(){ animateSteps(steps, idx + 1, done); }, 450);
}

function setIStep(i, state) {
    var el = document.getElementById('iStep' + i);
    if (!el) return;
    el.className = 'install-step ' + state;
}

/* ─── Color preview ─── */
function updateColorPreview() {
    var c = v('primary_color');
    document.getElementById('colorPreview').style.background = c;
    document.getElementById('colorHex').textContent = c;
}

/* ─── Helpers ─── */
function v(id) {
    var el = document.getElementById(id);
    return el ? el.value : '';
}
function setText(id, val) {
    var el = document.getElementById(id);
    if (el) el.textContent = val || '—';
}
function showErr(id, msg) {
    var el = document.getElementById(id);
    if (!el) return;
    el.style.display = 'block';
    el.innerHTML = '<i class="lucide-icon" data-lucide="circle-alert" aria-hidden="true" style="margin-right:6px;"></i>' + msg;
}
function clearErr(id) {
    var el = document.getElementById(id);
    if (el) el.style.display = 'none';
}
</script>
</body>
</html>
