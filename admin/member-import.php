<?php
/**
 * Admin: Bulk Member Import (CSV / Excel-friendly)
 * Chunked jobs for 10k–50k members + auto ID cards.
 */
$GLOBALS['ADMIN_PAGE_BOOT_SKIP_LOGIN'] = true;
require_once __DIR__ . '/includes/admin-page-boot.php';
require_once __DIR__ . '/../includes/member-auth.php';
require_once __DIR__ . '/../includes/member-ssot.php';
require_once __DIR__ . '/../includes/member-import-helpers.php';

if (!isAdminLoggedIn()) {
    if (!empty($_GET['ajax']) || !empty($_POST['ajax'])) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Login required']);
        exit;
    }
    header('Location: ' . ADMIN_URL . 'index.php');
    exit;
}

ensureMemberTables();
$pdo = null;
try { $pdo = getDB(); } catch (Throwable $e) { $pdo = null; }
if ($pdo) {
    ensureMemberImportTables($pdo);
    if (function_exists('ensureMembersListSchema')) {
        try { ensureMembersListSchema($pdo); } catch (Throwable $e) {}
    }
}

$adminId = (int)($_SESSION['admin_id'] ?? ($_SESSION['user_id'] ?? 0));
$ajaxAction = (string)($_GET['ajax'] ?? $_POST['ajax'] ?? '');

/* ── AJAX / download endpoints (before any HTML) ── */
if ($ajaxAction !== '') {
    if (!$pdo) {
        header('Content-Type: application/json; charset=UTF-8');
        http_response_code(503);
        echo json_encode(['ok' => false, 'error' => 'DB जडान भएन।']);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        /* Same gate as page view: any logged-in admin staff who can open Members */
        if (!isAdminLoggedIn()) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Login required']);
            exit;
        }
        if (function_exists('has_role') && !has_role('staff')) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Permission denied']);
            exit;
        }
        if (!function_exists('verifyCSRFToken') || !verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'error' => 'CSRF invalid — पेज refresh गरेर फेरि प्रयास गर्नुहोस्।']);
            exit;
        }
    }

    if ($ajaxAction === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=UTF-8');
        $mode = (($_POST['mode'] ?? 'update') === 'skip') ? 'skip' : 'update';
        echo json_encode(memberImportCreateJob($pdo, $_FILES['csv_file'] ?? [], $adminId, $mode));
        exit;
    }

    if ($ajaxAction === 'process' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=UTF-8');
        $jobId = (int)($_POST['job_id'] ?? 0);
        if ($jobId <= 0) {
            echo json_encode(['ok' => false, 'error' => 'job_id required']);
            exit;
        }
        echo json_encode(memberImportProcessTick($pdo, $jobId));
        exit;
    }

    if ($ajaxAction === 'status') {
        header('Content-Type: application/json; charset=UTF-8');
        $jobId = (int)($_GET['job_id'] ?? 0);
        $job = memberImportGetJob($pdo, $jobId);
        if (!$job) {
            echo json_encode(['ok' => false, 'error' => 'Job not found']);
            exit;
        }
        echo json_encode(['ok' => true, 'progress' => memberImportJobProgress($job)]);
        exit;
    }

    if ($ajaxAction === 'errors') {
        $jobId = (int)($_GET['job_id'] ?? 0);
        if ($jobId <= 0) {
            http_response_code(400);
            exit('Invalid job');
        }
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="member-import-errors-' . $jobId . '.csv"');
        memberImportExportErrors($pdo, $jobId);
        exit;
    }

    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

$pageTitle   = 'सदस्य Bulk Import';
$currentPage = 'member-import';
require_once __DIR__ . '/includes/admin-header.php';
require_once __DIR__ . '/includes/admin-ui.php';

$pdo = $db ?? $pdo ?? getDB();
ensureMemberImportTables($pdo);

/* Recent jobs */
$recent = [];
try {
    $recent = $pdo->query(
        "SELECT id, filename, status, mode, total_rows, ok_count, skip_count, fail_count, cards_count, created_at
           FROM member_import_jobs
          ORDER BY id DESC LIMIT 12"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $recent = []; }

$resumeJobId = (int)($_GET['job'] ?? 0);
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div>
        <h1 class="h4 mb-1"><?php echo htmlspecialchars($pageTitle); ?></h1>
        <p class="text-muted small mb-0">
            CBS Excel/CSV बाट Members मा <strong>Member ID (SSOT)</strong> अनुसार import।
            उही Member ID फेरि आउँदा <strong>पुरानो data replace</strong> (खाली optional field जोगिन्छ)।
            CSV → <strong>UTF-8</strong> · ~40MB / 50k+ rows सम्म chunked।
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="member-import-sample.php" class="btn btn-outline-success btn-sm">
            <i class="lucide-icon me-1" data-lucide="download" aria-hidden="true"></i>Sample CSV
        </a>
        <a href="members.php" class="btn btn-outline-secondary btn-sm">
            <i class="lucide-icon me-1" data-lucide="arrow-left" aria-hidden="true"></i>Members
        </a>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <?php if (function_exists('memberSsotAdminHelpHtml')) { echo memberSsotAdminHelpHtml('import'); } ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h2 class="h6 fw-bold mb-3"><i class="lucide-icon me-2 text-success" data-lucide="file-spreadsheet" aria-hidden="true"></i>CSV Upload</h2>

                <div class="alert alert-info small py-2">
                    <strong>अनिवार्य (compulsory):</strong>
                    <code>member_id</code>, <code>full_name</code> (English)
                    <span class="text-muted">(alias: <code>sadasyata_number</code>, <code>name</code>)</span><br>
                    <strong>Optional</strong> (खाली = OK / re-import मा पुरानो जोगिन्छ):
                    <code>name_np</code> (नेपाली नाम),
                    <code>mobile</code>/<code>phone</code>/<code>contact</code>,
                    <code>email</code>, <code>address</code>, <code>dob</code> (AD <code>YYYY-MM-DD</code> वा <code>DD/MM/YYYY</code>),
                    <code>gender</code>
                    <div class="mt-1"><strong>full_name = English नाम</strong> (CVV) · <strong>name_np = नेपाली नाम</strong> (KYM पूरा नाम)।</div>
                    <div class="mt-1">Member ID / mobile मा <strong>नेपाली अंक</strong> (०–९) राखे पनि भित्र English 0–9 मा convert हुन्छ।</div>
                    <div class="mt-1"><strong>Member ID = SSOT</strong> — उही ID फेरि import → नाम replace; खाली optional ले पुरानो मेटाउँदैन।
                        <a href="member-ssot-duplicates.php">दोहोरो Member ID जाँच →</a>
                    </div>
                </div>

                <form id="miUploadForm" enctype="multipart/form-data" class="mb-3">
                    <?php echo function_exists('csrfField') ? csrfField() : ''; ?>
                    <div class="mb-3">
                        <label for="miFile" class="form-label small fw-semibold">CSV फाइल (Excel → Save As → CSV UTF-8)</label>
                        <input type="file" name="csv_file" id="miFile" class="form-control" accept=".csv,text/csv" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">उही Member ID भएमा</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="mode" id="miModeUpdate" value="update" checked>
                            <label class="form-check-label" for="miModeUpdate"><strong>Update / Replace</strong> (सिफारिस) — नाम replace; खाली optional जोगिने</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="mode" id="miModeSkip" value="skip">
                            <label class="form-check-label" for="miModeSkip">Skip — पहिले नै भएको Member ID छोडी नयाँ मात्र</label>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-success" id="miStartBtn">
                        <i class="lucide-icon me-1" data-lucide="upload" aria-hidden="true"></i>Import सुरु गर्नुहोस्
                    </button>
                </form>

                <div id="miProgressWrap" class="d-none">
                    <div class="d-flex justify-content-between small mb-1">
                        <span id="miPhaseLabel">तयारी…</span>
                        <span id="miPctLabel">0%</span>
                    </div>
                    <div class="progress mb-3" style="height:12px;">
                        <div id="miBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width:0%"></div>
                    </div>
                    <div class="row g-2 small" id="miCounters">
                        <div class="col-6 col-md-4"><div class="border rounded p-2"><div class="text-muted">Total</div><strong id="miTotal">0</strong></div></div>
                        <div class="col-6 col-md-4"><div class="border rounded p-2"><div class="text-muted">Imported</div><strong id="miOk" class="text-success">0</strong></div></div>
                        <div class="col-6 col-md-4"><div class="border rounded p-2"><div class="text-muted">Skipped</div><strong id="miSkip" class="text-warning">0</strong></div></div>
                        <div class="col-6 col-md-4"><div class="border rounded p-2"><div class="text-muted">Failed</div><strong id="miFail" class="text-danger">0</strong></div></div>
                        <div class="col-6 col-md-4"><div class="border rounded p-2"><div class="text-muted">Cards</div><strong id="miCards" class="text-primary">0</strong></div></div>
                    </div>
                    <div id="miDoneBox" class="alert alert-success mt-3 d-none small">
                        <strong>Import सकियो!</strong>
                        <div class="mt-1">Portal temp password: <em>मोबाइलको पछिल्लो ४ अङ्क + सदस्यता नं. का पछिल्लो ४ अङ्क</em>
                            (उदा. mobile …5678 + ID …0123 → <code>56780123</code>)। Mobile खाली भए <code>0000</code> + ID का पछिल्ला ४। Bulk SMS पठाइँदैन।
                        </div>
                        <div class="mt-2 d-flex flex-wrap gap-2">
                            <a href="#" id="miErrorsLink" class="btn btn-sm btn-outline-danger d-none">Error/Skip CSV</a>
                            <a href="members.php" class="btn btn-sm btn-outline-success">Members list</a>
                        </div>
                    </div>
                    <div id="miErrorBox" class="alert alert-danger mt-3 d-none small"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-body">
                <h2 class="h6 fw-bold mb-2"><i class="lucide-icon me-2" data-lucide="info" aria-hidden="true"></i>कसरी गर्ने?</h2>
                <ol class="small mb-0 ps-3">
                    <li>Sample CSV download → Excel मा खोल्नुहोस्।</li>
                    <li><strong>member_id + full_name (EN)</strong> अनिवार्य; <code>name_np</code> / mobile optional।</li>
                    <li><strong>File → Save As → CSV UTF-8</strong>।</li>
                    <li>Upload → Update/Replace (default) → Start।</li>
                    <li>उही Member ID फेरि आउँदा पुरानो members row update हुन्छ।</li>
                </ol>
            </div>
        </div>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold small">हालैका Import Jobs</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0 small">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>File</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$recent): ?>
                        <tr><td colspan="4" class="text-muted text-center py-3">अहिलेसम्म import छैन।</td></tr>
                    <?php else: foreach ($recent as $j): ?>
                        <tr>
                            <td><?php echo (int)$j['id']; ?></td>
                            <td class="text-truncate" style="max-width:120px" title="<?php echo htmlspecialchars($j['filename']); ?>">
                                <?php echo htmlspecialchars($j['filename']); ?>
                                <div class="text-muted" style="font-size:.7rem">
                                    OK <?php echo (int)$j['ok_count']; ?> · Skip <?php echo (int)$j['skip_count']; ?> · Fail <?php echo (int)$j['fail_count']; ?> · Cards <?php echo (int)$j['cards_count']; ?>
                                </div>
                            </td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($j['status']); ?></span></td>
                            <td>
                                <?php if (!in_array($j['status'], ['done', 'failed'], true)): ?>
                                <a class="btn btn-xs btn-outline-primary btn-sm py-0" href="member-import.php?job=<?php echo (int)$j['id']; ?>">Resume</a>
                                <?php else: ?>
                                <a class="btn btn-xs btn-outline-secondary btn-sm py-0" href="member-import.php?ajax=errors&amp;job_id=<?php echo (int)$j['id']; ?>">Errors</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var form = document.getElementById('miUploadForm');
    var wrap = document.getElementById('miProgressWrap');
    var bar = document.getElementById('miBar');
    var phaseEl = document.getElementById('miPhaseLabel');
    var pctEl = document.getElementById('miPctLabel');
    var doneBox = document.getElementById('miDoneBox');
    var errBox = document.getElementById('miErrorBox');
    var errLink = document.getElementById('miErrorsLink');
    var startBtn = document.getElementById('miStartBtn');
    var csrfInput = form ? form.querySelector('[name="csrf_token"]') : null;
    var running = false;
    var jobId = <?php echo (int)$resumeJobId; ?>;

    function parseJsonResponse(r) {
        return r.text().then(function (text) {
            var data = null;
            try {
                data = text ? JSON.parse(text) : null;
            } catch (e) {
                var snippet = (text || '').replace(/\s+/g, ' ').slice(0, 160);
                throw new Error('Server JSON होइन (HTTP ' + r.status + ')' + (snippet ? ': ' + snippet : ''));
            }
            if (!r.ok && (!data || data.ok === undefined)) {
                throw new Error('HTTP ' + r.status);
            }
            return data;
        });
    }

    function showError(msg) {
        errBox.textContent = msg || 'Error';
        errBox.classList.remove('d-none');
        startBtn.disabled = false;
        bar.classList.remove('progress-bar-animated');
    }

    function setProgress(p) {
        if (!p) return;
        var pct = Math.max(0, Math.min(100, parseInt(p.percent || 0, 10)));
        bar.style.width = pct + '%';
        pctEl.textContent = pct + '%';
        document.getElementById('miTotal').textContent = p.total_rows || 0;
        document.getElementById('miOk').textContent = p.ok_count || 0;
        document.getElementById('miSkip').textContent = p.skip_count || 0;
        document.getElementById('miFail').textContent = p.fail_count || 0;
        document.getElementById('miCards').textContent = p.cards_count || 0;
        var label = 'Processing…';
        if (p.phase === 'parsing') label = 'CSV parse गर्दै…';
        else if (p.phase === 'importing') label = 'Members + cards बनाउँदै…';
        else if (p.phase === 'done') label = 'सकियो';
        else if (p.phase === 'failed') label = 'असफल';
        phaseEl.textContent = label + (p.filename ? ' — ' + p.filename : '');
        if ((p.fail_count || 0) + (p.skip_count || 0) > 0 && jobId) {
            errLink.href = 'member-import.php?ajax=errors&job_id=' + jobId;
            errLink.classList.remove('d-none');
        }
    }

    function tick() {
        if (!jobId || !running) return;
        var fd = new FormData();
        fd.append('ajax', 'process');
        fd.append('job_id', String(jobId));
        if (csrfInput) fd.append('csrf_token', csrfInput.value);
        fetch('member-import.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(parseJsonResponse)
            .then(function (data) {
                if (!data || !data.ok) {
                    running = false;
                    showError((data && data.error) ? data.error : 'Import error');
                    return;
                }
                setProgress(data.progress || {});
                if (data.busy) {
                    setTimeout(tick, 400);
                    return;
                }
                if (data.finished || (data.progress && (data.progress.status === 'done' || data.progress.status === 'failed'))) {
                    running = false;
                    bar.classList.remove('progress-bar-animated');
                    startBtn.disabled = false;
                    if (data.progress && data.progress.status === 'failed') {
                        showError(data.progress.error_message || data.error || 'Import failed');
                    } else {
                        doneBox.classList.remove('d-none');
                    }
                    return;
                }
                setTimeout(tick, 80);
            })
            .catch(function (err) {
                running = false;
                showError((err && err.message) ? err.message : 'Network/server error — Resume बाट फेरि प्रयास गर्नुहोस्।');
            });
    }

    function startJob(id) {
        id = parseInt(id, 10) || 0;
        if (id <= 0) {
            showError('Upload पछि job id आएन। फेरि प्रयास गर्नुहोस्।');
            return;
        }
        jobId = id;
        running = true;
        wrap.classList.remove('d-none');
        doneBox.classList.add('d-none');
        errBox.classList.add('d-none');
        bar.classList.add('progress-bar-animated');
        startBtn.disabled = true;
        phaseEl.textContent = 'CSV parse गर्दै…';
        pctEl.textContent = '1%';
        bar.style.width = '1%';
        tick();
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var fileInput = document.getElementById('miFile');
            if (!fileInput.files || !fileInput.files[0]) return;
            var name = (fileInput.files[0].name || '').toLowerCase();
            if (name && !name.endsWith('.csv')) {
                wrap.classList.remove('d-none');
                showError('CSV UTF-8 फाइल चाहिन्छ (.csv)। Excel → Save As → CSV UTF-8।');
                return;
            }
            var fd = new FormData(form);
            fd.append('ajax', 'upload');
            startBtn.disabled = true;
            wrap.classList.remove('d-none');
            doneBox.classList.add('d-none');
            errBox.classList.add('d-none');
            bar.classList.add('progress-bar-animated');
            phaseEl.textContent = 'Upload गर्दै…';
            pctEl.textContent = '0%';
            bar.style.width = '0%';
            fetch('member-import.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(parseJsonResponse)
                .then(function (data) {
                    if (!data || !data.ok) {
                        showError((data && data.error) ? data.error : 'Upload failed');
                        return;
                    }
                    startJob(data.job_id);
                })
                .catch(function (err) {
                    showError((err && err.message) ? ('Upload: ' + err.message) : 'Upload network error');
                });
        });
    }

    <?php if ($resumeJobId > 0): ?>
    startJob(<?php echo (int)$resumeJobId; ?>);
    <?php endif; ?>
})();
</script>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>
