<?php
$pageTitle = 'Database Backup / Restore';
$currentPage = 'backup-restore';
require_once __DIR__ . '/_bootstrap.php';
if (!isAdminLoggedIn()) {
    redirect(ADMIN_URL . 'index.php');
}
  /* ── Early CSRF Protection ── */
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCSRFToken()) {
      setFlash('error', 'सुरक्षा जाँच असफल। कृपया पुनः प्रयास गर्नुहोस्।');
      redirect($_SERVER['HTTP_REFERER'] ?? ADMIN_URL . 'dashboard.php');
  }
  if (empty($csrfToken)) $csrfToken = generateCSRFToken();


function brQuoteIdentifier($name) {
    return '`' . str_replace('`', '``', $name) . '`';
}

function brQuoteValue($value) {
    if ($value === null) {
        return 'NULL';
    }
    return getDB()->quote((string)$value);
}

function brSplitSql($sql) {
    $statements = [];
    $current = '';
    $length = strlen($sql);
    $quote = null;
    $escape = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        if ($quote !== null) {
            $current .= $char;
            if ($escape) {
                $escape = false;
            } elseif ($char === '\\') {
                $escape = true;
            } elseif ($char === $quote) {
                $quote = null;
            }
            continue;
        }

        if ($char === "'" || $char === '"') {
            $quote = $char;
            $current .= $char;
            continue;
        }

        if ($char === '-' && $next === '-') {
            while ($i < $length && $sql[$i] !== "\n") {
                $i++;
            }
            continue;
        }

        if ($char === '#') {
            while ($i < $length && $sql[$i] !== "\n") {
                $i++;
            }
            continue;
        }

        if ($char === '/' && $next === '*') {
            $i += 2;
            while ($i < $length - 1 && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                $i++;
            }
            $i++;
            continue;
        }

        if ($char === ';') {
            $trimmed = trim($current);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $current = '';
            continue;
        }

        $current .= $char;
    }

    $trimmed = trim($current);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['download_backup'])) {
    if (!verifyCSRFToken()) {
        setFlash('error', 'सुरक्षा जाँच असफल। कृपया पुनः प्रयास गर्नुहोस्।');
        redirect('backup-restore.php');
    }

    try {
        $db = getDB();
        $filename = 'database-backup-' . date('Y-m-d-His') . '.sql';
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        echo "SET FOREIGN_KEY_CHECKS=0;\n\n";
        $tables = $db->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM);

        foreach ($tables as $tableRow) {
            $table = $tableRow[0];
            $tableName = brQuoteIdentifier($table);
            $createRow = $db->query('SHOW CREATE TABLE ' . $tableName)->fetch(PDO::FETCH_ASSOC);
            $createSql = $createRow['Create Table'] ?? array_values($createRow)[1] ?? '';

            echo "DROP TABLE IF EXISTS {$tableName};\n";
            echo $createSql . ";\n\n";

            $stmt = $db->query('SELECT * FROM ' . $tableName);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $columns = array_map('brQuoteIdentifier', array_keys($row));
                $values = array_map('brQuoteValue', array_values($row));
                echo 'INSERT INTO ' . $tableName . ' (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n";
            }
            echo "\n";
        }

        echo "SET FOREIGN_KEY_CHECKS=1;\n";
        exit();
    } catch (Throwable $e) {
        setFlash('error', 'Backup बनाउन सकिएन: ' . $e->getMessage());
        redirect('backup-restore.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_backup'])) {
    /* Restore — superadmin मात्र। Regular admin ले data wipe गर्न पाउँदैन। */
    if (empty($_SESSION['is_superadmin'])) {
        setFlash('error', 'Database restore केवल Superadmin ले गर्न सक्छ।');
        redirect('backup-restore.php');
    }
    if (!verifyCSRFToken()) {
        setFlash('error', 'सुरक्षा जाँच असफल। कृपया पुनः प्रयास गर्नुहोस्।');
        redirect('backup-restore.php');
    }

    if (!isset($_FILES['sql_file']) || $_FILES['sql_file']['error'] !== UPLOAD_ERR_OK) {
        setFlash('error', 'SQL file upload भएन।');
        redirect('backup-restore.php');
    }

    $fileName = $_FILES['sql_file']['name'] ?? '';
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($extension !== 'sql') {
        setFlash('error', 'कृपया .sql file मात्र upload गर्नुहोस्।');
        redirect('backup-restore.php');
    }

    try {
        // Size guard: avoid very large uploads via web UI. Recommend CLI for large DBs.
        $maxSize = 50 * 1024 * 1024; // 50 MB
        if (isset($_FILES['sql_file']['size']) && $_FILES['sql_file']['size'] > $maxSize) {
            throw new Exception('SQL file धेरै ठूलो छ (50MB भन्दा बढी). CLI बाट restore गर्नुस्।');
        }

        $sql = file_get_contents($_FILES['sql_file']['tmp_name']);
        if (trim($sql) === '') {
            throw new Exception('SQL file खाली छ।');
        }

        $db = getDB();
        $statements = brSplitSql($sql);

        // Limit total statements processed via web UI to avoid DoS or accidental huge imports.
        $maxStatements = 10000;
        if (count($statements) > $maxStatements) {
            throw new Exception('SQL file मा धेरै statements छन् (' . count($statements) . '). CLI बाट restore गर्नुस्।');
        }

        // Block obviously dangerous statements that should never be run from web UI.
        $dangerPattern = '/\b(DROP\s+DATABASE|CREATE\s+USER|GRANT|REVOKE|ALTER\s+DATABASE|CREATE\s+DATABASE|USE\s+DATABASE|SET\s+PASSWORD|FLUSH\s+PRIVILEGES|SOURCE\s+|DELIMITER|CREATE\s+FUNCTION|CREATE\s+PROCEDURE|CREATE\s+EVENT|CREATE\s+TRIGGER)\b/i';
        $blocked = [];
        foreach ($statements as $stmt) {
            if (preg_match($dangerPattern, $stmt)) {
                $blocked[] = trim(mb_substr($stmt, 0, 300));
                if (count($blocked) >= 5) break;
            }
        }
        if (!empty($blocked)) {
            throw new Exception('Restore blocked: SQL file मा disallowed statements भेटियो। उदाहरण: ' . implode(' || ', $blocked));
        }

        $ok = 0;
        $errors = [];

        // Try to run inside a transaction where possible.
        $inTransaction = false;
        try {
            if ($db->beginTransaction()) {
                $inTransaction = true;
            }
        } catch (Throwable $e) {
            // Some drivers/DDL may implicitly commit; continue without transaction.
            $inTransaction = false;
        }

        foreach ($statements as $statement) {
            try {
                $db->exec($statement);
                $ok++;
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
                // If we started a transaction, rollback to avoid partial apply.
                if ($inTransaction) {
                    try { $db->rollBack(); } catch (Throwable $_) {}
                }
                break;
            }
        }

        if ($inTransaction && empty($errors)) {
            try { $db->commit(); } catch (Throwable $_) {}
        }

        if (!empty($errors)) {
            setFlash('error', $ok . ' statement run भयो, तर error आयो: ' . implode(' | ', $errors));
        } else {
            setFlash('success', 'Database restore/import सफल भयो। ' . $ok . ' statement run भयो।');
        }
    } catch (Throwable $e) {
        setFlash('error', 'Restore गर्न सकिएन: ' . $e->getMessage());
    }

    redirect('backup-restore.php');
}

require_once __DIR__ . '/includes/admin-header.php';
require_once __DIR__ . '/includes/admin-ui.php';
$flash = getFlash();
?>

<?php
echo adminPageHeader(
    'Database Backup / Restore', 'fa-shield-alt',
    'Update गर्नु अघि backup download गर्नुहोस्, आवश्यक परे SQL restore/import गर्नुहोस्।'
);
if ($flash) echo adminAlert($flash['type'], $flash['message']);
?>
<div class="container-fluid py-4">
    <div class="row mb-4"></div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fas fa-download me-2"></i>Backup Download</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted">यो बटनले हालको database को full SQL backup download गर्छ। Update गर्नु अघि सधैं backup राख्नुहोस्।</p>
                    <form method="POST">
                        <?php echo csrfField(); ?>
                        <button type="submit" name="download_backup" value="1" class="btn btn-success">
                            <i class="fas fa-file-export me-1"></i> Download Database Backup
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="fas fa-upload me-2"></i>Restore / Import SQL</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning">
                        <strong>सावधानी:</strong> गलत SQL restore गर्दा data परिवर्तन वा delete हुन सक्छ। पहिले backup download गर्नुहोस्।
                    </div>
                    <form method="POST" enctype="multipart/form-data" onsubmit="return confirm('Restore/Import गर्ने हो? पहिले backup download गरेको छ भने मात्र OK थिच्नुहोस्।');">
                        <?php echo csrfField(); ?>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">SQL file छान्नुहोस्</label>
                            <input type="file" name="sql_file" class="form-control" accept=".sql" required>
                        </div>
                        <button type="submit" name="restore_backup" value="1" class="btn btn-danger">
                            <i class="fas fa-database me-1"></i> Restore / Import SQL
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mt-4">
        <div class="card-body">
            <h5 class="fw-bold mb-3"><i class="fas fa-circle-info text-info me-2"></i>कसरी प्रयोग गर्ने?</h5>
            <ol class="mb-0 text-muted">
                <li>Website update गर्नु अघि <strong>Download Database Backup</strong> क्लिक गर्नुहोस्।</li>
                <li>Downloaded `.sql` file सुरक्षित राख्नुहोस्।</li>
                <li>Update पछि समस्या आएमा त्यही file छानेर <strong>Restore / Import SQL</strong> गर्नुहोस्।</li>
                <li>ठूलो database भए phpMyAdmin बाट restore गर्नु राम्रो हुन्छ।</li>
            </ol>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>
