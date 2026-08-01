<?php
/**
 * Bulk Member Import — chunked CSV jobs (10k–50k safe)
 * ─────────────────────────────────────────────────────
 * Phases: uploaded → parsing → importing → done|failed
 * Temp portal password: last4(mobile) + last4(sadasyata digits)
 * Cards via adminGenerateMemberIdCard(..., silent: true)
 */

if (!defined('MEMBER_IMPORT_PARSE_CHUNK')) {
    define('MEMBER_IMPORT_PARSE_CHUNK', 800);
}
if (!defined('MEMBER_IMPORT_IMPORT_CHUNK')) {
    define('MEMBER_IMPORT_IMPORT_CHUNK', 250);
}

if (!function_exists('ensureMemberImportTables')) {
    function ensureMemberImportTables(?PDO $pdo = null): void {
        if (!$pdo) {
            try { $pdo = getDB(); } catch (Throwable $e) { return; }
        }
        if (!$pdo) return;

        $pdo->exec("CREATE TABLE IF NOT EXISTS member_import_jobs (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            admin_id        INT NULL,
            filename        VARCHAR(255) NOT NULL DEFAULT '',
            stored_path     VARCHAR(500) NOT NULL DEFAULT '',
            status          VARCHAR(20) NOT NULL DEFAULT 'uploaded',
            mode            VARCHAR(10) NOT NULL DEFAULT 'skip',
            total_rows      INT NOT NULL DEFAULT 0,
            parsed_rows     INT NOT NULL DEFAULT 0,
            ok_count        INT NOT NULL DEFAULT 0,
            skip_count      INT NOT NULL DEFAULT 0,
            fail_count      INT NOT NULL DEFAULT 0,
            cards_count     INT NOT NULL DEFAULT 0,
            parse_offset    INT NOT NULL DEFAULT 0,
            error_message   TEXT NULL,
            created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_mij_status (status),
            INDEX idx_mij_admin (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS member_import_rows (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            job_id          INT NOT NULL,
            row_num         INT NOT NULL DEFAULT 0,
            sadasyata_number VARCHAR(50) NOT NULL DEFAULT '',
            full_name       VARCHAR(255) NOT NULL DEFAULT '',
            mobile          VARCHAR(20) NOT NULL DEFAULT '',
            email           VARCHAR(255) NOT NULL DEFAULT '',
            address         TEXT NULL,
            dob             VARCHAR(20) NOT NULL DEFAULT '',
            gender          VARCHAR(20) NOT NULL DEFAULT '',
            branch          VARCHAR(100) NOT NULL DEFAULT '',
            remarks         VARCHAR(500) NOT NULL DEFAULT '',
            status          VARCHAR(20) NOT NULL DEFAULT 'queued',
            message         VARCHAR(500) NOT NULL DEFAULT '',
            member_id       INT NULL,
            INDEX idx_mir_job_status (job_id, status),
            INDEX idx_mir_job_row (job_id, row_num)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Resume parse without re-scanning earlier rows (50k safe)
        try { $pdo->exec("ALTER TABLE member_import_jobs ADD COLUMN parse_byte_offset INT NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
        // Helpful lookup index for duplicate checks
        try { $pdo->exec("CREATE INDEX idx_members_sadasyata ON members (sadasyata_number)"); } catch (Throwable $e) {}
        try { $pdo->exec("CREATE INDEX idx_members_phone ON members (phone)"); } catch (Throwable $e) {}
    }
}

if (!function_exists('memberImportTempPassword')) {
    /** last 4 of mobile digits + last 4 of member-id digits */
    function memberImportTempPassword(string $mobile, string $sadasyata): string {
        $m = preg_replace('/\D/', '', $mobile) ?? '';
        $s = preg_replace('/\D/', '', $sadasyata) ?? '';
        $a = substr(str_pad($m, 4, '0', STR_PAD_LEFT), -4);
        $b = substr(str_pad($s, 4, '0', STR_PAD_LEFT), -4);
        return $a . $b;
    }
}

if (!function_exists('memberImportUploadDir')) {
    function memberImportUploadDir(): string {
        $dir = (defined('UPLOAD_PATH') ? UPLOAD_PATH : (ROOT_PATH . 'assets/uploads/')) . 'member-imports/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }
}

if (!function_exists('memberImportNormalizeHeader')) {
    function memberImportNormalizeHeader(string $h): string {
        // Strip UTF-8 BOM if Excel left it on the first column name
        if (strncmp($h, "\xEF\xBB\xBF", 3) === 0) {
            $h = substr($h, 3);
        }
        $h = strtolower(trim($h));
        $h = preg_replace('/^\xEF\xBB\xBF/', '', $h) ?? $h;
        $aliases = [
            'member_id' => 'sadasyata_number',
            'memberid' => 'sadasyata_number',
            'sadasyata' => 'sadasyata_number',
            'sadasyata_no' => 'sadasyata_number',
            'membership_no' => 'sadasyata_number',
            'name' => 'full_name',
            'member_name' => 'full_name',
            'phone' => 'mobile',
            'mobile_no' => 'mobile',
            'permanent_address' => 'address',
            'dob_ad' => 'dob',
            'date_of_birth' => 'dob',
        ];
        return $aliases[$h] ?? $h;
    }
}

if (!function_exists('memberImportNormalizeDob')) {
    /**
     * Accept YYYY-MM-DD, DD/MM/YYYY, DD-MM-YYYY, YYYY/MM/DD → YYYY-MM-DD or '' if empty, false if invalid.
     * @return string|false
     */
    function memberImportNormalizeDob(string $raw) {
        $raw = trim($raw);
        if ($raw === '') return '';
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $raw, $m)) {
            $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
            return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : false;
        }
        if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $raw, $m)) {
            $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
            return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : false;
        }
        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $raw, $m)) {
            $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
            return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : false;
        }
        return false;
    }
}

if (!function_exists('memberImportCreateJob')) {
    /**
     * @return array{ok:bool,job_id?:int,error?:string}
     */
    function memberImportCreateJob(PDO $pdo, array $file, int $adminId, string $mode = 'skip'): array {
        ensureMemberImportTables($pdo);
        $mode = ($mode === 'update') ? 'update' : 'skip';

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => 'CSV file upload असफल भयो।'];
        }
        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            return ['ok' => false, 'error' => 'Excel लाई CSV (UTF-8) मा Save गरेर मात्र upload गर्नुहोस्।'];
        }
        if ((int)($file['size'] ?? 0) <= 0) {
            return ['ok' => false, 'error' => 'खाली फाइल।'];
        }
        // Soft cap ~40MB — enough for 50k+ CSV rows
        if ((int)$file['size'] > 40 * 1024 * 1024) {
            return ['ok' => false, 'error' => 'फाइल धेरै ठूलो छ (अधिकतम ~40MB)।'];
        }

        $dir = memberImportUploadDir();
        $safeName = 'job_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.csv';
        $dest = $dir . $safeName;
        if (!move_uploaded_file((string)$file['tmp_name'], $dest)) {
            return ['ok' => false, 'error' => 'सर्भरमा फाइल सुरक्षित गर्न सकिएन।'];
        }

        $st = $pdo->prepare(
            "INSERT INTO member_import_jobs (admin_id, filename, stored_path, status, mode)
             VALUES (?, ?, ?, 'uploaded', ?)"
        );
        $st->execute([
            $adminId > 0 ? $adminId : null,
            mb_substr((string)($file['name'] ?? $safeName), 0, 250),
            $dest,
            $mode,
        ]);
        return ['ok' => true, 'job_id' => (int)$pdo->lastInsertId()];
    }
}

if (!function_exists('memberImportGetJob')) {
    function memberImportGetJob(PDO $pdo, int $jobId): ?array {
        ensureMemberImportTables($pdo);
        $st = $pdo->prepare("SELECT * FROM member_import_jobs WHERE id=? LIMIT 1");
        $st->execute([$jobId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('memberImportJobProgress')) {
    function memberImportJobProgress(array $job): array {
        $status = (string)($job['status'] ?? '');
        $total = max(0, (int)($job['total_rows'] ?? 0));
        $parsed = max(0, (int)($job['parsed_rows'] ?? 0));
        $done = (int)($job['ok_count'] ?? 0) + (int)($job['skip_count'] ?? 0) + (int)($job['fail_count'] ?? 0);

        if ($status === 'done') {
            $pct = 100;
            $phase = 'done';
        } elseif ($status === 'failed') {
            $pct = 100;
            $phase = 'failed';
        } elseif ($status === 'parsing' || $status === 'uploaded') {
            $phase = 'parsing';
            // Unknown total until first parse pass counts lines — use parse_offset heuristic
            $pct = $total > 0 ? min(49, (int)floor(($parsed / max(1, $total)) * 49)) : min(40, 5 + (int)($job['parse_offset'] ?? 0) / 500);
        } else {
            $phase = 'importing';
            $pct = $total > 0 ? min(99, 50 + (int)floor(($done / max(1, $total)) * 50)) : 50;
        }

        return [
            'job_id'      => (int)($job['id'] ?? 0),
            'status'      => $status,
            'phase'       => $phase,
            'percent'     => $pct,
            'mode'        => (string)($job['mode'] ?? 'skip'),
            'filename'    => (string)($job['filename'] ?? ''),
            'total_rows'  => $total,
            'parsed_rows' => $parsed,
            'ok_count'    => (int)($job['ok_count'] ?? 0),
            'skip_count'  => (int)($job['skip_count'] ?? 0),
            'fail_count'  => (int)($job['fail_count'] ?? 0),
            'cards_count' => (int)($job['cards_count'] ?? 0),
            'processed'   => $done,
            'error_message' => (string)($job['error_message'] ?? ''),
            'temp_password_hint' => 'मोबाइलको पछिल्लो ४ अङ्क + सदस्यता नं. का पछिल्लो ४ अङ्क',
        ];
    }
}

if (!function_exists('memberImportProcessTick')) {
    /**
     * One chunk of work (parse or import). Safe to call repeatedly via AJAX.
     * @return array progress payload + tick meta
     */
    function memberImportProcessTick(PDO $pdo, int $jobId): array {
        ensureMemberImportTables($pdo);
        if (function_exists('ensureMemberTables')) {
            try { ensureMemberTables(); } catch (Throwable $e) {}
        }
        if (file_exists(__DIR__ . '/card-verify-helpers.php')) {
            require_once __DIR__ . '/card-verify-helpers.php';
        }

        @set_time_limit(90);
        $job = memberImportGetJob($pdo, $jobId);
        if (!$job) {
            return ['ok' => false, 'error' => 'Import job फेला परेन।'];
        }

        $status = (string)$job['status'];
        if ($status === 'done' || $status === 'failed') {
            return ['ok' => true, 'finished' => true, 'progress' => memberImportJobProgress($job)];
        }

        // Prevent overlapping ticks (double tab / slow network)
        $lockName = 'member_import_' . $jobId;
        $gotLock = false;
        try {
            $stLock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
            $stLock->execute([$lockName]);
            $gotLock = ((int)$stLock->fetchColumn() === 1);
        } catch (Throwable $e) {
            $gotLock = true; // if GET_LOCK unavailable, proceed
        }
        if (!$gotLock) {
            return [
                'ok' => true,
                'finished' => false,
                'busy' => true,
                'progress' => memberImportJobProgress($job),
            ];
        }

        try {
            if ($status === 'uploaded' || $status === 'parsing') {
                $result = _memberImportParseChunk($pdo, $job);
            } else {
                $result = _memberImportImportChunk($pdo, $job);
            }
        } catch (Throwable $e) {
            error_log('[member-import] ' . $e->getMessage());
            $pdo->prepare("UPDATE member_import_jobs SET status='failed', error_message=? WHERE id=?")
                ->execute([mb_substr($e->getMessage(), 0, 500), $jobId]);
            $job = memberImportGetJob($pdo, $jobId);
            $result = ['ok' => false, 'finished' => true, 'error' => $e->getMessage(), 'progress' => memberImportJobProgress($job ?: [])];
            try {
                $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
            } catch (Throwable $e2) {}
            return $result;
        }

        try {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
        } catch (Throwable $e) {}

        $job = memberImportGetJob($pdo, $jobId);
        $progress = memberImportJobProgress($job ?: []);
        return array_merge(['ok' => true], $result, ['progress' => $progress]);
    }
}

if (!function_exists('_memberImportCountDataRows')) {
    function _memberImportCountDataRows(string $path): int {
        $fh = fopen($path, 'r');
        if (!$fh) return 0;
        $n = 0;
        $first = true;
        while (($row = fgetcsv($fh)) !== false) {
            if ($first) { $first = false; continue; }
            if (!is_array($row)) continue;
            if (count(array_filter($row, static fn($v) => trim((string)$v) !== '')) === 0) continue;
            $n++;
        }
        fclose($fh);
        return $n;
    }
}

if (!function_exists('_memberImportParseChunk')) {
    function _memberImportParseChunk(PDO $pdo, array $job): array {
        $jobId = (int)$job['id'];
        $path = (string)$job['stored_path'];
        if ($path === '' || !is_file($path)) {
            $pdo->prepare("UPDATE member_import_jobs SET status='failed', error_message=? WHERE id=?")
                ->execute(['CSV फाइल हरायो।', $jobId]);
            return ['finished' => true, 'tick' => 'parse'];
        }

        if ((string)$job['status'] === 'uploaded') {
            $total = _memberImportCountDataRows($path);
            if ($total <= 0) {
                $pdo->prepare("UPDATE member_import_jobs SET status='failed', error_message=?, total_rows=0 WHERE id=?")
                    ->execute(['CSV मा डेटा row छैन। Sample भरेर फेरि upload गर्नुहोस्।', $jobId]);
                return ['finished' => true, 'tick' => 'parse'];
            }
            $pdo->prepare("UPDATE member_import_jobs SET status='parsing', total_rows=?, parsed_rows=0, parse_offset=0, parse_byte_offset=0 WHERE id=?")
                ->execute([$total, $jobId]);
            $job['total_rows'] = $total;
            $job['parse_offset'] = 0;
            $job['parse_byte_offset'] = 0;
            $job['parsed_rows'] = 0;
            $job['status'] = 'parsing';
        }

        $fh = fopen($path, 'r');
        if (!$fh) {
            $pdo->prepare("UPDATE member_import_jobs SET status='failed', error_message=? WHERE id=?")
                ->execute(['CSV पढ्न सकिएन।', $jobId]);
            return ['finished' => true, 'tick' => 'parse'];
        }

        $byteOffset = (int)($job['parse_byte_offset'] ?? 0);
        if ($byteOffset > 0) {
            if (fseek($fh, $byteOffset) !== 0) {
                fclose($fh);
                $pdo->prepare("UPDATE member_import_jobs SET status='failed', error_message=? WHERE id=?")
                    ->execute(['CSV resume position गलत भयो।', $jobId]);
                return ['finished' => true, 'tick' => 'parse'];
            }
            $headers = null;
            $idx = null;
            // Header already validated; re-read names from job start for column map
            $fhMeta = fopen($path, 'r');
            $headerRow = $fhMeta ? fgetcsv($fhMeta) : false;
            if ($fhMeta) fclose($fhMeta);
            if (!$headerRow || !is_array($headerRow)) {
                fclose($fh);
                $pdo->prepare("UPDATE member_import_jobs SET status='failed', error_message=? WHERE id=?")
                    ->execute(['CSV header खाली छ।', $jobId]);
                return ['finished' => true, 'tick' => 'parse'];
            }
            $headers = [];
            foreach ($headerRow as $i => $h) {
                $headers[$i] = memberImportNormalizeHeader((string)$h);
            }
            $idx = array_flip($headers);
        } else {
            $headerRow = fgetcsv($fh);
            if (!$headerRow || !is_array($headerRow)) {
                fclose($fh);
                $pdo->prepare("UPDATE member_import_jobs SET status='failed', error_message=? WHERE id=?")
                    ->execute(['CSV header खाली छ। Sample download गर्नुहोस्।', $jobId]);
                return ['finished' => true, 'tick' => 'parse'];
            }
            $headers = [];
            foreach ($headerRow as $i => $h) {
                $headers[$i] = memberImportNormalizeHeader((string)$h);
            }
            $idx = array_flip($headers);
            foreach (['sadasyata_number', 'full_name', 'mobile'] as $req) {
                if (!isset($idx[$req])) {
                    fclose($fh);
                    $pdo->prepare("UPDATE member_import_jobs SET status='failed', error_message=? WHERE id=?")
                        ->execute(["CSV header मा '{$req}' अनिवार्य छ। Sample file प्रयोग गर्नुहोस्।", $jobId]);
                    return ['finished' => true, 'tick' => 'parse'];
                }
            }
            // Start of first data row
            $byteOffset = (int)ftell($fh);
        }

        $ins = $pdo->prepare(
            "INSERT INTO member_import_rows
                (job_id, row_num, sadasyata_number, full_name, mobile, email, address, dob, gender, branch, remarks, status, message)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );

        $chunk = 0;
        $parsedAdd = 0;
        $failAdd = 0;
        $limit = MEMBER_IMPORT_PARSE_CHUNK;
        $rowNumBase = (int)($job['parsed_rows'] ?? 0); // data rows already parsed
        $rowNum = $rowNumBase + 1; // 1-based data index for display (+ header conceptually)

        while ($chunk < $limit && ($row = fgetcsv($fh)) !== false) {
            if (!is_array($row) || count(array_filter($row, static fn($v) => trim((string)$v) !== '')) === 0) {
                $byteOffset = (int)ftell($fh);
                continue;
            }
            $chunk++;
            $rowNum = $rowNumBase + $chunk + 1; // approximate spreadsheet row (header=1)

            $val = static function (string $key) use ($idx, $row): string {
                if (!isset($idx[$key])) return '';
                $i = $idx[$key];
                return isset($row[$i]) ? trim((string)$row[$i]) : '';
            };

            $sid = function_exists('clean_text') ? clean_text($val('sadasyata_number')) : $val('sadasyata_number');
            $sid = function_exists('memberSsotNormalizeId') ? memberSsotNormalizeId($sid) : strtoupper(trim((string)$sid));
            $name = function_exists('clean_text') ? clean_text($val('full_name')) : $val('full_name');
            $mobile = preg_replace('/[^0-9]/', '', $val('mobile')) ?? '';
            // Nepal mobiles often stored with 977 prefix — normalize to last 10 digits when longer
            if (strlen($mobile) > 10 && strpos($mobile, '977') === 0) {
                $mobile = substr($mobile, -10);
            }
            $email = function_exists('clean_text') ? clean_text($val('email')) : $val('email');
            $address = function_exists('clean_text') ? clean_text($val('address')) : $val('address');
            $dobRaw = trim($val('dob'));
            $dobNorm = memberImportNormalizeDob($dobRaw);
            $gender = function_exists('clean_text') ? clean_text($val('gender')) : $val('gender');
            $branch = function_exists('clean_text') ? clean_text($val('branch')) : $val('branch');
            $remarks = function_exists('clean_text') ? clean_text($val('remarks')) : $val('remarks');

            $status = 'queued';
            $message = '';
            $dob = '';
            if ($sid === '' || $name === '' || strlen($mobile) < 7) {
                $status = 'failed';
                $message = 'sadasyata_number, full_name र valid mobile अनिवार्य।';
                $failAdd++;
            } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $status = 'failed';
                $message = 'अमान्य email।';
                $failAdd++;
            } elseif ($dobNorm === false) {
                $status = 'failed';
                $message = 'dob AD format गलत (YYYY-MM-DD वा DD/MM/YYYY)।';
                $failAdd++;
            } else {
                $dob = (string)$dobNorm;
            }

            $ins->execute([
                $jobId,
                $rowNum,
                mb_substr($sid, 0, 50),
                mb_substr($name, 0, 255),
                mb_substr($mobile, 0, 20),
                mb_substr($email, 0, 255),
                $address,
                mb_substr($dob, 0, 20),
                mb_substr($gender, 0, 20),
                mb_substr($branch, 0, 100),
                mb_substr($remarks, 0, 500),
                $status,
                mb_substr($message, 0, 500),
            ]);
            $parsedAdd++;
            $byteOffset = (int)ftell($fh);
        }

        $eof = feof($fh);
        fclose($fh);

        $newParsed = (int)$job['parsed_rows'] + $parsedAdd;
        $newFail = (int)$job['fail_count'] + $failAdd;
        $newOffset = (int)($job['parse_offset'] ?? 0) + $chunk;

        if ($eof || $chunk === 0) {
            $pdo->prepare(
                "UPDATE member_import_jobs
                    SET status='importing', parsed_rows=?, parse_offset=?, parse_byte_offset=?, fail_count=?,
                        total_rows=GREATEST(total_rows, ?)
                  WHERE id=?"
            )->execute([$newParsed, $newOffset, $byteOffset, $newFail, $newParsed, $jobId]);
            return ['finished' => false, 'tick' => 'parse', 'parse_done' => true, 'rows_this_tick' => $parsedAdd];
        }

        $pdo->prepare(
            "UPDATE member_import_jobs
                SET parsed_rows=?, parse_offset=?, parse_byte_offset=?, fail_count=?
              WHERE id=?"
        )->execute([$newParsed, $newOffset, $byteOffset, $newFail, $jobId]);

        return ['finished' => false, 'tick' => 'parse', 'rows_this_tick' => $parsedAdd];
    }
}

if (!function_exists('_memberImportImportChunk')) {
    function _memberImportImportChunk(PDO $pdo, array $job): array {
        global $db;
        $db = $pdo;

        $jobId = (int)$job['id'];
        $mode = ((string)($job['mode'] ?? 'skip') === 'update') ? 'update' : 'skip';
        $adminId = (int)($job['admin_id'] ?? 0);

        // Requeue rows stuck in processing from a killed request
        try {
            $pdo->prepare("UPDATE member_import_rows SET status='queued' WHERE job_id=? AND status='processing'")
                ->execute([$jobId]);
        } catch (Throwable $e) {}

        $st = $pdo->prepare(
            "SELECT * FROM member_import_rows
              WHERE job_id=? AND status='queued'
              ORDER BY id ASC
              LIMIT " . (int)MEMBER_IMPORT_IMPORT_CHUNK
        );
        $st->execute([$jobId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (!$rows) {
            $pdo->prepare("UPDATE member_import_jobs SET status='done' WHERE id=?")->execute([$jobId]);
            return ['finished' => true, 'tick' => 'import', 'rows_this_tick' => 0];
        }

        // Claim rows so a crashed/retry tick does not double-insert
        $ids = array_map(static fn($r) => (int)$r['id'], $rows);
        $idList = implode(',', $ids);
        if ($idList !== '') {
            $pdo->exec("UPDATE member_import_rows SET status='processing' WHERE id IN ({$idList}) AND status='queued'");
            // Reload only successfully claimed rows
            $st2 = $pdo->query("SELECT * FROM member_import_rows WHERE id IN ({$idList}) AND status='processing'");
            $rows = $st2 ? ($st2->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        }
        if (!$rows) {
            return ['finished' => false, 'tick' => 'import', 'rows_this_tick' => 0];
        }

        $hasCardExpires = true;
        try {
            if (function_exists('safeColumnExists')) {
                $hasCardExpires = safeColumnExists('members', 'card_expires_at');
            }
        } catch (Throwable $e) {}

        $findBySid = $pdo->prepare(
            "SELECT id, name, phone, email, address, sadasyata_number
             FROM members WHERE UPPER(TRIM(sadasyata_number)) = ? LIMIT 1"
        );
        $findByPhone = $pdo->prepare(
            "SELECT id, name, phone, email, address, sadasyata_number
             FROM members WHERE phone=? ORDER BY id ASC LIMIT 1"
        );
        $findByEmail = $pdo->prepare("SELECT id FROM members WHERE email=? AND email<>'' LIMIT 1");

        $okAdd = 0;
        $skipAdd = 0;
        $failAdd = 0;
        $cardsAdd = 0;

        $mark = $pdo->prepare("UPDATE member_import_rows SET status=?, message=?, member_id=? WHERE id=?");

        foreach ($rows as $r) {
            $rowId = (int)$r['id'];
            $sid = function_exists('memberSsotNormalizeId')
                ? memberSsotNormalizeId((string)$r['sadasyata_number'])
                : strtoupper(trim((string)$r['sadasyata_number']));
            $name = trim((string)$r['full_name']);
            $mobile = trim((string)$r['mobile']);
            if (strlen($mobile) > 10 && strpos($mobile, '977') === 0) {
                $mobile = substr($mobile, -10);
            }
            $email = trim((string)$r['email']);
            $address = trim((string)($r['address'] ?? ''));
            $dob = trim((string)$r['dob']);
            $gender = trim((string)$r['gender']);

            try {
                if ($sid === '') {
                    $mark->execute(['failed', 'सदस्यता नं. (Member ID) खाली छ — SSOT मा अनिवार्य।', null, $rowId]);
                    $failAdd++;
                    continue;
                }

                $existing = null;
                $findBySid->execute([$sid]);
                $existing = $findBySid->fetch(PDO::FETCH_ASSOC) ?: null;

                /* Phone collisions on a *different* Member ID must not hijack SSOT row */
                if (!$existing) {
                    $findByPhone->execute([$mobile]);
                    $byPhone = $findByPhone->fetch(PDO::FETCH_ASSOC) ?: null;
                    if ($byPhone) {
                        $otherSid = function_exists('memberSsotNormalizeId')
                            ? memberSsotNormalizeId((string)($byPhone['sadasyata_number'] ?? ''))
                            : strtoupper(trim((string)($byPhone['sadasyata_number'] ?? '')));
                        if ($otherSid !== '' && $otherSid !== $sid) {
                            $mark->execute([
                                'failed',
                                'यो mobile अर्को सदस्यता नं. (' . $otherSid . ') सँग जोडिएको छ।',
                                (int)$byPhone['id'],
                                $rowId,
                            ]);
                            $failAdd++;
                            continue;
                        }
                        if ($otherSid === $sid) {
                            $existing = $byPhone;
                        } elseif ($otherSid === '') {
                            /* Empty Member ID on phone-matched row: fill in update mode only */
                            if ($mode === 'update') {
                                $existing = $byPhone;
                            } else {
                                $mark->execute([
                                    'failed',
                                    'यो mobile मा Member ID खाली भएको पुरानो row छ। Update mode प्रयोग गरी Member ID भर्नुहोस्।',
                                    (int)$byPhone['id'],
                                    $rowId,
                                ]);
                                $failAdd++;
                                continue;
                            }
                        }
                    }
                }

                if ($existing) {
                    $memberPk = (int)$existing['id'];
                    if ($mode === 'update') {
                        $existingSid = function_exists('memberSsotNormalizeId')
                            ? memberSsotNormalizeId((string)($existing['sadasyata_number'] ?? ''))
                            : strtoupper(trim((string)($existing['sadasyata_number'] ?? '')));
                        /* Fill empty sadasyata from CSV; never overwrite a different ID */
                        $sidSql = '';
                        $sidParams = [];
                        if ($existingSid === '' && $sid !== '') {
                            $sidSql = 'sadasyata_number=?,';
                            $sidParams[] = $sid;
                        } elseif ($existingSid !== '' && $existingSid !== $sid) {
                            $mark->execute([
                                'failed',
                                'Row को Member ID (' . $existingSid . ') CSV (' . $sid . ') सँग मिल्दैन।',
                                $memberPk,
                                $rowId,
                            ]);
                            $failAdd++;
                            continue;
                        }
                        $up = $pdo->prepare(
                            "UPDATE members SET
                                {$sidSql}
                                name=?,
                                phone=?,
                                email=COALESCE(NULLIF(?, ''), email),
                                address=COALESCE(NULLIF(?, ''), address),
                                dob=COALESCE(NULLIF(?, ''), dob),
                                gender=COALESCE(NULLIF(?, ''), gender),
                                approval_status='approved',
                                is_active=1
                             WHERE id=?"
                        );
                        $up->execute(array_merge($sidParams, [
                            $name,
                            $mobile,
                            $email,
                            $address,
                            $dob !== '' ? $dob : null,
                            $gender,
                            $memberPk,
                        ]));
                        $cardOk = false;
                        if (function_exists('adminGenerateMemberIdCard')) {
                            $cardOk = (bool)adminGenerateMemberIdCard($memberPk, $adminId, true);
                        }
                        if ($cardOk) $cardsAdd++;
                        $kymMsg = '';
                        if (function_exists('memberSsotEnsureKycStubFromMember')) {
                            $kr = memberSsotEnsureKycStubFromMember($pdo, $memberPk);
                            if (!empty($kr['ok'])) {
                                $kymMsg = !empty($kr['created']) ? ' + KYM stub' : ' + KYM soft-fill/link';
                            }
                        }
                        $mark->execute([
                            'ok',
                            'Updated existing member'
                                . ($sidParams ? ' + Member ID filled' : '')
                                . ($cardOk ? ' + card' : '')
                                . $kymMsg,
                            $memberPk,
                            $rowId,
                        ]);
                        $okAdd++;
                    } else {
                        $mark->execute(['skipped', 'Duplicate (Member ID/mobile पहिले नै छ) — Update mode प्रयोग गर्नुहोस्।', $memberPk, $rowId]);
                        $skipAdd++;
                    }
                    continue;
                }

                if ($email !== '') {
                    $findByEmail->execute([$email]);
                    if ($findByEmail->fetchColumn()) {
                        $mark->execute(['failed', 'Email पहिले नै प्रयोग भइसकेको छ।', null, $rowId]);
                        $failAdd++;
                        continue;
                    }
                }

                $tempPass = memberImportTempPassword($mobile, $sid);
                $hash = password_hash($tempPass, PASSWORD_BCRYPT);

                if ($hasCardExpires) {
                    $ins = $pdo->prepare(
                        "INSERT INTO members
                            (name, email, phone, sadasyata_number, password_hash, address, dob, gender,
                             approval_status, approved_at, approved_by, is_active, card_expires_at)
                         VALUES (?,?,?,?,?,?,?,?, 'approved', NOW(), ?, 1, DATE_ADD(NOW(), INTERVAL 5 YEAR))"
                    );
                    $ins->execute([
                        $name,
                        $email !== '' ? $email : null,
                        $mobile,
                        $sid,
                        $hash,
                        $address !== '' ? $address : null,
                        $dob !== '' ? $dob : null,
                        $gender !== '' ? $gender : null,
                        $adminId > 0 ? $adminId : null,
                    ]);
                } else {
                    $ins = $pdo->prepare(
                        "INSERT INTO members
                            (name, email, phone, sadasyata_number, password_hash, address, dob, gender,
                             approval_status, approved_at, approved_by, is_active)
                         VALUES (?,?,?,?,?,?,?,?, 'approved', NOW(), ?, 1)"
                    );
                    $ins->execute([
                        $name,
                        $email !== '' ? $email : null,
                        $mobile,
                        $sid,
                        $hash,
                        $address !== '' ? $address : null,
                        $dob !== '' ? $dob : null,
                        $gender !== '' ? $gender : null,
                        $adminId > 0 ? $adminId : null,
                    ]);
                }

                $memberPk = (int)$pdo->lastInsertId();
                $cardOk = false;
                if ($memberPk > 0 && function_exists('adminGenerateMemberIdCard')) {
                    $cardOk = (bool)adminGenerateMemberIdCard($memberPk, $adminId, true);
                }
                if ($cardOk) $cardsAdd++;

                $kymMsg = '';
                if ($memberPk > 0 && function_exists('memberSsotEnsureKycStubFromMember')) {
                    $kr = memberSsotEnsureKycStubFromMember($pdo, $memberPk);
                    if (!empty($kr['ok'])) {
                        $kymMsg = !empty($kr['created']) ? ' + KYM stub' : ' + KYM soft-fill/link';
                    }
                }

                $mark->execute([
                    'ok',
                    'Imported'
                        . ($cardOk ? ' + card generated' : ' (card pending)')
                        . $kymMsg,
                    $memberPk > 0 ? $memberPk : null,
                    $rowId,
                ]);
                $okAdd++;
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                if (stripos($msg, 'Duplicate') !== false) {
                    $mark->execute(['skipped', 'Duplicate key', null, $rowId]);
                    $skipAdd++;
                } else {
                    $mark->execute(['failed', mb_substr($msg, 0, 400), null, $rowId]);
                    $failAdd++;
                }
            }
        }

        $pdo->prepare(
            "UPDATE member_import_jobs
                SET ok_count = ok_count + ?,
                    skip_count = skip_count + ?,
                    fail_count = fail_count + ?,
                    cards_count = cards_count + ?
              WHERE id=?"
        )->execute([$okAdd, $skipAdd, $failAdd, $cardsAdd, $jobId]);

        // More queued?
        $left = $pdo->prepare("SELECT COUNT(*) FROM member_import_rows WHERE job_id=? AND status='queued'");
        $left->execute([$jobId]);
        $remaining = (int)$left->fetchColumn();
        if ($remaining <= 0) {
            $pdo->prepare("UPDATE member_import_jobs SET status='done' WHERE id=?")->execute([$jobId]);
            return ['finished' => true, 'tick' => 'import', 'rows_this_tick' => count($rows)];
        }

        return ['finished' => false, 'tick' => 'import', 'rows_this_tick' => count($rows)];
    }
}

if (!function_exists('memberImportExportErrors')) {
    /** Stream failed/skipped rows as CSV to output */
    function memberImportExportErrors(PDO $pdo, int $jobId): void {
        ensureMemberImportTables($pdo);
        $st = $pdo->prepare(
            "SELECT row_num, sadasyata_number, full_name, mobile, email, status, message
               FROM member_import_rows
              WHERE job_id=? AND status IN ('failed','skipped')
              ORDER BY row_num ASC"
        );
        $st->execute([$jobId]);

        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, ['row_num', 'sadasyata_number', 'full_name', 'mobile', 'email', 'status', 'message']);
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, [
                $row['row_num'],
                $row['sadasyata_number'],
                $row['full_name'],
                $row['mobile'],
                $row['email'],
                $row['status'],
                $row['message'],
            ]);
        }
        fclose($out);
    }
}
