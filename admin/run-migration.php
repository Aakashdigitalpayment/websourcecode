<?php
/**
 * =====================================================
 * ADMIN: DATABASE MIGRATION RUNNER
 * Database Update Admin बाट नै run गर्न सकिन्छ
 *
 * यो page ले database/install.sql (र upload गरिएको .sql) execute गर्छ
 * cPanel मा नगई admin panel बाटै database update गर्न सकिन्छ
 * =====================================================
 */
$pageTitle = 'Database Migration';
$currentPage = 'run-migration'; /* admin nav मा active highlight को लागि */
require_once 'includes/admin-header.php';

/* Superadmin मात्र — DB migration sensitive operation हो */
if (empty($_SESSION['is_superadmin'])) {
    setFlash('error', 'यो page केवल Superadmin ले access गर्न सक्छ।');
    redirect(ADMIN_URL . 'dashboard.php');
    exit();
}

// =====================================================
// Migration files को list
/* v6+ Consolidated: एकमात्र install.sql ले सबै tables, indexes, audit, credentials,
   member-portal, notification templates, र role columns थप्छ। */
$migrationFiles = [
    [
        'file'        => '../database/install.sql',
        'label'       => '⭐ Complete Database Setup (Single File)',
        'description' => 'सबै tables, indexes (v10.7 listing indexes समेत), audit, member portal, notifications, roles, credential vault — एकै file। Idempotent।',
        'safe'        => true,
    ],
];

$result   = '';
$resultOk = false;
$runFile  = '';
/* Testing-safe mode: custom SQL terminal-like runner disable */
$allowCustomSql = false;

/**
 * SQL splitter — `DELIMITER` blocks (stored procedures) handle गर्छ।
 * Naive explode(';',$sql) ले BEGIN…END procedure body तोड्छ — यसले बच्छ।
 *
 * @return array पूरै statements (DELIMITER lines हटाएर)
 */
/* splitSqlStatements() → see admin/includes/sql-utils.php (included by db-setup.php first) */
if (!function_exists('splitSqlStatements')) { require_once __DIR__ . '/includes/sql-utils.php'; }

/* SQL File Upload को लागि variables */
$uploadResult   = '';
$uploadResultOk = false;

/* =====================================================
   POST: Computer बाट upload गरिएको SQL file run गर्ने
   (phpMyAdmin बिना नै .sql file run गर्न सकिन्छ)
===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_uploaded_sql'])) {
    if (!verifyCSRFToken()) {
        $uploadResult = '<div class="alert alert-danger"><i class="fas fa-ban me-2"></i>सुरक्षा जाँच असफल। पुन: प्रयास गर्नुहोस्।</div>';
    } elseif (empty($_FILES['sql_file']) || $_FILES['sql_file']['error'] !== UPLOAD_ERR_OK) {
        $errCode = $_FILES['sql_file']['error'] ?? 99;
        if ($errCode === UPLOAD_ERR_INI_SIZE || $errCode === UPLOAD_ERR_FORM_SIZE) {
            $errMsg = 'File size धेरै ठूलो छ।';
        } elseif ($errCode === UPLOAD_ERR_NO_FILE) {
            $errMsg = '.sql file choose गर्नुहोस्।';
        } else {
            $errMsg = 'Upload असफल (error code: ' . $errCode . ')';
        }
        $uploadResult = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-2"></i>' . $errMsg . '</div>';
    } else {
        $file = $_FILES['sql_file'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if ($ext !== 'sql') {
            $uploadResult = '<div class="alert alert-danger"><i class="fas fa-ban me-2"></i>केवल <strong>.sql</strong> file मात्र upload गर्न सकिन्छ।</div>';
        } elseif ($file['size'] > 20 * 1024 * 1024) { /* 20MB limit */
            $uploadResult = '<div class="alert alert-danger"><i class="fas fa-ban me-2"></i>File 20MB भन्दा बढी हुनु भएन।</div>';
        } elseif ($file['size'] === 0) {
            $uploadResult = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-2"></i>Upload गरिएको file खाली छ।</div>';
        } else {
            /* File memory मा पढ्छु — server मा save गर्दिन (security) */
            $sql = file_get_contents($file['tmp_name']);

            if (empty(trim($sql))) {
                $uploadResult = '<div class="alert alert-warning">SQL file खाली छ।</div>';
            } else {
                try {
                    $db = getDB();

                    /* Comments हटाउँछु, statements split गर्छु */
                    $raw   = preg_replace('/--[^\n]*\n/u', "\n", $sql);
                    $parts = splitSqlStatements($raw);

                    $ok       = 0;
                    $skipped  = 0;
                    $errCount = 0;
                    $errors   = [];
                    $notices  = [];

                    foreach ($parts as $stmt) {
                        if (empty(trim($stmt))) continue;
                        /* CREATE DATABASE / USE — cPanel मा permission हुँदैन, skip गर्छु */
                        if (preg_match('/^\s*(CREATE\s+DATABASE|USE\s+\w)/i', $stmt)) {
                            $notices[] = 'Skip: ' . htmlspecialchars(substr(trim($stmt), 0, 70)) . '…';
                            $skipped++;
                            continue;
                        }
                        try {
                            $db->exec($stmt);
                            $ok++;
                        } catch (\PDOException $e) {
                            $msg = $e->getMessage();
                            /* "Already exists" — warning, error होइन */
                            if (
                                stripos($msg, 'Duplicate column')  !== false ||
                                stripos($msg, 'already exists')    !== false ||
                                stripos($msg, 'Duplicate key')     !== false
                            ) {
                                $notices[] = 'ℹ️ Already exists (skip): ' . htmlspecialchars($msg);
                                $skipped++;
                            } else {
                                $errCount++;
                                $errors[] = htmlspecialchars($msg);
                            }
                        }
                    }

                    if ($errCount === 0) {
                        $uploadResultOk = true;
                        $uploadResult   = '<div class="alert alert-success">'
                            . '<i class="fas fa-check-circle fa-lg me-2"></i>'
                            . '<strong>' . htmlspecialchars($file['name']) . ' सफलतापूर्वक run भयो!</strong><br>'
                            . '<small>' . $ok . ' statement(s) execute भए'
                            . ($skipped > 0 ? ', ' . $skipped . ' skip भए (already exist)' : '') . '.</small>';
                        if (!empty($notices)) {
                            $uploadResult .= '<hr class="my-2"><details><summary class="small text-muted">Notices हेर्नुहोस् (' . count($notices) . ')</summary><ul class="mb-0 small mt-1">';
                            foreach ($notices as $n) $uploadResult .= '<li>' . $n . '</li>';
                            $uploadResult .= '</ul></details>';
                        }
                        $uploadResult .= '</div>';
                        logSecurityEvent('sql_upload_run', 'Uploaded SQL run: ' . $file['name'] . ', ok=' . $ok);
                    } else {
                        $uploadResult = '<div class="alert alert-danger">'
                            . '<i class="fas fa-times-circle fa-lg me-2"></i>'
                            . '<strong>केही errors आए (' . $errCount . '):</strong>'
                            . '<ul class="mb-0 small mt-2">';
                        foreach ($errors as $e) $uploadResult .= '<li>' . $e . '</li>';
                        $uploadResult .= '</ul>';
                        if ($ok > 0) $uploadResult .= '<small class="text-muted d-block mt-2">' . $ok . ' statements OK थिए।</small>';
                        $uploadResult .= '</div>';
                    }
                } catch (\Exception $e) {
                    $uploadResult = '<div class="alert alert-danger"><i class="fas fa-times-circle me-2"></i>Database Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
                }
            }
        }
    }
}

// =====================================================
// POST: Migration run गर्ने
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_migration'])) {
    $selectedFile = $_POST['migration_file'] ?? '';
    $runFile = $selectedFile;

    $allowedMigrationPaths = array_column($migrationFiles, 'file');

    // Security: path traversal रोक्ने — only allow files inside database/ folder
    $realBase = realpath(__DIR__ . '/../database/');
    $realFile = realpath(__DIR__ . '/' . $selectedFile);

    // File validate गर्ने
    if (!in_array($selectedFile, $allowedMigrationPaths, true)) {
        $result = '<div class="alert alert-danger"><i class="fas fa-ban"></i> <strong>अमान्य फाइल!</strong> छानिएको migration अनुमति सूचीमा छैन।</div>';
    } elseif (!$realFile || strpos($realFile, $realBase) !== 0) {
        $result = '<div class="alert alert-danger"><i class="fas fa-ban"></i> <strong>अमान्य फाइल!</strong> Allowed folder बाहिरको file run गर्न मिल्दैन।</div>';
    } elseif (!file_exists($realFile)) {
        $result = '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> फाइल भेटिएन: ' . htmlspecialchars(basename($selectedFile)) . '</div>';
    } else {
        // SQL file पढ्ने र execute गर्ने
        $sql = file_get_contents($realFile);

        if (empty(trim($sql))) {
            $result = '<div class="alert alert-warning">SQL file खाली छ।</div>';
        } else {
            try {
                $db = getDB();

                // SQL statements split गर्ने — DELIMITER blocks (stored procedures) पनि handle
                $parts = splitSqlStatements($sql);

                $successCount = 0;
                $errorCount   = 0;
                $errors       = [];
                $warnings     = [];

                foreach ($parts as $stmt) {
                    if (empty(trim($stmt))) continue;
                    /* CREATE DATABASE र USE statements skip गर्ने — cPanel user लाई permission हुँदैन */
                    if (preg_match('/^\s*(CREATE\s+DATABASE|USE\s+\w)/i', $stmt)) {
                        $warnings[] = 'ℹ️ Skipped (not needed on cPanel): ' . htmlspecialchars(substr(trim($stmt), 0, 60));
                        continue;
                    }
                    try {
                        $db->exec($stmt);
                        $successCount++;
                    } catch (\PDOException $e) {
                        $msg = $e->getMessage();
                        // "Duplicate column", "already exists" — warnings हुन्, errors होइनन्
                        if (
                            stripos($msg, 'Duplicate column') !== false ||
                            stripos($msg, 'already exists') !== false ||
                            stripos($msg, 'Duplicate key') !== false ||
                            strpos($e->getCode(), '42S21') !== false
                        ) {
                            $warnings[] = 'ℹ️ ' . htmlspecialchars($msg) . ' (Skip — already exists)';
                        } else {
                            $errorCount++;
                            $errors[] = htmlspecialchars($msg);
                        }
                    }
                }

                if ($errorCount === 0) {
                    $resultOk = true;
                    $result   = '<div class="alert alert-success">';
                    $result  .= '<i class="fas fa-check-circle fa-lg me-2"></i>';
                    $result  .= '<strong>Migration सफलतापूर्वक सम्पन्न भयो!</strong><br>';
                    $result  .= '<small>' . $successCount . ' statement(s) execute भए।</small>';
                    if (!empty($warnings)) {
                        $result .= '<hr><strong>Notices (skip गरिए — already exist):</strong><ul class="mb-0 small">';
                        foreach ($warnings as $w) $result .= '<li>' . $w . '</li>';
                        $result .= '</ul>';
                    }
                    $result .= '</div>';
                    logSecurityEvent('migration_run', 'Migration run: ' . basename($selectedFile));
                } else {
                    $result  = '<div class="alert alert-danger">';
                    $result .= '<i class="fas fa-times-circle fa-lg me-2"></i>';
                    $result .= '<strong>केही errors आए (' . $errorCount . '):</strong><ul class="mb-0 small">';
                    foreach ($errors as $e) $result .= '<li>' . $e . '</li>';
                    $result .= '</ul>';
                    if ($successCount > 0) {
                        $result .= '<br><small class="text-muted">' . $successCount . ' statements OK थिए।</small>';
                    }
                    $result .= '</div>';
                }
            } catch (\Exception $e) {
                $result = '<div class="alert alert-danger"><i class="fas fa-times-circle"></i> Database Error: ' . htmlspecialchars($e->getMessage()) . '</div>';
            }
        }
    }
}
?>

<!-- Page Content -->
<div class="container-fluid py-4">

    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col">
            <div class="d-flex align-items-center gap-3">
                <div class="bg-warning bg-opacity-10 p-3 rounded-3">
                    <i class="fas fa-database fa-2x text-warning"></i>
                </div>
                <div>
                    <h2 class="mb-0 fw-bold">Database Migration</h2>
                    <p class="text-muted mb-0">
                        Admin panel बाट एउटै database file run गर्नुहोस् — cPanel मा जान नपर्ने
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Warning Box -->
    <div class="alert alert-warning d-flex align-items-start gap-2 mb-4">
        <i class="fas fa-exclamation-triangle fa-lg mt-1 flex-shrink-0"></i>
        <div>
            <strong>ध्यान दिनुहोस्:</strong>
            Database update गर्नु अगाडि backup लिनुहोस्। <code>install.sql</code> re-run गर्दा data delete नहुने गरी बनाइएको छ।
        </div>
    </div>

    <!-- ============================================================
         SQL FILE UPLOAD — Computer बाट .sql file upload गरेर run गर्ने
         (phpMyAdmin बिना नै — new setup र update दुवैको लागि)
    ============================================================ -->
    <div class="card border-0 shadow mb-4" style="border-left: 4px solid #0d6efd !important;">
        <div class="card-header bg-primary text-white d-flex align-items-center gap-2">
            <i class="fas fa-file-upload fa-lg"></i>
            <div>
                <h5 class="mb-0">SQL File Upload गरेर Run गर्नुहोस्</h5>
                <small class="opacity-75">phpMyAdmin बिना नै — आफ्नो computer बाट .sql file directly run गर्नुहोस्</small>
            </div>
        </div>
        <div class="card-body">

            <!-- Upload result (यदि upload run गरिएको छ) -->
            <?php if (!empty($uploadResult)): ?>
            <div class="mb-3"><?php echo $uploadResult; ?></div>
            <?php endif; ?>

            <!-- Upload form -->
            <form method="POST" enctype="multipart/form-data"
                  onsubmit="return confirmUploadRun(this)">
                <?php echo csrfField(); ?>
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-md-8">
                        <label class="form-label fw-semibold">
                            <i class="fas fa-file-code me-1 text-primary"></i>
                            .sql File छान्नुहोस्:
                        </label>
                        <input type="file"
                               name="sql_file"
                               accept=".sql"
                               class="form-control form-control-lg"
                               required>
                        <div class="form-text text-muted">
                            केवल <code>.sql</code> file — अधिकतम 20MB ।
                            File server मा save हुँदैन, directly execute हुन्छ।
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <button type="submit" name="run_uploaded_sql" value="1"
                                class="btn btn-primary btn-lg w-100">
                            <i class="fas fa-play-circle me-2"></i>Run SQL File
                        </button>
                    </div>
                </div>

                <!-- Use cases — user ले कहिले use गर्ने थाहा होस् -->
                <div class="row g-2 mt-3">
                    <div class="col-md-6">
                        <div class="d-flex gap-2 p-2 rounded-2 bg-light">
                            <i class="fas fa-star text-warning mt-1 flex-shrink-0"></i>
                            <div class="small">
                                <strong>नयाँ installation:</strong><br>
                                <code>install.sql</code> file download गरी यहाँ upload गर्नुहोस् — सबै tables बन्छन्।
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="d-flex gap-2 p-2 rounded-2 bg-light">
                            <i class="fas fa-sync text-primary mt-1 flex-shrink-0"></i>
                            <div class="small">
                                <strong>Update / Upgrade:</strong><br>
                                नयाँ version को migration .sql file upload गरेर run गर्नुहोस्।
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Migration Result (यदि builtin file run गरिएको छ) -->
    <?php if (!empty($result)): ?>
    <div class="mb-4">
        <?php echo $result; ?>
    </div>
    <?php endif; ?>

    <!-- Flash Message -->
    <?php $flash = getFlash(); if ($flash): ?>
    <div class="alert alert-<?php echo $flash['type'] === 'success' ? 'success' : 'danger'; ?> mb-4">
        <?php echo htmlspecialchars($flash['message']); ?>
    </div>
    <?php endif; ?>

    <!-- Migration Files List (Built-in — server मा रहेका files) -->
    <div class="row g-4">
        <?php foreach ($migrationFiles as $idx => $migration):
            $fileExists = file_exists(__DIR__ . '/' . $migration['file']);
            $isJustRun  = ($runFile === $migration['file'] && $resultOk);
        ?>
        <div class="col-12">
            <div class="card border-0 shadow-sm <?php echo $isJustRun ? 'border-success border-2' : ''; ?>">
                <div class="card-body">
                    <div class="row align-items-center">
                        <div class="col-auto">
                            <?php if ($isJustRun): ?>
                                <div class="bg-success bg-opacity-10 p-3 rounded-3">
                                    <i class="fas fa-check-circle fa-2x text-success"></i>
                                </div>
                            <?php elseif ($fileExists): ?>
                                <div class="bg-primary bg-opacity-10 p-3 rounded-3">
                                    <i class="fas fa-file-code fa-2x text-primary"></i>
                                </div>
                            <?php else: ?>
                                <div class="bg-secondary bg-opacity-10 p-3 rounded-3">
                                    <i class="fas fa-file-excel fa-2x text-secondary"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <h5 class="mb-0 fw-semibold"><?php echo htmlspecialchars($migration['label']); ?></h5>
                                <?php if ($migration['safe']): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">
                                        <i class="fas fa-shield-alt"></i> Safe — Repeat run OK
                                    </span>
                                <?php endif; ?>
                                <?php if (!$fileExists): ?>
                                    <span class="badge bg-secondary">File छैन</span>
                                <?php elseif ($isJustRun): ?>
                                    <span class="badge bg-success"><i class="fas fa-check me-1"></i>Completed</span>
                                <?php endif; ?>
                            </div>
                            <p class="text-muted mb-0 small"><?php echo htmlspecialchars($migration['description']); ?></p>
                            <?php if ($fileExists): ?>
                                <code class="text-muted small d-block mt-1">
                                    <?php echo htmlspecialchars(basename($migration['file'])); ?>
                                    (<?php echo number_format(filesize(__DIR__ . '/' . $migration['file'])); ?> bytes)
                                </code>
                            <?php endif; ?>
                        </div>
                        <div class="col-auto">
                            <?php if ($fileExists): ?>
                            <form method="POST" onsubmit="return confirmMigration(this)">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="migration_file" value="<?php echo htmlspecialchars($migration['file']); ?>">
                                <button type="submit" name="run_migration" value="1"
                                    class="btn <?php echo $isJustRun ? 'btn-outline-success' : 'btn-primary'; ?>">
                                    <i class="fas fa-play-circle me-1"></i>
                                    <?php echo $isJustRun ? 'फेरि Run गर्नुहोस्' : 'Run Migration'; ?>
                                </button>
                            </form>
                            <?php else: ?>
                            <button class="btn btn-secondary" disabled>
                                <i class="fas fa-ban me-1"></i> File छैन
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($allowCustomSql): ?>
    <!-- Manual SQL Section -->
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-dark text-white">
            <h5 class="mb-0"><i class="fas fa-terminal me-2"></i>Custom SQL Run गर्नुहोस् (Advanced)</h5>
        </div>
        <div class="card-body">
            <div class="alert alert-danger small mb-3">
                <i class="fas fa-exclamation-circle"></i>
                <strong>सावधानी:</strong> यहाँ SQL directly run हुन्छ। DROP, DELETE गलत गर्यो भने data सखाप हुन्छ।
                Expert मात्र use गर्नुहोस्।
            </div>
            <form method="POST" onsubmit="return confirmCustomSQL()">
                <?php echo csrfField(); ?>
                <div class="mb-3">
                    <!-- SQL लेख्ने textarea — यहाँ safe ALTER TABLE, CREATE TABLE etc. मात्र -->
                    <label class="form-label fw-bold">SQL Statement:</label>
                    <textarea name="custom_sql" class="form-control font-monospace" rows="8"
                        placeholder="-- Example: ALTER TABLE grievances ADD COLUMN IF NOT EXISTS tracking_id VARCHAR(60);&#10;-- Multiple statements semicolon (;) बाट छुट्याउनुहोस्"
                    ><?php echo htmlspecialchars($_POST['custom_sql'] ?? ''); ?></textarea>
                </div>
                <button type="submit" name="run_custom_sql" value="1" class="btn btn-danger">
                    <i class="fas fa-bolt me-1"></i> Execute SQL
                </button>
            </form>
            <?php
            // Custom SQL run गर्ने
            if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_custom_sql'])) {
                $customSql = trim($_POST['custom_sql'] ?? '');
                if (!empty($customSql)) {
                    // Dangerous operations block गर्ने
                    $dangerousPatterns = ['/\bDROP\s+TABLE\b/i', '/\bDROP\s+DATABASE\b/i', '/\bTRUNCATE\b/i'];
                    $isDangerous = false;
                    foreach ($dangerousPatterns as $p) {
                        if (preg_match($p, $customSql)) { $isDangerous = true; break; }
                    }
                    if ($isDangerous) {
                        echo '<div class="alert alert-danger mt-3"><i class="fas fa-ban"></i> DROP TABLE / TRUNCATE Admin panel बाट run गर्न blocked छ। phpMyAdmin use गर्नुहोस्।</div>';
                    } else {
                        try {
                            $db = getDB();
                            $parts = array_filter(array_map('trim', explode(';', $customSql)), fn($s) => !empty($s));
                            $ok = 0; $errs = [];
                            foreach ($parts as $stmt) {
                                try { $db->exec($stmt); $ok++; } catch (\PDOException $e) { $errs[] = $e->getMessage(); }
                            }
                            if (empty($errs)) {
                                echo '<div class="alert alert-success mt-3"><i class="fas fa-check-circle"></i> ' . $ok . ' statement(s) successfully executed.</div>';
                            } else {
                                echo '<div class="alert alert-danger mt-3"><strong>Errors:</strong><ul class="mb-0">';
                                foreach ($errs as $e) echo '<li>' . htmlspecialchars($e) . '</li>';
                                echo '</ul></div>';
                            }
                        } catch (\Exception $e) {
                            echo '<div class="alert alert-danger mt-3">' . htmlspecialchars($e->getMessage()) . '</div>';
                        }
                    }
                }
            }
            ?>
        </div>
    </div>
    <?php else: ?>
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header bg-dark text-white">
            <h5 class="mb-0"><i class="fas fa-shield-alt me-2"></i>Custom SQL (Disabled)</h5>
        </div>
        <div class="card-body">
            <div class="alert alert-info mb-0">
                <i class="fas fa-info-circle me-2"></i>
                Safety र consistency को लागि यो page बाट direct custom SQL execute बन्द गरिएको छ।
                कृपया <code>admin/db-setup.php</code> को controlled actions (install/rebuild/reset) प्रयोग गर्नुहोस्।
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Help Section -->
    <div class="card border-0 shadow-sm mt-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="fas fa-question-circle me-2 text-info"></i>सहायता — Migration कहिले र कसरी run गर्ने?</h5>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <h6 class="fw-bold text-danger"><i class="fas fa-bug me-1"></i>Error आउँदा</h6>
                    <ul class="small text-muted">
                        <li><code>Unknown column</code> वा <code>Table doesn't exist</code> आएमा <strong>install.sql</strong> run गर्नुहोस्</li>

                        <li><code>Data truncated</code> → Column type mismatch — custom SQL use गर्नुहोस्</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <h6 class="fw-bold text-success"><i class="fas fa-rocket me-1"></i>नयाँ installation मा</h6>
                    <ul class="small text-muted">
                        <li>phpMyAdmin मा <code>database/install.sql</code> import गर्नुहोस्</li>
                        <li>Admin → System Info page मा database check गर्नुहोस्</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
/* =====================================================
   Migration confirm गर्ने dialog
===================================================== */
function confirmMigration(form) {
    /* Migration file को नाम लिने */
    var fileName = form.querySelector('input[name="migration_file"]').value;
    var baseName = fileName.split('/').pop();
    return confirm(
        'Migration run गर्ने?\n\n' +
        'File: ' + baseName + '\n\n' +
        'OK थिच्नुहोस् — Migration run हुनेछ।\n' +
        'Cancel थिच्नुहोस् — रोकिनेछ।'
    );
}

/* Custom SQL confirm */
function confirmCustomSQL() {
    var sql = document.querySelector('textarea[name="custom_sql"]').value.trim();
    if (!sql) { alert('SQL खाली छ।'); return false; }
    return confirm(
        '⚠️ Custom SQL execute गर्ने?\n\n' +
        'यो action undo गर्न सकिँदैन।\n' +
        'OK थिच्नुहोस् — Execute हुनेछ।'
    );
}

/* Upload गरिएको SQL file confirm */
function confirmUploadRun(form) {
    var fileInput = form.querySelector('input[name="sql_file"]');
    if (!fileInput || !fileInput.files.length) {
        alert('.sql file choose गर्नुहोस्।');
        return false;
    }
    var fileName = fileInput.files[0].name;
    /* .sql extension check */
    if (!fileName.toLowerCase().endsWith('.sql')) {
        alert('केवल .sql file मात्र run गर्न सकिन्छ।');
        return false;
    }
    return confirm(
        'SQL File Run गर्ने?\n\n' +
        'File: ' + fileName + '\n\n' +
        'CREATE DATABASE / USE statements automatically skip हुन्छन्।\n' +
        'OK थिच्नुहोस् — Execute हुनेछ।\n' +
        'Cancel — रोकिनेछ।'
    );
}
</script>

<?php require_once 'includes/admin-footer.php'; ?>
