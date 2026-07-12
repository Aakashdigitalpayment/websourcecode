<?php
/**
 * 👥 कर्मचारी व्यवस्थापन (Staff Management)
 * ─────────────────────────────────────────────────────────────
 * Superadmin र Admin ले staff/admin user बनाउन/edit/disable गर्न मिल्ने।
 * Staff ले यो page हेर्न पाउँदैन।
 */
require_once __DIR__ . '/includes/admin-header.php';
require_once __DIR__ . '/../includes/superadmin-config.php';
require_once __DIR__ . '/../includes/auth-roles.php';
require_once __DIR__ . '/../includes/election-tables.php';
ensureDesignationsTable(getDB());
try { getDB()->exec("ALTER TABLE admin_users ADD COLUMN designation VARCHAR(160) NULL DEFAULT NULL"); } catch (\Throwable $e) {}
$__staffDesigs = fetchDesignations(getDB(), ['staff']);
require_role('admin');  /* staff blocked */

$db    = getDB();
$flash = '';
$me    = (int)($_SESSION['admin_id'] ?? 0);

/* ── HRM employees pool (for "Link from Employee" option) ── */
$__hrmEmployees = [];
try {
    if ($db->query("SHOW TABLES LIKE 'hrm_employees'")->fetchColumn()) {
        $__hrmEmployees = $db->query(
            "SELECT id, employee_code, full_name_np, full_name_en, email, mobile, designation, admin_user_id, photo
             FROM hrm_employees
             WHERE status IN ('active','probation','on_leave')
             ORDER BY full_name_np"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (\Throwable $e) {}

/* ── Handle POST ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* Create staff/admin */
    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? 'staff';
        $designation = trim($_POST['designation'] ?? '');

        /* Only superadmin can create another admin/superadmin */
        if (in_array($role, ['admin', 'superadmin']) && !is_superadmin()) {
            setFlash('error', 'सुपर एडमिनले मात्र admin/superadmin बनाउन सक्छन्।');
        } elseif (file_managed_superadmin_username() !== null && $username === file_managed_superadmin_username()) {
            setFlash('error', 'यो username फाइल-सुपरएडमिनको हो — `includes/superadmin-config.local.php` मा मात्र।');
        } elseif (!$username || !$password || strlen($password) < 6) {
            setFlash('error', 'Username र password (कम्तीमा 6 अक्षर) आवश्यक।');
        } else {
            try {
                $stmt = $db->prepare(
                    "INSERT INTO admin_users (username, password, full_name, email, role, designation, is_active, status, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, 1, 'active', ?)"
                );
                $stmt->execute([
                    $username,
                    password_hash($password, PASSWORD_DEFAULT),
                    $name, $email, $role, ($designation ?: null), $me,
                ]);
                $newId = (int)$db->lastInsertId();
                /* Link back to HRM employee if chosen */
                $linkEmp = (int)($_POST['link_employee_id'] ?? 0);
                if ($linkEmp > 0) {
                    try {
                        $db->prepare("UPDATE hrm_employees SET admin_user_id=? WHERE id=? AND (admin_user_id IS NULL OR admin_user_id=0)")
                           ->execute([$newId, $linkEmp]);
                    } catch (\Throwable $e) {}
                }
                setFlash('success', 'नयाँ '. e($role) .' सफलतापूर्वक थपियो।' . ($linkEmp ? ' (कर्मचारीसँग लिंक भयो)' : ''));
            } catch (\PDOException $e) {
                setFlash('error', 'Username पहिले नै exist गर्छ वा error: ' . e($e->getMessage()));
            }
        }
        header('Location: staff.php'); exit;
    }

    /* Toggle active */
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === $me) {
            setFlash('error', 'आफैँलाई disable गर्न मिल्दैन।');
        } elseif (admin_user_id_is_file_managed_superadmin($db, $id)) {
            setFlash('error', 'फाइल-सुपरएडमिन खाता यहाँबाट toggle गर्न मिल्दैन।');
        } else {
            $db->prepare("UPDATE admin_users SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
            setFlash('success', 'Status अद्यावधिक भयो।');
        }
        header('Location: staff.php'); exit;
    }

    /* Reset password */
    if ($action === 'resetpw') {
        $id     = (int)($_POST['id'] ?? 0);
        $newPw  = $_POST['new_password'] ?? '';
        if (admin_user_id_is_file_managed_superadmin($db, $id)) {
            setFlash('error', 'फाइल-सुपरएडमिनको पासवर्ड फाइलबाट मात्र बदल्नुहोस्।');
        } elseif (strlen($newPw) < 6) {
            setFlash('error', 'Password कम्तीमा 6 अक्षर हुनुपर्छ।');
        } else {
            $db->prepare("UPDATE admin_users SET password = ?, last_password_change = NOW() WHERE id = ?")
               ->execute([password_hash($newPw, PASSWORD_DEFAULT), $id]);
            setFlash('success', 'Password reset भयो।');
        }
        header('Location: staff.php'); exit;
    }

    /* Delete (superadmin only) */
    if ($action === 'delete' && is_superadmin()) {
        $id = (int)($_POST['id'] ?? 0);
        if (admin_user_id_is_file_managed_superadmin($db, $id)) {
            setFlash('error', 'फाइल-सुपरएडमिन मेटाउन मिल्दैन।');
        } elseif ($id !== $me) {
            $db->prepare("DELETE FROM admin_users WHERE id = ?")->execute([$id]);
            setFlash('success', 'User हटाइयो।');
        }
        header('Location: staff.php'); exit;
    }
}

/* ── Fetch users ── */
$users = $db->query(
    "SELECT id, username, full_name, email, role, designation, is_active, last_login, created_at
     FROM admin_users ORDER BY
        FIELD(role,'superadmin','admin','staff'), id ASC"
)->fetchAll(PDO::FETCH_ASSOC);
$users = filter_out_file_managed_superadmin_rows($users);
?>

<div class="admin-content">
    <div class="page-header stf-page-head">
        <div>
            <h1 class="stf-title">👥 कर्मचारी व्यवस्थापन</h1>
            <p class="stf-subtitle">Admin र Staff users — role hierarchy</p>
        </div>
        <button class="btn-coop" onclick="document.getElementById('addStaffModal').style.display='flex'">
            <i class="fas fa-user-plus"></i> नयाँ User थप्नुहोस्
        </button>
    </div>

    <div class="alert alert-light border small py-2 mb-3">
        <i class="fas fa-info-circle text-warning me-1"></i>
        <strong>सुझाव:</strong> Admin login users को मुख्य पृष्ठ
        <a href="manage-admins.php"><strong>Manage Admins</strong></a> हो।
        यो <code>staff.php</code> advanced/legacy विकल्प हो (HRM link सहित) — नयाँ कामका लागि Manage Admins प्रयोग गर्नुहोस्।
    </div>

    <?php echo adminHelpTip('यो पृष्ठबाट Admin र Staff Users व्यवस्थापन गर्न सकिन्छ।', ['नयाँ User थप्न: माथिको "नयाँ User थप्नुहोस्" बटन थिच्नुहोस्।', 'Role: Admin = सबै access; Staff = सीमित access।', 'Password बदल्न: सम्बन्धित user को Edit icon थिच्नुहोस्।']); ?>

    <?php if ($f = getFlash()): ?>
        <div class="alert alert-<?= $f['type']==='error'?'danger':'success' ?>"><?= e($f['message']) ?></div>
    <?php endif; ?>

    <div class="card-coop stf-card-table-wrap admin-table-card">
        <table class="table table-hover mb-0 stf-table table-responsive-stack">
            <thead class="stf-soft-head">
                <tr>
                    <th>नाम</th><th>Username</th><th>Email</th>
                    <th>पद</th><th>भूमिका</th><th>Status</th><th>Last Login</th><th class="stf-align-right">कार्य</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u): ?>
                <?php
                  $badge = $u['role']==='superadmin' ? 'danger'
                         : ($u['role']==='admin' ? 'primary' : 'secondary');
                ?>
                <tr>
                    <td><strong><?= e($u['full_name'] ?: $u['username']) ?></strong></td>
                    <td><code><?= e($u['username']) ?></code></td>
                    <td><?= e($u['email']) ?></td>
                    <td><small><?= e($u['designation'] ?? '') ?></small></td>
                    <td><span class="badge bg-<?= $badge ?>"><?= e($u['role']) ?></span></td>
                    <td>
                        <?php if ($u['is_active']): ?>
                            <span class="stf-status-dot stf-status-active">● सक्रिय</span>
                        <?php else: ?>
                            <span class="stf-status-dot stf-status-inactive">● निष्क्रिय</span>
                        <?php endif; ?>
                    </td>
                    <td><small><?= e($u['last_login'] ?? 'कहिल्यै लग-इन भएको छैन') ?></small></td>
                    <td class="stf-align-right">
                        <?php if ($u['id'] !== $me): ?>
                        <form method="post" class="stf-inline-form">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                            <button class="btn btn-sm btn-outline-secondary"
                                    onclick="return confirm('Status बदल्ने?')" aria-label="Toggle status" title="Toggle status">
                                <i class="fas fa-power-off"></i>
                            </button>
                        </form>
                        <button class="btn btn-sm btn-outline-warning"
                                onclick="resetPw(<?= (int)$u['id'] ?>, '<?= e($u['username']) ?>')">
                            <i class="fas fa-key"></i>
                        </button>
                        <?php if (is_superadmin()): ?>
                            <form method="post" class="stf-inline-form"
                                  onsubmit="return confirm('पक्का delete गर्ने?');">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" aria-label="Delete" title="Delete">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        <?php endif; ?>
                        <?php else: ?>
                            <small class="stf-self-note">(आफैँ)</small>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Permission Matrix -->
    <div class="card-coop stf-mt24">
        <h3 class="stf-section-title">🔐 भूमिका अनुसार अनुमतिहरू</h3>
        <table class="table table-hover table-sm stf-table-no-margin">
            <thead>
                <tr><th>क्षमता</th><th>Superadmin</th><th>Admin</th><th>Staff</th></tr>
            </thead>
            <tbody>
                <tr><td>सदस्य रिक्वेस्ट हेर्ने/जवाफ दिने</td><td>✅</td><td>✅</td><td>✅</td></tr>
                <tr><td>Office Credentials हेर्ने/copy</td><td>✅</td><td>✅</td><td>✅</td></tr>
                <tr><td>सामग्री व्यवस्थापन (news, notices, etc.)</td><td>✅</td><td>✅</td><td>❌</td></tr>
                <tr><td>Staff थप्ने/हटाउने</td><td>✅</td><td>✅</td><td>❌</td></tr>
                <tr><td>Admin/Superadmin बनाउने</td><td>✅</td><td>❌</td><td>❌</td></tr>
                <tr><td>Site Settings, Backup, DB Setup</td><td>✅</td><td>❌</td><td>❌</td></tr>
                <tr><td>Credentials थप्ने/edit/delete</td><td>✅</td><td>✅</td><td>❌</td></tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Add User Modal -->
<div id="addStaffModal" class="stf-modal-backdrop">
    <div class="card-coop stf-modal-card stf-modal-card-lg">
        <h3 class="stf-section-title">नयाँ User थप्नुहोस्</h3>
        <form method="post" class="needs-validation" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="link_employee_id" id="f_link_emp_id" value="">
            <div class="stf-grid-gap">
                <?php if (!empty($__hrmEmployees)): ?>
                <select class="field-coop" id="f_link_emp_select" onchange="prefillFromEmployee(this)">
                    <option value="">— कर्मचारीबाट छान्नुहोस् (वैकल्पिक) —</option>
                    <?php foreach ($__hrmEmployees as $__e): ?>
                        <?php $linked = (int)($__e['admin_user_id'] ?? 0) > 0; ?>
                        <option value="<?= (int)$__e['id'] ?>"
                                data-name="<?= e($__e['full_name_np']) ?>"
                                data-email="<?= e($__e['email'] ?? '') ?>"
                                data-desig="<?= e($__e['designation'] ?? '') ?>"
                                data-code="<?= e($__e['employee_code']) ?>"
                                <?= $linked ? 'disabled' : '' ?>>
                            <?= e($__e['employee_code']) ?> — <?= e($__e['full_name_np']) ?><?= $linked ? ' (पहिले नै लिंक छ)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="stf-muted-note">कर्मचारी छान्दा नाम/Email/पद/Username स्वतः भरिन्छ।</small>
                <?php endif; ?>
                <input class="field-coop" name="name"     placeholder="पूरा नाम" required>
                <input class="field-coop" name="username" placeholder="Username" required>
                <input class="field-coop" name="email"    type="email" placeholder="Email">
                <input class="field-coop" name="password" type="password" placeholder="Password (कम्तीमा 6)" required>
                <select class="field-coop" name="designation">
                    <option value="">— पद छान्नुहोस् (वैकल्पिक) —</option>
                    <?php foreach ($__staffDesigs as $__d): ?>
                        <option value="<?= e($__d['title_np']) ?>"><?= e($__d['title_np']) ?><?php if($__d['title_en']): ?> — <?= e($__d['title_en']) ?><?php endif; ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="stf-muted-note">पद यहाँ नभए <a href="designations.php" target="_blank">पद मास्टर</a> मा थप्नुहोस्।</small>
                <select class="field-coop" name="role">
                    <option value="staff">Staff (सीमित अधिकार)</option>
                    <?php if (is_superadmin()): ?>
                        <option value="admin">Admin</option>
                        <option value="superadmin">Superadmin</option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="stf-actions-row stf-actions-row-lg">
                <button type="button" class="btn-coop btn-outline"
                        onclick="document.getElementById('addStaffModal').style.display='none'">रद्द</button>
                <button type="submit" class="btn-coop">थप्नुहोस्</button>
            </div>
        </form>
    </div>
</div>

<!-- Reset Password Modal -->
<div id="resetPwModal" class="stf-modal-backdrop">
    <div class="card-coop stf-modal-card stf-modal-card-sm">
        <h3 class="stf-section-title">Password Reset</h3>
        <p>User: <strong id="resetPwUser"></strong></p>
        <form method="post" class="needs-validation" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="action" value="resetpw">
            <input type="hidden" name="id" id="resetPwId">
            <input class="field-coop" type="password" name="new_password"
                   placeholder="नयाँ password (कम्तीमा 6 अक्षर)" required minlength="6">
            <div class="stf-actions-row stf-actions-row-sm">
                <button type="button" class="btn-coop btn-outline"
                        onclick="document.getElementById('resetPwModal').style.display='none'">रद्द</button>
                <button type="submit" class="btn-coop">Reset</button>
            </div>
        </form>
    </div>
</div>

<script>
function resetPw(id, user) {
    document.getElementById('resetPwId').value = id;
    document.getElementById('resetPwUser').textContent = user;
    document.getElementById('resetPwModal').style.display = 'flex';
}
function prefillFromEmployee(sel){
    const opt = sel.options[sel.selectedIndex];
    const form = sel.closest('form');
    if (!opt || !form) return;
    document.getElementById('f_link_emp_id').value = opt.value || '';
    if (!opt.value) return;
    const set = (n, v) => { const el = form.querySelector('[name="'+n+'"]'); if (el && !el.value) el.value = v || ''; };
    set('name',  opt.dataset.name);
    set('email', opt.dataset.email);
    /* username suggestion = employee_code lowercased */
    const u = form.querySelector('[name="username"]');
    if (u && !u.value && opt.dataset.code) u.value = opt.dataset.code.toLowerCase();
    /* designation: try to match an option */
    const d = form.querySelector('[name="designation"]');
    if (d && opt.dataset.desig) {
        for (const o of d.options) { if (o.value === opt.dataset.desig) { d.value = o.value; break; } }
    }
}
</script>

<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>
