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
if (($installLockExists || $localDbConfigExists) && !$allowInstallRerun) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Install wizard is disabled for security. Use admin/db-setup.php for maintenance.";
    exit;
}

session_start();

/* ─────────────────────────── AJAX: test DB connection ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_db') {
    header('Content-Type: application/json; charset=utf-8');
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

<link href="https://fonts.googleapis.com/css2?family=Mukta:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
    --green:       #1a5f2a;
    --green-dk:    #114020;
    --green-lt:    #e8f5e9;
    --green-glow:  #2e8b4a;
    --red:         #dc2626;
    --amber:       #d97706;
    --blue:        #1565c0;
    --gray-50:     #f9fafb;
    --gray-100:    #f3f4f6;
    --gray-200:    #e5e7eb;
    --gray-400:    #9ca3af;
    --gray-600:    #4b5563;
    --gray-700:    #374151;
    --gray-900:    #111827;
    --radius:      12px;
    --shadow:      0 4px 24px rgba(0,0,0,.10);
    --shadow-lg:   0 8px 40px rgba(0,0,0,.14);
}
html { scroll-behavior: smooth; }
body {
    font-family: 'Mukta', 'Noto Sans Devanagari', sans-serif;
    background: linear-gradient(135deg, #f0f9f2 0%, #e8f5e9 50%, #f0f4f8 100%);
    min-height: 100vh;
    color: var(--gray-900);
    line-height: 1.6;
}

/* ─── Page shell ─── */
.page-wrap {
    max-width: 720px;
    margin: 0 auto;
    padding: 24px 16px 60px;
}

/* ─── Branding header ─── */
.brand-header {
    text-align: center;
    padding: 32px 0 24px;
}
.brand-logo {
    width: 64px; height: 64px; border-radius: 18px;
    background: linear-gradient(135deg, var(--green) 0%, var(--green-glow) 100%);
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 28px; color: #fff;
    box-shadow: 0 8px 24px rgba(26,95,42,.35);
    margin-bottom: 14px;
}
.brand-title {
    font-size: 1.7rem; font-weight: 800; color: var(--green-dk);
    letter-spacing: -.01em;
}
.brand-sub {
    font-size: .9rem; color: var(--gray-600); margin-top: 4px;
}

/* ─── Progress bar ─── */
.progress-track {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0;
    margin: 0 0 28px;
    padding: 0 8px;
}
.prog-step {
    display: flex; flex-direction: column; align-items: center;
    gap: 6px; flex: 1; min-width: 0;
}
.prog-dot {
    width: 36px; height: 36px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: .8rem; font-weight: 700;
    border: 2px solid var(--gray-200);
    background: #fff; color: var(--gray-400);
    transition: all .3s ease; position: relative; z-index: 1;
}
.prog-dot.done  { background: var(--green); border-color: var(--green); color: #fff; }
.prog-dot.active{ background: var(--green-dk); border-color: var(--green-dk); color: #fff;
    box-shadow: 0 0 0 4px rgba(26,95,42,.18); }
.prog-label {
    font-size: .68rem; color: var(--gray-400); text-align: center;
    font-weight: 500; line-height: 1.2;
    transition: color .3s;
}
.prog-step.active .prog-label { color: var(--green-dk); font-weight: 700; }
.prog-step.done  .prog-label { color: var(--green); }
.prog-line {
    flex: 1; height: 2px;
    background: var(--gray-200);
    margin-top: -22px; /* align with dot centers */
    position: relative; z-index: 0;
    min-width: 12px;
    transition: background .3s;
}
.prog-line.done { background: var(--green); }

/* ─── Card ─── */
.card {
    background: #fff;
    border-radius: 18px;
    box-shadow: var(--shadow-lg);
    overflow: hidden;
}
.card-head {
    padding: 24px 28px 0;
    border-bottom: 1px solid var(--gray-100);
    padding-bottom: 18px;
    background: linear-gradient(135deg, var(--green-lt) 0%, #fff 100%);
}
.card-head-icon {
    width: 44px; height: 44px; border-radius: 12px;
    background: linear-gradient(135deg, var(--green) 0%, var(--green-glow) 100%);
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 1.1rem; margin-bottom: 10px;
}
.card-head h2 { font-size: 1.25rem; font-weight: 700; color: var(--green-dk); }
.card-head p  { font-size: .88rem; color: var(--gray-600); margin-top: 3px; }
.card-body { padding: 24px 28px; }

/* ─── Form elements ─── */
.form-row { display: grid; gap: 16px; margin-bottom: 16px; }
.form-row.cols-2 { grid-template-columns: 1fr 1fr; }
@media (max-width: 520px) { .form-row.cols-2 { grid-template-columns: 1fr; } }
.form-group { display: flex; flex-direction: column; gap: 5px; }
.form-label {
    font-size: .82rem; font-weight: 600; color: var(--gray-700);
    display: flex; align-items: center; gap: 6px;
}
.form-label .req { color: var(--red); }
.form-control {
    padding: 10px 13px; border-radius: 9px;
    border: 1.5px solid var(--gray-200);
    font-family: inherit; font-size: .9rem; color: var(--gray-900);
    background: var(--gray-50);
    transition: border .2s, box-shadow .2s;
    width: 100%;
}
.form-control:focus {
    outline: none; border-color: var(--green);
    box-shadow: 0 0 0 3px rgba(26,95,42,.12);
    background: #fff;
}
.form-hint { font-size: .74rem; color: var(--gray-400); }
.form-hint a { color: var(--green); }

/* Color picker row */
.color-row { display: flex; align-items: center; gap: 10px; }
.color-row input[type=color] {
    width: 48px; height: 40px; padding: 2px 4px;
    border-radius: 8px; border: 1.5px solid var(--gray-200);
    cursor: pointer; background: #fff;
}
.color-preview {
    flex: 1; height: 40px; border-radius: 9px;
    font-size: .82rem; display: flex; align-items: center;
    padding: 0 12px; color: #fff; font-weight: 600;
    transition: background .2s;
}

/* DB test button */
.db-test-row { display: flex; gap: 10px; align-items: flex-end; }
.db-test-row .form-group { flex: 1; }
.btn-test {
    padding: 10px 18px; border-radius: 9px;
    background: var(--gray-100); border: 1.5px solid var(--gray-200);
    font-family: inherit; font-size: .85rem; font-weight: 600;
    color: var(--gray-700); cursor: pointer; white-space: nowrap;
    transition: all .15s;
    height: 42px;
}
.btn-test:hover { background: var(--gray-200); }
.db-status { margin-top: 10px; padding: 9px 13px; border-radius: 8px; font-size: .84rem; display: none; }
.db-status.ok  { background: var(--green-lt); color: var(--green-dk); border: 1px solid #c8e6c9; }
.db-status.err { background: #fef2f2; color: var(--red); border: 1px solid #fecaca; }

/* ─── Check table ─── */
.check-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
.check-table th {
    text-align: left; padding: 10px 12px; font-size: .75rem;
    text-transform: uppercase; letter-spacing: .05em; color: var(--gray-400);
    border-bottom: 2px solid var(--gray-100);
}
.check-table td { padding: 10px 12px; border-bottom: 1px solid var(--gray-100); }
.check-table tr:last-child td { border-bottom: none; }
.badge-ok  { display: inline-flex; align-items: center; gap: 5px; color: var(--green); font-weight: 600; }
.badge-err { display: inline-flex; align-items: center; gap: 5px; color: var(--red);   font-weight: 600; }
.badge-warn{ display: inline-flex; align-items: center; gap: 5px; color: var(--amber); font-weight: 600; }
.check-warning {
    background: #fffbeb; border: 1px solid #fde68a; border-radius: 10px;
    padding: 12px 16px; margin-top: 14px; font-size: .84rem; color: #92400e;
    display: flex; gap: 10px; align-items: flex-start;
}

/* ─── Install progress ─── */
.install-steps { display: flex; flex-direction: column; gap: 10px; margin-bottom: 20px; }
.install-step  {
    display: flex; align-items: center; gap: 12px;
    padding: 11px 14px; border-radius: 10px;
    background: var(--gray-50); border: 1px solid var(--gray-200);
    font-size: .88rem;
    transition: all .3s;
}
.install-step.pending { opacity: .45; }
.install-step.running { background: #eff6ff; border-color: #bfdbfe; }
.install-step.done    { background: var(--green-lt); border-color: #c8e6c9; }
.install-step.error   { background: #fef2f2; border-color: #fecaca; color: var(--red); }
.install-step .step-icon {
    width: 30px; height: 30px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: .8rem; flex-shrink: 0;
    background: var(--gray-200); color: var(--gray-400);
}
.install-step.done  .step-icon { background: var(--green); color: #fff; }
.install-step.error .step-icon { background: var(--red); color: #fff; }
.install-step.running .step-icon { background: var(--blue); color: #fff; animation: pulse 1s infinite; }

@keyframes pulse {
    0%,100% { transform: scale(1); opacity: 1; }
    50%      { transform: scale(1.15); opacity: .8; }
}

/* Success panel */
.success-panel {
    text-align: center; padding: 12px 0 8px;
}
.success-icon {
    width: 72px; height: 72px; border-radius: 50%;
    background: linear-gradient(135deg, var(--green) 0%, var(--green-glow) 100%);
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 2rem; color: #fff; margin-bottom: 16px;
    box-shadow: 0 8px 24px rgba(26,95,42,.35);
}
.success-panel h3 { font-size: 1.5rem; font-weight: 800; color: var(--green-dk); margin-bottom: 6px; }
.success-panel p  { font-size: .9rem; color: var(--gray-600); max-width: 400px; margin: 0 auto 20px; }
.success-links { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
.btn-site {
    padding: 12px 28px; border-radius: 10px;
    font-family: inherit; font-size: .9rem; font-weight: 700;
    cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 8px;
    transition: all .2s;
}
.btn-site-primary {
    background: linear-gradient(135deg, var(--green) 0%, var(--green-glow) 100%);
    color: #fff; box-shadow: 0 4px 14px rgba(26,95,42,.35);
}
.btn-site-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(26,95,42,.4); }
.btn-site-outline {
    background: #fff; color: var(--green-dk);
    border: 2px solid var(--green);
}
.btn-site-outline:hover { background: var(--green-lt); }

/* ─── Navigation buttons ─── */
.nav-btns {
    display: flex; justify-content: space-between; align-items: center;
    padding: 18px 28px; background: var(--gray-50);
    border-top: 1px solid var(--gray-100);
}
.btn-back, .btn-next {
    padding: 11px 26px; border-radius: 10px;
    font-family: inherit; font-size: .92rem; font-weight: 700;
    cursor: pointer; border: none;
    display: flex; align-items: center; gap: 8px;
    transition: all .2s;
}
.btn-back {
    background: #fff; color: var(--gray-700);
    border: 1.5px solid var(--gray-200);
}
.btn-back:hover { background: var(--gray-100); }
.btn-next {
    background: linear-gradient(135deg, var(--green) 0%, var(--green-glow) 100%);
    color: #fff; box-shadow: 0 4px 14px rgba(26,95,42,.3);
}
.btn-next:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(26,95,42,.4); }
.btn-next:disabled { opacity: .5; cursor: not-allowed; transform: none; box-shadow: none; }
.step-counter { font-size: .78rem; color: var(--gray-400); font-weight: 600; }

/* ─── Error panel ─── */
.error-box {
    background: #fef2f2; border: 1px solid #fecaca; border-radius: 10px;
    padding: 14px 16px; margin-top: 16px; font-size: .85rem; color: var(--red);
    display: none;
}

/* ─── Responsive ─── */
@media (max-width: 480px) {
    .card-body, .card-head { padding: 18px 18px; }
    .nav-btns { padding: 14px 18px; }
    .brand-title { font-size: 1.4rem; }
}
</style>
</head>
<body>

<div class="page-wrap">

    <!-- Brand header -->
    <div class="brand-header">
        <div class="brand-logo"><i class="fas fa-seedling"></i></div>
        <div class="brand-title">सहकारी Website Setup</div>
        <div class="brand-sub">Cooperative Website — One-Time Install Wizard · v1.0</div>
    </div>

    <!-- Progress steps -->
    <div class="progress-track" id="progressTrack">
        <div class="prog-step active" id="ps0">
            <div class="prog-dot active" id="pd0"><i class="fas fa-check-circle"></i></div>
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
                <div class="card-head-icon"><i class="fas fa-server"></i></div>
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
                                    <span class="badge-ok"><i class="fas fa-circle-check"></i> ठीक</span>
                                <?php else: ?>
                                    <span class="badge-err"><i class="fas fa-circle-xmark"></i> समस्या</span>
                                <?php endif; ?>
                            </td>
                            <td style="color:var(--gray-600);font-size:.82rem;"><?= htmlspecialchars($chk['val']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if (!$allChecksPass): ?>
                <div class="check-warning">
                    <i class="fas fa-triangle-exclamation" style="font-size:1.1rem;flex-shrink:0;margin-top:2px;"></i>
                    <div>
                        <strong>कुनै आवश्यकता पूरा भएन।</strong><br>
                        <span style="font-size:.82rem;">माथि रातो देखाएका items आफ्नो hosting provider सँग ठीक गर्नुहोस् अनि पुनः try गर्नुहोस्।
                        अधिकांश cPanel hosting मा PDO MySQL पहिले नै available हुन्छ।</span>
                    </div>
                </div>
                <?php else: ?>
                <div style="margin-top:14px;padding:11px 14px;background:var(--green-lt);border:1px solid #c8e6c9;border-radius:10px;font-size:.85rem;color:var(--green-dk);display:flex;gap:8px;align-items:center;">
                    <i class="fas fa-circle-check"></i>
                    <strong>सबै आवश्यकताहरू पूरा भए!</strong> अगाडि बढ्नुहोस्।
                </div>
                <?php endif; ?>
            </div>
            <div class="nav-btns">
                <span class="step-counter">Step 1 of 5</span>
                <button class="btn-next" onclick="goStep(1)" <?= !$allChecksPass ? 'disabled' : '' ?>>
                    अर्को <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>

        <!-- ════ STEP 1: Database ════ -->
        <div id="step1" style="display:none;">
            <div class="card-head">
                <div class="card-head-icon"><i class="fas fa-database"></i></div>
                <h2>Database जोडाउनुहोस्</h2>
                <p>cPanel मा बनाएको database को जानकारी भर्नुहोस्। यो hosting provider ले दिएको हुन्छ।</p>
            </div>
            <div class="card-body">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-server" style="color:var(--green);"></i> DB Host <span class="req">*</span></label>
                        <input type="text" class="form-control" id="db_host" value="localhost" placeholder="localhost">
                        <span class="form-hint">प्रायः <code>localhost</code> नै हुन्छ।</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-database" style="color:var(--green);"></i> Database Name <span class="req">*</span></label>
                        <input type="text" class="form-control" id="db_name" placeholder="cpanel_dbname">
                        <span class="form-hint">cPanel → MySQL Databases मा बनाएको नाम।</span>
                    </div>
                </div>
                <div class="db-test-row" style="gap:12px;display:grid;grid-template-columns:1fr 1fr;">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-user" style="color:var(--green);"></i> DB Username <span class="req">*</span></label>
                        <input type="text" class="form-control" id="db_user" placeholder="cpanel_dbuser">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-lock" style="color:var(--green);"></i> DB Password</label>
                        <input type="password" class="form-control" id="db_pass" placeholder="••••••••">
                    </div>
                </div>
                <button type="button" class="btn-test" style="margin-top:6px;" onclick="testDb()">
                    <i class="fas fa-plug"></i> Connection Test गर्नुहोस्
                </button>
                <div class="db-status" id="dbStatus"></div>
                <div class="error-box" id="step1Err"></div>
            </div>
            <div class="nav-btns">
                <button class="btn-back" onclick="goStep(0)"><i class="fas fa-arrow-left"></i> पछाडि</button>
                <span class="step-counter">Step 2 of 5</span>
                <button class="btn-next" onclick="validateDb()">अर्को <i class="fas fa-arrow-right"></i></button>
            </div>
        </div>

        <!-- ════ STEP 2: Cooperative Info ════ -->
        <div id="step2" style="display:none;">
            <div class="card-head">
                <div class="card-head-icon"><i class="fas fa-building-columns"></i></div>
                <h2>सहकारी जानकारी भर्नुहोस्</h2>
                <p>तपाईंको सहकारीको नाम, सम्पर्क र प्राथमिक रंग सेट गर्नुहोस्। पछि Admin Panel बाट पनि बदल्न सकिन्छ।</p>
            </div>
            <div class="card-body">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-font" style="color:var(--green);"></i> नाम (नेपालीमा) <span class="req">*</span></label>
                        <input type="text" class="form-control" id="site_name" placeholder="जस्तै: सूर्योदय बचत तथा ऋण सहकारी">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-font" style="color:var(--green);"></i> Name (English)</label>
                        <input type="text" class="form-control" id="site_name_en" placeholder="Suryadaya S&C Cooperative">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-quote-left" style="color:var(--green);"></i> Slogan / नारा</label>
                        <input type="text" class="form-control" id="site_slogan" placeholder="जस्तै: समुदायको विश्वासिलो साथी">
                    </div>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-phone" style="color:var(--green);"></i> फोन नम्बर</label>
                        <input type="text" class="form-control" id="phone" placeholder="061-590067">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-envelope" style="color:var(--green);"></i> Email</label>
                        <input type="email" class="form-control" id="email" placeholder="info@example.com">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-location-dot" style="color:var(--green);"></i> ठेगाना</label>
                        <input type="text" class="form-control" id="address" placeholder="जस्तै: पोखरा, कास्की, गण्डकी प्रदेश">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-globe" style="color:var(--green);"></i> Website URL</label>
                        <input type="url" class="form-control" id="site_url" value="<?= htmlspecialchars($guessUrl) ?>" placeholder="https://yourdomain.com/">
                        <span class="form-hint">पूरा URL domain सहित (https:// वा http://)।</span>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-palette" style="color:var(--green);"></i> Primary Color (मुख्य रंग)</label>
                        <div class="color-row">
                            <input type="color" id="primary_color" value="#1a5f2a" onchange="updateColorPreview()">
                            <div class="color-preview" id="colorPreview" style="background:#1a5f2a;">
                                <i class="fas fa-circle-half-stroke" style="margin-right:6px;"></i>
                                <span id="colorHex">#1a5f2a</span> — तपाईंको brand रंग
                            </div>
                        </div>
                        <span class="form-hint">Header, buttons, nav सबैमा यही रंग देखिन्छ। Admin panel बाट पछि पनि बदल्न सकिन्छ।</span>
                    </div>
                </div>
                <div class="error-box" id="step2Err"></div>
            </div>
            <div class="nav-btns">
                <button class="btn-back" onclick="goStep(1)"><i class="fas fa-arrow-left"></i> पछाडि</button>
                <span class="step-counter">Step 3 of 5</span>
                <button class="btn-next" onclick="validateCoopInfo()">अर्को <i class="fas fa-arrow-right"></i></button>
            </div>
        </div>

        <!-- ════ STEP 3: Admin Account ════ -->
        <div id="step3" style="display:none;">
            <div class="card-head">
                <div class="card-head-icon"><i class="fas fa-user-shield"></i></div>
                <h2>Admin Account बनाउनुहोस्</h2>
                <p>यो username र password ले Admin Panel मा login गरिन्छ। सुरक्षित राख्नुहोस्।</p>
            </div>
            <div class="card-body">
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-user" style="color:var(--green);"></i> Username <span class="req">*</span></label>
                        <input type="text" class="form-control" id="admin_username" value="admin" placeholder="admin">
                        <span class="form-hint">अंग्रेजी अक्षर, अंक, _ मात्र।</span>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-id-badge" style="color:var(--green);"></i> पूरा नाम</label>
                        <input type="text" class="form-control" id="admin_fullname" placeholder="जस्तै: रामप्रसाद शर्मा">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-envelope" style="color:var(--green);"></i> Admin Email</label>
                        <input type="email" class="form-control" id="admin_email" placeholder="admin@example.com">
                    </div>
                </div>
                <div class="form-row cols-2">
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-lock" style="color:var(--green);"></i> Password <span class="req">*</span></label>
                        <input type="password" class="form-control" id="admin_password" placeholder="कम्तिमा 8 characters">
                    </div>
                    <div class="form-group">
                        <label class="form-label"><i class="fas fa-lock" style="color:var(--green);"></i> Password Confirm <span class="req">*</span></label>
                        <input type="password" class="form-control" id="admin_password2" placeholder="फेरि भर्नुहोस्">
                    </div>
                </div>
                <div style="background:var(--green-lt);border:1px solid #c8e6c9;border-radius:10px;padding:12px 14px;font-size:.82rem;color:var(--green-dk);margin-top:6px;">
                    <i class="fas fa-shield-halved" style="margin-right:6px;"></i>
                    <strong>सुरक्षा सुझाव:</strong> ठूला र साना अक्षर, अंक, विशेष चिह्न मिसाएर बलियो password बनाउनुहोस्। 
                    यो password अरू कसैलाई नदिनुहोस्।
                </div>
                <div class="error-box" id="step3Err"></div>
            </div>
            <div class="nav-btns">
                <button class="btn-back" onclick="goStep(2)"><i class="fas fa-arrow-left"></i> पछाडि</button>
                <span class="step-counter">Step 4 of 5</span>
                <button class="btn-next" onclick="validateAdmin()">अर्को <i class="fas fa-arrow-right"></i></button>
            </div>
        </div>

        <!-- ════ STEP 4: Install ════ -->
        <div id="step4" style="display:none;">
            <div class="card-head">
                <div class="card-head-icon"><i class="fas fa-rocket"></i></div>
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
                    <div class="install-step pending" id="iStep0"><div class="step-icon"><i class="fas fa-database"></i></div><span>Database जोडाउँदैछ…</span></div>
                    <div class="install-step pending" id="iStep1"><div class="step-icon"><i class="fas fa-table"></i></div><span>Tables बनाउँदैछ…</span></div>
                    <div class="install-step pending" id="iStep2"><div class="step-icon"><i class="fas fa-user-shield"></i></div><span>Admin account सेटअप…</span></div>
                    <div class="install-step pending" id="iStep3"><div class="step-icon"><i class="fas fa-gear"></i></div><span>Site settings save…</span></div>
                    <div class="install-step pending" id="iStep4"><div class="step-icon"><i class="fas fa-file-code"></i></div><span>Config file लेख्दैछ…</span></div>
                    <div class="install-step pending" id="iStep5"><div class="step-icon"><i class="fas fa-lock"></i></div><span>Install lock बनाउँदैछ…</span></div>
                </div>

                <!-- Error -->
                <div class="error-box" id="installErr"></div>

                <!-- Success -->
                <div id="installSuccess" style="display:none;" class="success-panel">
                    <div class="success-icon"><i class="fas fa-check"></i></div>
                    <h3>🎉 Installation सम्पन्न!</h3>
                    <p>तपाईंको सहकारी website सफलतापूर्वक install भयो। अब Admin Panel मा login गर्नुहोस्।</p>
                    <div class="success-links">
                        <a href="#" id="linkAdmin" class="btn-site btn-site-primary" target="_blank">
                            <i class="fas fa-user-shield"></i> Admin Panel खोल्नुहोस्
                        </a>
                        <a href="#" id="linkSite" class="btn-site btn-site-outline" target="_blank">
                            <i class="fas fa-globe"></i> Website हेर्नुहोस्
                        </a>
                    </div>
                    <div style="margin-top:20px;padding:12px 16px;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;font-size:.82rem;color:#92400e;text-align:left;">
                        <i class="fas fa-triangle-exclamation" style="margin-right:6px;"></i>
                        <strong>सुरक्षाको लागि:</strong> install.php file cPanel File Manager बाट delete गर्नुहोस्
                        वा rename गर्नुहोस् (जस्तै: install.php.bak)।
                    </div>
                </div>
            </div>

            <div class="nav-btns" id="installNavBtns">
                <button class="btn-back" onclick="goStep(3)" id="btnInstallBack"><i class="fas fa-arrow-left"></i> पछाडि</button>
                <span class="step-counter">Step 5 of 5</span>
                <button class="btn-next" id="btnInstallRun" onclick="runInstall()">
                    <i class="fas fa-rocket"></i> Install गर्नुहोस्
                </button>
            </div>
        </div>

    </div><!-- /card -->

    <div style="text-align:center;margin-top:20px;font-size:.75rem;color:var(--gray-400);">
        Cooperative Website Theme · Install Wizard v1.0 · PHP <?= PHP_VERSION ?>
    </div>

</div><!-- /page-wrap -->

<script>
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
        if (i < n)       { dot.className += ' done';   step.className += ' done';   dot.innerHTML = '<i class="fas fa-check"></i>'; }
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

/* ─── DB test ─── */
function testDb() {
    var status = document.getElementById('dbStatus');
    status.style.display = 'block';
    status.className = 'db-status';
    status.innerHTML = '<i class="fas fa-spinner fa-spin"></i> जाँच्दैछ…';
    
    var fd = new FormData();
    fd.append('action', 'test_db');
    fd.append('db_host', v('db_host'));
    fd.append('db_name', v('db_name'));
    fd.append('db_user', v('db_user'));
    fd.append('db_pass', v('db_pass'));
    
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
    el.innerHTML = '<i class="fas fa-circle-exclamation" style="margin-right:6px;"></i>' + msg;
}
function clearErr(id) {
    var el = document.getElementById(id);
    if (el) el.style.display = 'none';
}
</script>
</body>
</html>
