<?php
/* ── Auto-create folder action (AJAX POST from "Create Folder" button) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['folder_key'], $_POST['csrf_token'])) {
    require_once 'includes/admin-header.php';   /* loads session + CSRF helpers */
    header('Content-Type: application/json');
    if (!checkCSRF($_POST['csrf_token'])) {
        echo json_encode(['ok' => false, 'msg' => 'CSRF mismatch.']);
        exit;
    }
    if ($_POST['action'] === 'create_folder') {
        $allowedKeys = ['admin_replies', 'cache'];
        $folderMap   = [
            'admin_replies' => ROOT_PATH . 'assets/uploads/admin-replies',
            'cache'         => ROOT_PATH . 'cache',
        ];
        $key = $_POST['folder_key'];
        if (!in_array($key, $allowedKeys, true)) {
            echo json_encode(['ok' => false, 'msg' => 'Unknown folder key.']);
            exit;
        }
        $target = $folderMap[$key];
        if (is_dir($target)) {
            echo json_encode(['ok' => true, 'msg' => 'Folder already exists.']);
        } elseif (@mkdir($target, 0755, true)) {
            echo json_encode(['ok' => true, 'msg' => 'Folder created successfully.']);
        } else {
            echo json_encode(['ok' => false, 'msg' => 'Could not create folder. Check server file permissions.']);
        }
        exit;
    }
    echo json_encode(['ok' => false, 'msg' => 'Unknown action.']);
    exit;
}

$pageTitle = 'Site Health Check';
$currentPage = 'site-health';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

function healthStatusBadge($status) {
    $map = [
        'ok' => ['class' => 'success', 'label' => 'OK'],
        'warn' => ['class' => 'warning', 'label' => 'Warning'],
        'fail' => ['class' => 'danger', 'label' => 'Fix Needed'],
    ];
    $item = $map[$status] ?? $map['warn'];
    return '<span class="badge bg-' . $item['class'] . '">' . $item['label'] . '</span>';
}

function healthRow($title, $status, $message, $fix = '') {
    return [
        'title' => $title,
        'status' => $status,
        'message' => $message,
        'fix' => $fix,
    ];
}

$checks = [];

$checks[] = healthRow(
    'PHP Version',
    version_compare(PHP_VERSION, '8.0.0', '>=') ? 'ok' : 'fail',
    'Current PHP version: ' . PHP_VERSION,
    'cPanel → Select PHP Version बाट PHP 8.0 वा माथि set गर्नुहोस्।'
);

foreach (['pdo', 'pdo_mysql', 'mbstring', 'json', 'openssl', 'curl', 'gd'] as $extension) {
    $checks[] = healthRow(
        'PHP Extension: ' . $extension,
        extension_loaded($extension) ? 'ok' : ($extension === 'gd' || $extension === 'curl' ? 'warn' : 'fail'),
        extension_loaded($extension) ? 'Loaded' : 'Not loaded',
        'cPanel PHP Extensions बाट ' . $extension . ' enable गर्नुहोस्।'
    );
}

try {
    $db = getDB();
    $dbName = $db->query('SELECT DATABASE()')->fetchColumn();
    $tableCount = $db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
    $checks[] = healthRow('Database Connection', 'ok', 'Connected to database: ' . ($dbName ?: DB_NAME));
    $checks[] = healthRow(
        'Database Tables',
        (int)$tableCount > 0 ? 'ok' : 'warn',
        (int)$tableCount . ' table(s) found',
        'phpMyAdmin मा database/install.sql import गर्नुहोस्।'
    );
} catch (Throwable $e) {
    $checks[] = healthRow(
        'Database Connection',
        'fail',
        'Connection failed: ' . $e->getMessage(),
        'includes/database.local.php वा includes/database.php मा DB_NAME, DB_USER, DB_PASS जाँच गर्नुहोस्।'
    );
}

/* folder_key = non-empty → show "Create Folder" button when status is 'fail' */
$paths = [
    ['label' => 'Upload Folder',        'path' => ROOT_PATH . 'assets/uploads/', 'key' => ''],
    ['label' => 'Admin Replies Folder', 'path' => ROOT_PATH . 'assets/uploads/admin-replies/', 'key' => 'admin_replies'],
    ['label' => 'Cache Folder',         'path' => ROOT_PATH . 'cache/',           'key' => 'cache'],
    ['label' => 'Logs Folder',          'path' => ROOT_PATH . 'logs/',            'key' => ''],
];

foreach ($paths as $entry) {
    $label    = $entry['label'];
    $path     = $entry['path'];
    $folderKey = $entry['key'];
    $exists   = is_dir($path);
    $writable = $exists && is_writable($path);
    $row = healthRow(
        $label,
        $writable ? 'ok' : ($exists ? 'warn' : 'fail'),
        $exists ? ($writable ? 'Folder exists and is writable' : 'Folder exists but is not writable') : 'Folder missing',
        $exists ? 'cPanel File Manager बाट permission 755 वा hosting support अनुसार writable बनाउनुहोस्।' : 'यो folder create गर्नुहोस्: ' . str_replace(ROOT_PATH, '', $path)
    );
    $row['folder_key'] = (!$exists && $folderKey) ? $folderKey : '';
    $checks[] = $row;
}

$databaseDist = ROOT_PATH . 'includes/database.dist.php';
$databaseLocal = ROOT_PATH . 'includes/database.local.php';
$databaseLegacy = ROOT_PATH . 'includes/database.php';
$checks[] = healthRow(
    'Database loader (tracked)',
    file_exists($databaseDist) ? 'ok' : 'fail',
    file_exists($databaseDist) ? 'includes/database.dist.php found' : 'includes/database.dist.php missing',
    'Package बाट includes/database.dist.php upload गर्नुहोस्।'
);
$checks[] = healthRow(
    'DB credentials file (gitignored)',
    (file_exists($databaseLocal) || file_exists($databaseLegacy)) ? 'ok' : 'warn',
    file_exists($databaseLocal) ? 'includes/database.local.php found'
        : (file_exists($databaseLegacy) ? 'includes/database.php (legacy) found' : 'no local credentials file'),
    'includes/database.local.php.example → database.local.php कपी गरी भर्नुहोस्।'
);

$mainDatabaseSql = ROOT_PATH . 'database/install.sql';
$checks[] = healthRow(
    'Single Database SQL File',
    file_exists($mainDatabaseSql) ? 'ok' : 'warn',
    file_exists($mainDatabaseSql) ? 'database/install.sql found' : 'database/install.sql missing',
    'Package बाट database/install.sql upload गर्नुहोस्।'
);

$htaccess = ROOT_PATH . '.htaccess';
$checks[] = healthRow(
    '.htaccess File',
    file_exists($htaccess) ? 'ok' : 'warn',
    file_exists($htaccess) ? '.htaccess found' : '.htaccess missing',
    'If clean URLs or security rules fail, package बाट .htaccess upload गर्नुहोस्।'
);

$phpHandlerLooksOk = PHP_SAPI !== 'cli';
$checks[] = healthRow(
    'PHP Handler',
    $phpHandlerLooksOk ? 'ok' : 'warn',
    'Server API: ' . PHP_SAPI,
    'यदि browser मा PHP code text जसरी देखियो भने cPanel मा PHP handler/version enable गर्नुहोस्।'
);

$okCount = count(array_filter($checks, fn($check) => $check['status'] === 'ok'));
$warnCount = count(array_filter($checks, fn($check) => $check['status'] === 'warn'));
$failCount = count(array_filter($checks, fn($check) => $check['status'] === 'fail'));
?>

<?php
/* Expose CSRF token for JS (used by createFolder AJAX) */
echo '<script>window.CSRF_TOKEN=' . json_encode($csrfToken) . ';</script>';
echo adminPageHeader(
    'Site Health Check', 'fa-heart-pulse',
    'Database, PHP, folders, र cPanel setup एकै ठाउँमा check गर्नुहोस्',
    '<a href="site-health.php" class="btn btn-outline-light btn-sm"><i class="fas fa-rotate me-1"></i>Re-check</a>'
);
?>
<div class="container-fluid py-4">
    <div class="row mb-4"></div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="display-6 fw-bold text-success"><?php echo $okCount; ?></div>
                    <div class="text-muted">Passed</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="display-6 fw-bold text-warning"><?php echo $warnCount; ?></div>
                    <div class="text-muted">Warnings</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <div class="display-6 fw-bold text-danger"><?php echo $failCount; ?></div>
                    <div class="text-muted">Need Fix</div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($failCount > 0): ?>
        <div class="alert alert-danger">
            <strong>Fix needed:</strong> रातो status भएका items पहिले मिलाउनुहोस्। धेरैजसो issue `database.local.php` / PHP version / folder permission बाट आउँछ।
        </div>
    <?php elseif ($warnCount > 0): ?>
        <div class="alert alert-warning">
            <strong>Almost ready:</strong> warning items critical नहुन सक्छन्, तर upload/forms/images राम्रो चल्न तिनीहरू मिलाउनु राम्रो हुन्छ।
        </div>
    <?php else: ?>
        <div class="alert alert-success">
            <strong>Everything looks good:</strong> Site setup healthy देखिन्छ।
        </div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Check</th>
                        <th>स्थिति</th>
                        <th>Result</th>
                        <th>How to Fix</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($checks as $idx => $check): ?>
                        <tr id="health-row-<?php echo $idx; ?>">
                            <td class="fw-semibold"><?php echo htmlspecialchars($check['title']); ?></td>
                            <td id="health-status-<?php echo $idx; ?>"><?php echo healthStatusBadge($check['status']); ?></td>
                            <td id="health-msg-<?php echo $idx; ?>"><?php echo htmlspecialchars($check['message']); ?></td>
                            <td class="text-muted small">
                                <?php if (!empty($check['folder_key'])): ?>
                                    <span id="health-fix-<?php echo $idx; ?>">
                                        <?php echo htmlspecialchars($check['fix']); ?>
                                        <button
                                            class="btn btn-sm btn-outline-primary ms-2"
                                            onclick="createFolder('<?php echo htmlspecialchars($check['folder_key']); ?>', <?php echo $idx; ?>, this)"
                                        ><i class="fas fa-folder-plus me-1"></i>Create</button>
                                    </span>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($check['fix'] ?: 'No action needed.'); ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>



<script>
/* ── One-click "Create Folder" handler for Site Health ── */
function createFolder(folderKey, rowIdx, btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Creating…';
    const csrf = window.CSRF_TOKEN || '';
    const fd = new FormData();
    fd.append('action',      'create_folder');
    fd.append('folder_key',  folderKey);
    fd.append('csrf_token',  csrf);
    fetch(window.location.href, { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                document.getElementById('health-status-' + rowIdx).innerHTML =
                    '<span class="badge bg-success">OK</span>';
                document.getElementById('health-msg-' + rowIdx).textContent =
                    'Folder exists and is writable';
                document.getElementById('health-fix-' + rowIdx).innerHTML =
                    '<span class="text-success"><i class="fas fa-check me-1"></i>' +
                    htmlEsc(data.msg) + '</span>';
            } else {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-folder-plus me-1"></i>Retry';
                alert('Error: ' + data.msg);
            }
        })
        .catch(() => {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-folder-plus me-1"></i>Retry';
            alert('Network error — please try again.');
        });
}
function htmlEsc(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
</script>

      <!-- ═══════════════════════════════════════════════════════════════════
           FORM FIELD PREVIEW — Live visual consistency check
           ══════════════════════════════════════════════════════════════════ -->
      <div class="card border-0 shadow-sm mt-4">
          <div class="card-header bg-white border-bottom d-flex align-items-center justify-content-between py-3">
              <div>
                  <i class="fas fa-eye me-2 text-primary"></i>
                  <span class="fw-semibold">Form Field Preview</span>
                  <span class="text-muted small ms-2">— सबै field types को visual consistency यहाँ check गर्नुहोस्</span>
              </div>
              <div class="d-flex gap-2 align-items-center">
                  <span id="fp-theme-label" class="text-muted small">Light</span>
                  <div class="form-check form-switch mb-0">
                      <input class="form-check-input" type="checkbox" id="fp-dark-toggle" title="Dark mode toggle">
                      <label class="form-check-label small" for="fp-dark-toggle">Dark</label>
                  </div>
              </div>
          </div>

          <!-- Tabs -->
          <div class="card-header bg-light border-bottom px-3 py-0">
              <ul class="nav nav-tabs admin-nav-tabs card-header-tabs" id="fpTabs" role="tablist">
                  <li class="nav-item" role="presentation">
                      <button class="nav-link active small" id="fp-text-tab"     data-bs-toggle="tab" data-bs-target="#fp-text"     type="button">Inputs</button>
                  </li>
                  <li class="nav-item" role="presentation">
                      <button class="nav-link small"        id="fp-select-tab"   data-bs-toggle="tab" data-bs-target="#fp-select"   type="button">Select / Dropdown</button>
                  </li>
                  <li class="nav-item" role="presentation">
                      <button class="nav-link small"        id="fp-textarea-tab" data-bs-toggle="tab" data-bs-target="#fp-textarea" type="button">Textarea</button>
                  </li>
                  <li class="nav-item" role="presentation">
                      <button class="nav-link small"        id="fp-checks-tab"   data-bs-toggle="tab" data-bs-target="#fp-checks"   type="button">Checkbox / Radio</button>
                  </li>
                  <li class="nav-item" role="presentation">
                      <button class="nav-link small"        id="fp-states-tab"   data-bs-toggle="tab" data-bs-target="#fp-states"   type="button">States</button>
                  </li>
                  <li class="nav-item" role="presentation">
                      <button class="nav-link small"        id="fp-groups-tab"   data-bs-toggle="tab" data-bs-target="#fp-groups"   type="button">Input Groups</button>
                  </li>
              </ul>
          </div>

          <div class="card-body" id="fp-canvas">
              <div class="tab-content">

                  <!-- ── TAB 1: Text Inputs ── -->
                  <div class="tab-pane fade show active" id="fp-text" role="tabpanel">
                      <div class="fp-section-label">Text-Type Inputs</div>
                      <div class="row g-3">
                          <?php
                          $textInputs = [
                              ['type'=>'text',     'label'=>'Text',          'placeholder'=>'पूरा नाम लेख्नुहोस्',       'icon'=>'fa-user'],
                              ['type'=>'email',    'label'=>'Email',         'placeholder'=>'example@coop.com',           'icon'=>'fa-envelope'],
                              ['type'=>'number',   'label'=>'Number',        'placeholder'=>'०',                          'icon'=>'fa-hashtag'],
                              ['type'=>'tel',      'label'=>'Phone / Tel',   'placeholder'=>'९८xxxxxx',                   'icon'=>'fa-phone'],
                              ['type'=>'password', 'label'=>'Password',      'placeholder'=>'••••••••',                   'icon'=>'fa-lock'],
                              ['type'=>'url',      'label'=>'URL',           'placeholder'=>'https://example.com',        'icon'=>'fa-link'],
                              ['type'=>'search',   'label'=>'Search',        'placeholder'=>'खोज्नुहोस्…',               'icon'=>'fa-magnifying-glass'],
                              ['type'=>'date',     'label'=>'Date',          'placeholder'=>'',                           'icon'=>'fa-calendar'],
                              ['type'=>'time',     'label'=>'Time',          'placeholder'=>'',                           'icon'=>'fa-clock'],
                              ['type'=>'color',    'label'=>'Color Picker',  'placeholder'=>'',                           'icon'=>'fa-palette'],
                              ['type'=>'range',    'label'=>'Range Slider',  'placeholder'=>'',                           'icon'=>'fa-sliders'],
                              ['type'=>'file',     'label'=>'File Upload',   'placeholder'=>'',                           'icon'=>'fa-upload'],
                          ];
                          foreach ($textInputs as $inp):
                          ?>
                          <div class="col-md-6 col-xl-4">
                              <label class="form-label fw-semibold small text-muted mb-1">
                                  <i class="fas <?php echo $inp['icon']; ?> me-1"></i><?php echo $inp['label']; ?>
                                  <code class="ms-1 text-muted" style="font-size:.7rem;">type="<?php echo $inp['type']; ?>"</code>
                              </label>
                              <?php if ($inp['type'] === 'range'): ?>
                                  <input type="range" class="form-range" min="0" max="100" value="40">
                                  <div class="d-flex justify-content-between"><small class="text-muted">0</small><small class="text-muted">100</small></div>
                              <?php elseif ($inp['type'] === 'file'): ?>
                                  <input type="file" class="form-control form-control-sm">
                              <?php else: ?>
                                  <input type="<?php echo $inp['type']; ?>"
                                         class="form-control form-control-sm"
                                         placeholder="<?php echo htmlspecialchars($inp['placeholder']); ?>">
                              <?php endif; ?>
                          </div>
                          <?php endforeach; ?>
                      </div>
                  </div>

                  <!-- ── TAB 2: Select / Dropdown ── -->
                  <div class="tab-pane fade" id="fp-select" role="tabpanel">
                      <div class="fp-section-label">Selects &amp; Dropdowns</div>
                      <div class="row g-4">
                          <div class="col-md-6">
                              <label class="form-label fw-semibold small">Single Select <code class="text-muted" style="font-size:.7rem;">form-select</code></label>
                              <select class="form-select form-select-sm">
                                  <option value="">— छान्नुहोस् —</option>
                                  <option>बचत खाता</option>
                                  <option>ऋण खाता</option>
                                  <option>मुद्दती खाता</option>
                              </select>
                          </div>
                          <div class="col-md-6">
                              <label class="form-label fw-semibold small">Large Select <code class="text-muted" style="font-size:.7rem;">form-select-lg</code></label>
                              <select class="form-select form-select-lg">
                                  <option value="">— छान्नुहोस् —</option>
                                  <option>सदस्य खाता</option>
                                  <option>संस्थागत खाता</option>
                              </select>
                          </div>
                          <div class="col-md-6">
                              <label class="form-label fw-semibold small">Disabled Select</label>
                              <select class="form-select form-select-sm" disabled>
                                  <option>Disabled विकल्प</option>
                              </select>
                          </div>
                          <div class="col-md-6">
                              <label class="form-label fw-semibold small">Invalid Select</label>
                              <select class="form-select form-select-sm is-invalid">
                                  <option value="">— कृपया छान्नुहोस् —</option>
                                  <option>विकल्प १</option>
                              </select>
                              <div class="invalid-feedback">यो field आवश्यक छ।</div>
                          </div>
                          <div class="col-md-6">
                              <label class="form-label fw-semibold small">Multiple Select</label>
                              <select class="form-select form-select-sm" multiple size="4">
                                  <option>बचत</option>
                                  <option selected>ऋण</option>
                                  <option>मुद्दती</option>
                                  <option>कल्याण कोष</option>
                              </select>
                              <div class="form-text">Ctrl/Cmd + click गरी धेरै छान्नुहोस्।</div>
                          </div>
                          <div class="col-md-6">
                              <label class="form-label fw-semibold small">Valid Select</label>
                              <select class="form-select form-select-sm is-valid">
                                  <option>बचत खाता ✓</option>
                              </select>
                              <div class="valid-feedback">राम्रो छ।</div>
                          </div>
                      </div>
                  </div>

                  <!-- ── TAB 3: Textarea ── -->
                  <div class="tab-pane fade" id="fp-textarea" role="tabpanel">
                      <div class="fp-section-label">Textareas</div>
                      <div class="row g-4">
                          <div class="col-md-6">
                              <label class="form-label fw-semibold small">Normal <code class="text-muted" style="font-size:.7rem;">rows="3"</code></label>
                              <textarea class="form-control" rows="3" placeholder="यहाँ टिप्पणी लेख्नुहोस्…"></textarea>
                          </div>
                          <div class="col-md-6">
                              <label class="form-label fw-semibold small">Disabled</label>
                              <textarea class="form-control" rows="3" disabled>यो field disabled छ।</textarea>
                          </div>
                          <div class="col-md-6">
                              <label class="form-label fw-semibold small">Invalid — Error State</label>
                              <textarea class="form-control is-invalid" rows="3" placeholder="विवरण लेख्नुहोस्…"></textarea>
                              <div class="invalid-feedback">यो field खाली छाड्न मिल्दैन।</div>
                          </div>
                          <div class="col-md-6">
                              <label class="form-label fw-semibold small">Valid — Success State</label>
                              <textarea class="form-control is-valid" rows="3">सदस्यता नं १२३४ — राम्रो।</textarea>
                              <div class="valid-feedback">Input valid छ।</div>
                          </div>
                          <div class="col-12">
                              <label class="form-label fw-semibold small">With char counter <code class="text-muted" style="font-size:.7rem;">maxlength="200"</code></label>
                              <textarea class="form-control" rows="3" id="fp-char-ta" maxlength="200" placeholder="यहाँ सूचना/नोट लेख्नुहोस्…"></textarea>
                              <div class="d-flex justify-content-between mt-1">
                                  <div class="form-text">अधिकतम २०० अक्षर।</div>
                                  <div class="form-text"><span id="fp-char-count">0</span> / 200</div>
                              </div>
                          </div>
                      </div>
                  </div>

                  <!-- ── TAB 4: Checkbox / Radio ── -->
                  <div class="tab-pane fade" id="fp-checks" role="tabpanel">
                      <div class="fp-section-label">Checkboxes &amp; Radios</div>
                      <div class="row g-4">
                          <div class="col-md-4">
                              <p class="fw-semibold small text-muted mb-2">Checkboxes</p>
                              <div class="form-check mb-2">
                                  <input class="form-check-input" type="checkbox" id="fc1" checked>
                                  <label class="form-check-label" for="fc1">checked छ</label>
                              </div>
                              <div class="form-check mb-2">
                                  <input class="form-check-input" type="checkbox" id="fc2">
                                  <label class="form-check-label" for="fc2">unchecked</label>
                              </div>
                              <div class="form-check mb-2">
                                  <input class="form-check-input" type="checkbox" id="fc3" disabled>
                                  <label class="form-check-label text-muted" for="fc3">disabled</label>
                              </div>
                              <div class="form-check mb-2">
                                  <input class="form-check-input is-invalid" type="checkbox" id="fc4">
                                  <label class="form-check-label" for="fc4">invalid</label>
                                  <div class="invalid-feedback">आवश्यक छ।</div>
                              </div>
                          </div>
                          <div class="col-md-4">
                              <p class="fw-semibold small text-muted mb-2">Radio Buttons</p>
                              <div class="form-check mb-2">
                                  <input class="form-check-input" type="radio" name="fpRadio" id="fr1" checked>
                                  <label class="form-check-label" for="fr1">पुरुष</label>
                              </div>
                              <div class="form-check mb-2">
                                  <input class="form-check-input" type="radio" name="fpRadio" id="fr2">
                                  <label class="form-check-label" for="fr2">महिला</label>
                              </div>
                              <div class="form-check mb-2">
                                  <input class="form-check-input" type="radio" name="fpRadio" id="fr3">
                                  <label class="form-check-label" for="fr3">अन्य</label>
                              </div>
                              <div class="form-check mb-2">
                                  <input class="form-check-input" type="radio" name="fpRadio" id="fr4" disabled>
                                  <label class="form-check-label text-muted" for="fr4">disabled</label>
                              </div>
                          </div>
                          <div class="col-md-4">
                              <p class="fw-semibold small text-muted mb-2">Toggle Switches</p>
                              <div class="form-check form-switch mb-2">
                                  <input class="form-check-input" type="checkbox" id="fsw1" checked>
                                  <label class="form-check-label" for="fsw1">Active</label>
                              </div>
                              <div class="form-check form-switch mb-2">
                                  <input class="form-check-input" type="checkbox" id="fsw2">
                                  <label class="form-check-label" for="fsw2">Inactive</label>
                              </div>
                              <div class="form-check form-switch mb-2">
                                  <input class="form-check-input" type="checkbox" id="fsw3" disabled checked>
                                  <label class="form-check-label text-muted" for="fsw3">Disabled ON</label>
                              </div>
                              <div class="form-check form-switch mb-2">
                                  <input class="form-check-input" type="checkbox" id="fsw4" disabled>
                                  <label class="form-check-label text-muted" for="fsw4">Disabled OFF</label>
                              </div>
                          </div>

                          <!-- Inline variants -->
                          <div class="col-12">
                              <p class="fw-semibold small text-muted mb-2">Inline Checkboxes</p>
                              <div class="d-flex flex-wrap gap-3">
                                  <?php foreach (['बचत', 'ऋण', 'मुद्दती', 'कल्याण', 'बीमा'] as $i => $opt): ?>
                                  <div class="form-check form-check-inline">
                                      <input class="form-check-input" type="checkbox" id="fci<?php echo $i; ?>" <?php echo $i === 0 ? 'checked' : ''; ?>>
                                      <label class="form-check-label" for="fci<?php echo $i; ?>"><?php echo $opt; ?></label>
                                  </div>
                                  <?php endforeach; ?>
                              </div>
                          </div>
                      </div>
                  </div>

                  <!-- ── TAB 5: States ── -->
                  <div class="tab-pane fade" id="fp-states" role="tabpanel">
                      <div class="fp-section-label">Field States — Normal · Focus · Disabled · Error · Success</div>
                      <div class="row g-3">
                          <?php
                          $states = [
                              ['label'=>'Normal',    'cls'=>'',          'val'=>'',          'ph'=>'Normal field',          'help'=>'Default appearance'],
                              ['label'=>'Filled',    'cls'=>'',          'val'=>'भरिएको मान','ph'=>'',                      'help'=>'With content entered'],
                              ['label'=>'Focus *',   'cls'=>'fp-simfocus','val'=>'',          'ph'=>'Focus (simulated)',     'help'=>'Click any field to see live focus'],
                              ['label'=>'Read Only', 'cls'=>'',          'val'=>'read-only value','ph'=>'',                 'help'=>'readonly', 'extra'=>'readonly'],
                              ['label'=>'Disabled',  'cls'=>'',          'val'=>'Disabled field', 'ph'=>'',                 'help'=>'disabled', 'extra'=>'disabled'],
                              ['label'=>'Invalid',   'cls'=>'is-invalid','val'=>'',          'ph'=>'Invalid field',         'help'=>'Error state', 'err'=>'यो field आवश्यक छ।'],
                              ['label'=>'Valid',     'cls'=>'is-valid',  'val'=>'राम्रो मान', 'ph'=>'',                     'help'=>'Success state', 'ok'=>'Input valid छ।'],
                              ['label'=>'With Help', 'cls'=>'',          'val'=>'',          'ph'=>'Enter value here',      'help'=>'Supporting help text below'],
                              ['label'=>'SM Size',   'cls'=>'form-control-sm', 'val'=>'','ph'=>'Small input (sm)',          'help'=>''],
                              ['label'=>'LG Size',   'cls'=>'form-control-lg', 'val'=>'','ph'=>'Large input (lg)',          'help'=>''],
                              ['label'=>'Rounded',   'cls'=>'rounded-pill','val'=>'',        'ph'=>'Pill-shaped field',     'help'=>''],
                              ['label'=>'Flat',      'cls'=>'rounded-0', 'val'=>'',          'ph'=>'No border-radius',      'help'=>''],
                          ];
                          foreach ($states as $s):
                              $extra = isset($s['extra']) ? $s['extra'] : '';
                          ?>
                          <div class="col-md-6 col-xl-4">
                              <label class="form-label fw-semibold small text-muted mb-1"><?php echo $s['label']; ?></label>
                              <input type="text"
                                     class="form-control <?php echo $s['cls']; ?>"
                                     value="<?php echo htmlspecialchars($s['val']); ?>"
                                     placeholder="<?php echo htmlspecialchars($s['ph']); ?>"
                                     <?php echo $extra; ?>>
                              <?php if (!empty($s['err'])): ?>
                                  <div class="invalid-feedback"><?php echo $s['err']; ?></div>
                              <?php endif; ?>
                              <?php if (!empty($s['ok'])): ?>
                                  <div class="valid-feedback"><?php echo $s['ok']; ?></div>
                              <?php endif; ?>
                              <?php if (!empty($s['help']) && empty($s['err']) && empty($s['ok'])): ?>
                                  <div class="form-text"><?php echo $s['help']; ?></div>
                              <?php endif; ?>
                          </div>
                          <?php endforeach; ?>
                      </div>
                  </div>

                  <!-- ── TAB 6: Input Groups ── -->
                  <div class="tab-pane fade" id="fp-groups" role="tabpanel">
                      <div class="fp-section-label">Input Groups &amp; Add-ons</div>
                      <div class="row g-4">
                          <div class="col-md-6">
                              <label class="form-label fw-semibold small">Prefix icon / text</label>
                              <div class="input-group input-group-sm">
                                  <span class="input-group-text"><i class="fas fa-user"></i></span>
                                  <input type="text" class="form-control" placeholder="सदस्यको नाम">
                              </div>
                          </div>
                          <div class="col-md-6">
                              <label class="form-label fw-semibold small">Suffix text</label>
                              <div class="input-group input-group-sm">
                                  <input type="number" class="form-control" placeholder="रकम">
                                  <span class="input-group-text">रु.</span>
                              </div>
                          </div>
                          <div class="col-md-6">
                              <label class="form-label fw-semibold small">Both sides</label>
                              <div class="input-group input-group-sm">
                                  <span class="input-group-text"><i class="fas fa-phone"></i></span>
                                  <input type="tel" class="form-control" placeholder="९८xxxxxxxx">
                                  <span class="input-group-text">NP</span>
                              </div>
                          </div>
                          <div class="col-md-6">
                              <label class="form-label fw-semibold small">With button</label>
                              <div class="input-group input-group-sm">
                                  <input type="text" class="form-control" placeholder="खोज्नुहोस्…">
                                  <button class="btn btn-primary" type="button" aria-label="Search" title="Search"><i class="fas fa-search"></i></button>
                              </div>
                          </div>
                          <div class="col-md-6">
                              <label class="form-label fw-semibold small">Invalid group</label>
                              <div class="input-group input-group-sm has-validation">
                                  <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                  <input type="email" class="form-control is-invalid" placeholder="email@domain.com">
                                  <div class="invalid-feedback">Valid email आवश्यक छ।</div>
                              </div>
                          </div>
                          <div class="col-md-6">
                              <label class="form-label fw-semibold small">Select + Input combo</label>
                              <div class="input-group input-group-sm">
                                  <select class="form-select" style="max-width:110px">
                                      <option>NPR</option>
                                      <option>USD</option>
                                      <option>INR</option>
                                  </select>
                                  <input type="number" class="form-control" placeholder="०.००">
                              </div>
                          </div>
                          <div class="col-md-6">
                              <label class="form-label fw-semibold small">Copy-to-clipboard</label>
                              <div class="input-group input-group-sm">
                                  <input type="text" class="form-control" id="fp-copy-field" value="MEMBER-<?php echo str_pad(rand(1000,9999),6,'0',STR_PAD_LEFT); ?>" readonly>
                                  <button class="btn btn-outline-secondary" type="button" id="fp-copy-btn" title="Copy">
                                      <i class="lucide-icon" aria-hidden="true" data-lucide="copy"></i>
                                  </button>
                              </div>
                          </div>
                          <div class="col-md-6">
                              <label class="form-label fw-semibold small">Password reveal</label>
                              <div class="input-group input-group-sm">
                                  <input type="password" class="form-control" id="fp-pw-field" value="secret1234">
                                  <button class="btn btn-outline-secondary" type="button" id="fp-pw-toggle" title="Show/Hide">
                                      <i class="fas fa-eye" id="fp-pw-icon"></i>
                                  </button>
                              </div>
                          </div>
                      </div>
                  </div>

              </div><!-- /.tab-content -->
          </div><!-- /.card-body -->

          <div class="card-footer bg-light border-top small text-muted d-flex justify-content-between align-items-center py-2">
              <span><i class="fas fa-circle-info me-1"></i>यो section केवल visual reference हो — कुनै पनि data submit हुँदैन।</span>
              <button class="btn btn-sm btn-outline-secondary" id="fp-reset-btn"><i class="fas fa-rotate-left me-1"></i>Reset Fields</button>
          </div>
      </div><!-- /.card form field preview -->


      <script>
      (function() {
          /* Dark mode toggle */
          var darkToggle = document.getElementById('fp-dark-toggle');
          var canvas     = document.getElementById('fp-canvas');
          var label      = document.getElementById('fp-theme-label');
          if (darkToggle) {
              darkToggle.addEventListener('change', function () {
                  canvas.classList.toggle('fp-dark', this.checked);
                  if (label) label.textContent = this.checked ? 'Dark' : 'Light';
              });
          }

          /* Char counter */
          var ta = document.getElementById('fp-char-ta');
          var cc = document.getElementById('fp-char-count');
          if (ta && cc) {
              ta.addEventListener('input', function() {
                  cc.textContent = this.value.length;
                  cc.style.color = this.value.length > 180 ? '#dc3545' : '';
              });
          }

          /* Copy to clipboard */
          var copyBtn = document.getElementById('fp-copy-btn');
          if (copyBtn) {
              copyBtn.addEventListener('click', function () {
                  var field = document.getElementById('fp-copy-field');
                  if (!field) return;
                  navigator.clipboard.writeText(field.value).then(function() {
                      var orig = copyBtn.innerHTML;
                      copyBtn.innerHTML = '<i class="fas fa-check text-success"></i>';
                      setTimeout(function() { copyBtn.innerHTML = orig; }, 1500);
                  }).catch(function() {
                      field.select();
                      document.execCommand('copy');
                  });
              });
          }

          /* Password reveal */
          var pwToggle = document.getElementById('fp-pw-toggle');
          if (pwToggle) {
              pwToggle.addEventListener('click', function () {
                  var field = document.getElementById('fp-pw-field');
                  var icon  = document.getElementById('fp-pw-icon');
                  if (!field) return;
                  var shown = field.type === 'text';
                  field.type = shown ? 'password' : 'text';
                  if (icon) { icon.className = shown ? 'fas fa-eye' : 'fas fa-eye-slash'; }
              });
          }

          /* Reset all writable fields */
          var resetBtn = document.getElementById('fp-reset-btn');
          if (resetBtn) {
              resetBtn.addEventListener('click', function () {
                  canvas.querySelectorAll('input:not([type=checkbox]):not([type=radio]):not([type=color]):not([type=range]):not([disabled]):not([readonly])').forEach(function(i) {
                      if (i.type === 'file') return;
                      i.value = '';
                  });
                  canvas.querySelectorAll('textarea:not([disabled])').forEach(function(t) { t.value = ''; });
                  if (cc) cc.textContent = '0';
                  var field = document.getElementById('fp-pw-field');
                  if (field) { field.type = 'password'; }
                  var icon = document.getElementById('fp-pw-icon');
                  if (icon) { icon.className = 'fas fa-eye'; }
              });
          }
      })();
      </script>
  
<?php require_once 'includes/admin-footer.php'; ?>
