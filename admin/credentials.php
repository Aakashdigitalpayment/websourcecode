<?php
/**
 * 🔑 Smart Credential Manager (Office Dashboard)
 * ─────────────────────────────────────────────────────────────
 * सरकारी/आधिकारिक site को URL + username + encrypted password।
 * Click गर्ने बित्तिकै site नयाँ tab मा खुल्छ + credentials copy button।
 * सबै action audit log मा record हुन्छ।
 */
require_once __DIR__ . '/includes/admin-header.php';
require_once __DIR__ . '/../includes/auth-roles.php';
require_once __DIR__ . '/../includes/credentials-crypto.php';
require_role('staff');  /* staff+ allowed to view; mutate चाहिँ admin+ */

$db = getDB();

/* ── AJAX: reveal password (POST + CSRF only — never GET) ── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['ajax'] ?? '') === 'reveal') {
    header('Content-Type: application/json; charset=utf-8');
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($id <= 0) {
            throw new Exception('Not found');
        }
        $row = $db->prepare("SELECT password_enc, password_iv FROM office_credentials WHERE id = ?");
        $row->execute([$id]);
        $r = $row->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new Exception('Not found');
        $pw = cred_decrypt($r['password_enc'], $r['password_iv']);
        cred_log_action($id, 'reveal');
        echo json_encode(['ok' => true, 'password' => $pw]);
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
/* Legacy GET reveal disabled */
if (($_GET['ajax'] ?? '') === 'reveal') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Use POST with CSRF']);
    exit;
}

/* ── AJAX: log open/copy actions (open/copy_user/copy_pass) ── */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['ajax'] ?? '') === 'log') {
    header('Content-Type: application/json; charset=utf-8');
    $id     = (int)($_POST['id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    $allowedLog = ['open', 'copy_user', 'copy_pass'];
    if (!in_array($action, $allowedLog, true)) {
        $action = '';
    }
    if ($id && $action !== '') {
        cred_log_action($id, $action);
    }
    echo json_encode(['ok' => true]);
    exit;
}
if (($_GET['ajax'] ?? '') === 'log') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Use POST with CSRF']);
    exit;
}

/* ── POST handlers (admin+ only for mutations) ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_role('admin');
    $action = $_POST['action'] ?? '';
    $me     = (int)($_SESSION['admin_id'] ?? 0);

    if ($action === 'create' || $action === 'update') {
        $id        = (int)($_POST['id'] ?? 0);
        $siteName  = trim($_POST['site_name'] ?? '');
        $siteUrl   = trim($_POST['site_url'] ?? '');
        $siteLogo  = trim($_POST['site_logo'] ?? '');
        $username  = trim($_POST['username'] ?? '');
        $password  = $_POST['password'] ?? '';
        $category  = trim($_POST['category'] ?? 'general');
        $notes     = trim($_POST['notes'] ?? '');

        if (!$siteName || !$siteUrl || !$username) {
            setFlash('error', 'Site name, URL र username आवश्यक।');
        } else {
            try {
                if ($action === 'create') {
                    $enc = cred_encrypt($password);
                    $stmt = $db->prepare(
                        "INSERT INTO office_credentials
                         (site_name, site_url, site_logo, username, password_enc, password_iv,
                          category, notes, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->execute([$siteName, $siteUrl, $siteLogo, $username,
                                    $enc['cipher'], $enc['iv'], $category, $notes, $me]);
                    cred_log_action((int)$db->lastInsertId(), 'create');
                    setFlash('success', 'नयाँ credential सुरक्षित गरियो।');
                } else {
                    if ($password !== '') {
                        $enc = cred_encrypt($password);
                        $stmt = $db->prepare(
                            "UPDATE office_credentials
                             SET site_name=?, site_url=?, site_logo=?, username=?, password_enc=?, password_iv=?,
                                 category=?, notes=?, updated_by=?
                             WHERE id=?"
                        );
                        $stmt->execute([$siteName, $siteUrl, $siteLogo, $username,
                                        $enc['cipher'], $enc['iv'], $category, $notes, $me, $id]);
                    } else {
                        $stmt = $db->prepare(
                            "UPDATE office_credentials
                             SET site_name=?, site_url=?, site_logo=?, username=?,
                                 category=?, notes=?, updated_by=?
                             WHERE id=?"
                        );
                        $stmt->execute([$siteName, $siteUrl, $siteLogo, $username,
                                        $category, $notes, $me, $id]);
                    }
                    cred_log_action($id, 'edit');
                    setFlash('success', 'Credential अद्यावधिक गरियो।');
                }
            } catch (\Throwable $e) {
                setFlash('error', 'Error: ' . e($e->getMessage()));
            }
        }
        header('Location: credentials.php'); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        cred_log_action($id, 'delete');
        $db->prepare("DELETE FROM office_credentials WHERE id = ?")->execute([$id]);
        setFlash('success', 'Credential हटाइयो।');
        header('Location: credentials.php'); exit;
    }
}

/* ── Fetch credentials ── */
$rows = $db->query(
    "SELECT * FROM office_credentials WHERE is_active = 1
     ORDER BY category, sort_order, site_name LIMIT 500"
)->fetchAll(PDO::FETCH_ASSOC);
?>


<div class="admin-content">
    <div class="page-header cred-page-header">
        <div>
            <h1 class="cred-page-title">🔑 अफिस Credentials</h1>
            <p class="cred-page-sub">
                सरकारी/आधिकारिक sites — एकै ठाउँबाट access।
                <small class="cred-warn-note">🔒 सबै password AES-256 encrypted।</small>
            </p>
        </div>
        <?php if (is_admin_or_above()): ?>
        <button class="btn-coop" onclick="openCredModal()">
            <i class="fas fa-plus"></i> नयाँ Credential
        </button>
        <?php endif; ?>
    </div>

    <?php if ($f = getFlash()): ?>
        <div class="alert alert-<?= $f['type']==='error'?'danger':'success' ?>"><?= e($f['message']) ?></div>
    <?php endif; ?>

    <?php if (!$rows): ?>
        <div class="card border-0 shadow-sm">
            <div class="text-center text-muted py-5 px-3 cred-empty-state">
                <i class="fas fa-key fa-2x mb-3 d-block opacity-25" aria-hidden="true"></i>
                <div class="fw-semibold text-secondary cred-empty-title mb-2">कुनै credential थपिएको छैन</div>
                <p class="small mb-0 cred-empty-hint">माथि वा तलको बटनबाट नयाँ credential थप्नुहोस्।</p>
                <?php if (is_admin_or_above()): ?>
                <div class="mt-4">
                    <button type="button" class="btn-coop" onclick="openCredModal()">
                        <i class="fas fa-plus me-1"></i>नयाँ Credential
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
    <div class="cred-grid">
        <?php foreach ($rows as $r): ?>
            <?php
              $logo = $r['site_logo'] ?: '';
              $favicon = $logo ?: ('https://www.google.com/s2/favicons?domain=' . urlencode(parse_url($r['site_url'], PHP_URL_HOST) ?? '') . '&sz=64');
            ?>
            <div class="card-coop cred-card">
                <div class="cred-head">
                    <a href="<?= e($r['site_url']) ?>" target="_blank" rel="noopener"
                       onclick="logAction(<?= (int)$r['id'] ?>, 'open')"
                       title="Site खोल्नुहोस्">
                        <img src="<?= e($favicon) ?>" alt="" class="cred-favicon">
                    </a>
                    <div class="cred-head-meta">
                        <div class="cred-name">
                            <?= e($r['site_name']) ?>
                        </div>
                        <small class="cred-cat"><?= e($r['category']) ?></small>
                    </div>
                </div>

                <div class="cred-row">
                    <code class="cred-code">
                        <?= e($r['username']) ?>
                    </code>
                    <button class="btn btn-sm btn-outline-secondary" title="Username copy"
                            onclick="copyText(<?= (int)$r['id'] ?>, 'user', '<?= e($r['username']) ?>')">
                        <i class="lucide-icon" aria-hidden="true" data-lucide="copy"></i>
                    </button>
                </div>

                <div class="cred-row">
                    <code class="cred-code pw"
                          id="pw-<?= (int)$r['id'] ?>">••••••••</code>
                    <button class="btn btn-sm btn-outline-secondary" title="देखाउनुहोस्"
                            onclick="revealPw(<?= (int)$r['id'] ?>)">
                        <i class="far fa-eye" id="eye-<?= (int)$r['id'] ?>"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-secondary" title="Password copy"
                            onclick="copyPw(<?= (int)$r['id'] ?>)">
                        <i class="lucide-icon" aria-hidden="true" data-lucide="copy"></i>
                    </button>
                </div>

                <a href="<?= e($r['site_url']) ?>" target="_blank" rel="noopener"
                   class="btn-coop cred-open-btn"
                   onclick="logAction(<?= (int)$r['id'] ?>, 'open')">
                    <i class="fas fa-external-link-alt"></i> Site खोल्नुहोस्
                </a>

                <?php if (is_admin_or_above()): ?>
                <div class="cred-actions-top">
                    <button class="btn btn-sm btn-link cred-icon-btn"
                            onclick='editCred(<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                        <i class="fas fa-edit"></i>
                    </button>
                    <form method="post" class="d-inline" onsubmit="return confirm('हटाउने?');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-link cred-icon-btn danger" aria-label="Delete" title="Delete">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Add/Edit Inline Form (popup हटाइयो) -->
<div id="credPanel" class="card-coop d-none cred-panel">
    <div class="cred-panel-head">
        <h3 class="cred-panel-title" id="credModalTitle">नयाँ Credential</h3>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="closeCredModal()">
            <i class="fas fa-times me-1"></i>बन्द गर्नुहोस्
        </button>
    </div>
        <form method="post" id="credForm" class="needs-validation" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create" id="credAction">
            <input type="hidden" name="id" value="" id="credId">
            <div class="cred-form-grid">
                <div>
                    <label class="form-label small fw-semibold mb-1" for="cf_name">Site नाम</label>
                    <input class="field-coop" name="site_name" id="cf_name" placeholder="e.g. NRB Portal" required>
                </div>
                <div>
                    <label class="form-label small fw-semibold mb-1" for="cf_url">Site URL</label>
                    <input class="field-coop" name="site_url"  id="cf_url"  type="url" placeholder="https://..." required>
                </div>
                <div>
                    <label class="form-label small fw-semibold mb-1" for="cf_logo">Logo URL</label>
                    <input class="field-coop" name="site_logo" id="cf_logo" placeholder="खाली राखे favicon auto">
                </div>
                <div>
                    <label class="form-label small fw-semibold mb-1" for="cf_cat">श्रेणी</label>
                    <select class="field-coop" name="category" id="cf_cat">
                        <option value="government">सरकारी (Government)</option>
                        <option value="banking">बैंकिङ</option>
                        <option value="cooperative">सहकारी सम्बन्धी</option>
                        <option value="general">सामान्य</option>
                    </select>
                </div>
                <div>
                    <label class="form-label small fw-semibold mb-1" for="cf_user">Username</label>
                    <input class="field-coop" name="username" id="cf_user" placeholder="Username" required>
                </div>
                <div>
                    <label class="form-label small fw-semibold mb-1" for="cf_pass">Password</label>
                    <input class="field-coop" name="password" id="cf_pass" type="password"
                           placeholder="Edit मा खाली = पुरानै रहन्छ">
                </div>
                <div class="cred-notes-span">
                    <label class="form-label small fw-semibold mb-1" for="cf_notes">Notes</label>
                    <textarea class="field-coop" name="notes" id="cf_notes" rows="2" placeholder="Optional"></textarea>
                </div>
            </div>
            <div class="cred-inline-actions cred-form-actions">
                <button type="button" class="btn-coop btn-outline" onclick="closeCredModal()">रद्द</button>
                <button type="submit" class="btn-coop">सुरक्षित गर्नुहोस्</button>
            </div>
        </form>
</div>

<script>
window.CRED_CSRF = <?php echo json_encode((string)$csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
function openCredModal() {
    document.getElementById('credAction').value = 'create';
    document.getElementById('credId').value = '';
    document.getElementById('credForm').reset();
    document.getElementById('credModalTitle').textContent = 'नयाँ Credential';
    var pw = document.getElementById('cf_pass');
    pw.required = true;
    pw.placeholder = 'Password (आवश्यक)';
    var panel = document.getElementById('credPanel');
    panel.classList.remove('d-none');
    panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
    setTimeout(function(){ document.getElementById('cf_name').focus(); }, 80);
}
function closeCredModal() {
    document.getElementById('credPanel').classList.add('d-none');
}

function editCred(r) {
    document.getElementById('credAction').value = 'update';
    document.getElementById('credId').value = r.id;
    var pw = document.getElementById('cf_pass');
    pw.required = false;
    pw.placeholder = 'खाली राखे पुरानै password रहन्छ';
    document.getElementById('cf_name').value  = r.site_name || '';
    document.getElementById('cf_url').value   = r.site_url || '';
    document.getElementById('cf_logo').value  = r.site_logo || '';
    document.getElementById('cf_cat').value   = r.category || 'general';
    document.getElementById('cf_user').value  = r.username || '';
    document.getElementById('cf_pass').value  = '';
    document.getElementById('cf_notes').value = r.notes || '';
    document.getElementById('credModalTitle').textContent = 'Credential सम्पादन';
    var panel = document.getElementById('credPanel');
    panel.classList.remove('d-none');
    panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

async function revealPw(id) {
    const pwEl  = document.getElementById('pw-' + id);
    const eyeEl = document.getElementById('eye-' + id);
    if (pwEl.dataset.shown === '1') {
        pwEl.textContent = '••••••••';
        pwEl.dataset.shown = '0';
        eyeEl.className = 'far fa-eye';
        return;
    }
    const res = await fetch('credentials.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'ajax=reveal&id=' + encodeURIComponent(id) + '&csrf_token=' + encodeURIComponent(window.CRED_CSRF || '')
    });
    const j = await res.json();
    if (j.ok) {
        pwEl.textContent = j.password;
        pwEl.dataset.shown = '1';
        pwEl.dataset.pw = j.password;
        eyeEl.className = 'far fa-eye-slash';
    } else { alert('Error: ' + j.error); }
}

async function copyText(id, type, txt) {
    await navigator.clipboard.writeText(txt);
    logAction(id, 'copy_' + type);
    showToast(type === 'user' ? 'Username copy भयो ✓' : 'Copy भयो ✓');
}
async function copyPw(id) {
    const res = await fetch('credentials.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'ajax=reveal&id=' + encodeURIComponent(id) + '&csrf_token=' + encodeURIComponent(window.CRED_CSRF || '')
    });
    const j = await res.json();
    if (j.ok) {
        await navigator.clipboard.writeText(j.password);
        logAction(id, 'copy_pass');
        showToast('Password copy भयो ✓');
    }
}

function logAction(id, action) {
    fetch('credentials.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'ajax=log&id=' + encodeURIComponent(id)
            + '&action=' + encodeURIComponent(action)
            + '&csrf_token=' + encodeURIComponent(window.CRED_CSRF || '')
    }).catch(() => {});
}

function showToast(msg) {
    const t = document.createElement('div');
    t.className = 'cred-toast';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 2000);
}
</script>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>
