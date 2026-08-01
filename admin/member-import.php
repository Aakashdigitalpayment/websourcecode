<?php
/**
 * Admin: Bulk Member Import (CSV / Excel-friendly)
 * Chunked jobs for 10k–50k members + auto ID cards.
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/member-auth.php';
require_once __DIR__ . '/../includes/member-import-helpers.php';
require_once __DIR__ . '/../includes/auth-roles.php';

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
        if (!function_exists('has_role') || !has_role('admin')) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Permission denied']);
            exit;
        }
        if (!function_exists('verifyCSRFToken') || !verifyCSRFToken($_POST['csrf_token'] ?? '')) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['ok' => false, 'error' => 'CSRF invalid']);
            exit;
        }
    }

    if ($ajaxAction === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=UTF-8');
        $mode = (($_POST['mode'] ?? '') === 'update') ? 'update' : 'skip';
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
            पुराना सदस्य CSV बाट upload गर्नुहोस् — कार्ड auto-generate हुन्छ। Excel मा भरेर <strong>Save As → CSV UTF-8</strong> गर्नुहोस्।
        </p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="member-import-sample.php" class="btn btn-outline-success btn-sm">
            <i class="fas fa-download me-1"></i>Sample CSV
        </a>
        <a href="members.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-arrow-left me-1"></i>Members
        </a>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h2 class="h6 fw-bold mb-3"><i class="fas fa-file-csv me-2 text-success"></i>CSV Upload</h2>

                <div class="alert alert-info small py-2">
                    <strong>Required columns:</strong>
                    <code>sadasyata_number</code>, <code>full_name</code>, <code>mobile</code><br>
                    Optional: <code>email</code>, <code>address</code>, <code>dob</code> (AD <code>YYYY-MM-DD</code> वा <code>DD/MM/YYYY</code>),
                    <code>gender</code>, <code>branch</code>, <code>remarks</code>
                </div>

                <form id="miUploadForm" enctype="multipart/form-data" class="mb-3">
                    <?php echo function_exists('csrfField') ? csrfField() : ''; ?>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">CSV फाइल</label>
                        <input type="file" name="csv_file" id="miFile" class="form-control" accept=".csv,text/csv" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Duplicate भएमा</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="mode" id="miModeSkip" value="skip" checked>
                            <label class="form-check-label" for="miModeSkip">Skip (सिफारिस) — नयाँ नबनाउने</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="mode" id="miModeUpdate" value="update">
                            <label class="form-check-label" for="miModeUpdate">Update — नाम/ठेगाना आदि refresh + card ensure</label>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-success" id="miStartBtn">
                        <i class="fas fa-upload me-1"></i>Import सुरु गर्नुहोस्
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
                            (उदा. mobile …5678 + ID …0123 → <code>56780123</code>)। Bulk SMS पठाइँदैन।
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
                <h2 class="h6 fw-bold mb-2"><i class="fas fa-info-circle me-2"></i>कसरी गर्ने?</h2>
                <ol class="small mb-0 ps-3">
                    <li>Sample CSV download गर्नुहोस्।</li>
                    <li>Excel मा खोल्नुहोस् → सदस्य भर्नुहोस्।</li>
                    <li><strong>File → Save As → CSV UTF-8</strong>।</li>
                    <li>यहाँ upload → Skip/Update छान्नुहोस् → Start।</li>
                    <li>Progress आफैं चल्छ (ठूलो फाइलमा धेरै chunk)।</li>
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
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    running = false;
                    errBox.textContent = (data && data.error) ? data.error : 'Import error';
                    errBox.classList.remove('d-none');
                    bar.classList.remove('progress-bar-animated');
                    startBtn.disabled = false;
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
                        errBox.textContent = data.progress.error_message || data.error || 'Import failed';
                        errBox.classList.remove('d-none');
                    } else {
                        doneBox.classList.remove('d-none');
                    }
                    return;
                }
                setTimeout(tick, 80);
            })
            .catch(function () {
                running = false;
                errBox.textContent = 'Network/server error — Resume बाट फेरि प्रयास गर्नुहोस्।';
                errBox.classList.remove('d-none');
                startBtn.disabled = false;
                bar.classList.remove('progress-bar-animated');
            });
    }

    function startJob(id) {
        jobId = id;
        running = true;
        wrap.classList.remove('d-none');
        doneBox.classList.add('d-none');
        errBox.classList.add('d-none');
        bar.classList.add('progress-bar-animated');
        startBtn.disabled = true;
        tick();
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var fileInput = document.getElementById('miFile');
            if (!fileInput.files || !fileInput.files[0]) return;
            var fd = new FormData(form);
            fd.append('ajax', 'upload');
            startBtn.disabled = true;
            wrap.classList.remove('d-none');
            phaseEl.textContent = 'Upload गर्दै…';
            fetch('member-import.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (!data || !data.ok) {
                        errBox.textContent = (data && data.error) ? data.error : 'Upload failed';
                        errBox.classList.remove('d-none');
                        startBtn.disabled = false;
                        return;
                    }
                    startJob(data.job_id);
                })
                .catch(function () {
                    errBox.textContent = 'Upload network error';
                    errBox.classList.remove('d-none');
                    startBtn.disabled = false;
                });
        });
    }

    <?php if ($resumeJobId > 0): ?>
    startJob(<?php echo (int)$resumeJobId; ?>);
    <?php endif; ?>
})();
</script>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>
