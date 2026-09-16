<?php
/**
 * Admin User Management — manage-admins.php
 * ==========================================
 * Superadmin मात्र यो पृष्ठ (URL थाहा भएका admin ले bypass नगर्न)।
 * Credential `superadmin-config.local.php`; फाइल-सुपरएडमिन सूचीमा लुकेको।
 */
require_once __DIR__ . '/includes/admin-page-boot.php';
$pageTitle  = 'Admin व्यवस्थापन';
$currentPage = 'manage-admins';
require_once 'includes/admin-header.php';
require_once __DIR__ . '/../includes/superadmin-config.php';
require_once 'includes/admin-ui.php';

if (empty($_SESSION['is_superadmin'])) {
    setFlash('error', 'Admin व्यवस्थापन केवल Superadmin ले खोल्न सक्छ।');
    redirect('dashboard.php');
    exit;
}

$db = getDB();

/* CSRF सुरक्षा: POST अनुरोध प्रमाणित गर्नुहोस् */
checkCSRF();

$myId         = $_SESSION['admin_id']     ?? null;
$isSuperAdmin = !empty($_SESSION['is_superadmin']);

/* ══════════════════════════════════════════════════
   POST ACTIONS
══════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ── १. नयाँ Admin बनाउनुहोस् ── */
    if ($action === 'create_admin') {
        $username    = trim($_POST['username']        ?? '');
        $fullName    = trim($_POST['full_name']       ?? '');
        $email       = trim($_POST['email']           ?? '');
        $role        = $_POST['role']                 ?? 'admin';
        if (function_exists('admin_canonical_db_role')) {
            $role = admin_canonical_db_role($role);
        }
        $newPass     = $_POST['new_password']         ?? '';
        $confirmPass = $_POST['confirm_password']     ?? '';
        $isActive    = isset($_POST['is_active']) ? 1 : 0;

        if (empty($username) || empty($fullName) || empty($newPass)) {
            setFlash('error', 'युजरनेम, पूरा नाम र पासवर्ड अनिवार्य छ।');
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
            setFlash('error', 'युजरनेम ३–३० अक्षर, a-z/0-9/_ मात्र हुन सक्छ।');
        } elseif ($newPass !== $confirmPass) {
            setFlash('error', 'पासवर्ड र पुष्टि पासवर्ड मेल खाएन।');
        } elseif (strlen($newPass) < 8) {
            setFlash('error', 'पासवर्ड कम्तिमा ८ अक्षर हुनुपर्छ।');
        } elseif (!in_array($role, ['admin', 'editor'], true)) {
            setFlash('error', 'गलत role।');
        } elseif (file_managed_superadmin_username() !== null && $username === file_managed_superadmin_username()) {
            setFlash('error', 'यो username फाइल-सुपरएडमिनको हो — `includes/superadmin-config.local.php` मा मात्र।');
        } else {
            try {
                $chk = $db->prepare("SELECT id FROM admin_users WHERE username = ?");
                $chk->execute([$username]);
                if ($chk->fetch()) {
                    setFlash('error', '"' . htmlspecialchars($username) . '" username पहिले नै छ। अर्को username छान्नुहोस्।');
                } else {
                    $hashed = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
                    try {
                        $db->prepare("INSERT INTO admin_users (username, password, full_name, email, role, is_active, must_change_password, created_at)
                                      VALUES (?, ?, ?, ?, ?, ?, 1, NOW())")
                           ->execute([$username, $hashed, $fullName, $email ?: null, $role, $isActive]);
                    } catch (Throwable $e) {
                        $db->prepare("INSERT INTO admin_users (username, password, full_name, email, role, is_active, created_at)
                                      VALUES (?, ?, ?, ?, ?, ?, NOW())")
                           ->execute([$username, $hashed, $fullName, $email ?: null, $role, $isActive]);
                    }
                    setFlash('success', '"' . htmlspecialchars($fullName) . '" admin user सफलतापूर्वक बनाइयो।');
                }
            } catch (Exception $e) {
                error_log('[manage-admins] ' . $e->getMessage());
                setFlash('error', 'कार्य असफल भयो।');
            }
        }
        redirect('manage-admins.php');
    }

    /* ── २. Password Reset ── */
    if ($action === 'reset_password') {
        $targetId    = (int)($_POST['target_id']      ?? 0);
        $newPass     = $_POST['new_password']          ?? '';
        $confirmPass = $_POST['confirm_password']      ?? '';

        if (!$isSuperAdmin && $targetId === (int)$myId) {
            setFlash('error', 'आफ्नो पासवर्ड यहाँबाट होइन — change-password.php बाट गर्नुहोस्।');
        } elseif ($newPass !== $confirmPass) {
            setFlash('error', 'पासवर्ड र पुष्टि मेल खाएन।');
        } elseif (strlen($newPass) < 8) {
            setFlash('error', 'पासवर्ड कम्तिमा ८ अक्षर हुनुपर्छ।');
        } else {
            try {
                $chk = $db->prepare("SELECT full_name, username FROM admin_users WHERE id = ?");
                $chk->execute([$targetId]);
                $target = $chk->fetch();
                if (!$target) {
                    setFlash('error', 'Admin फेला परेन।');
                } elseif (admin_row_is_file_managed_superadmin($target)) {
                    setFlash('error', 'फाइल-सुपरएडमिनको पासवर्ड `includes/superadmin-config.local.php` (cPanel) मा मात्र बदल्नुहोस्।');
                } else {
                    $hashed = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
                    $forceNext = ($targetId !== (int) $myId) ? 1 : 0;
                    try {
                        $db->prepare('UPDATE admin_users SET password = ?, must_change_password = ? WHERE id = ?')
                           ->execute([$hashed, $forceNext, $targetId]);
                    } catch (Throwable $e) {
                        $db->prepare('UPDATE admin_users SET password = ? WHERE id = ?')
                           ->execute([$hashed, $targetId]);
                    }
                    $msg = '"' . htmlspecialchars($target['full_name']) . '" को पासवर्ड अपडेट भयो।';
                    if ($forceNext === 1) {
                        $msg .= ' उनीहरूले अर्को login मा आफ्नो पासवर्ड बदल्नुपर्छ।';
                    }
                    setFlash('success', $msg);
                }
            } catch (Exception $e) {
                error_log('[manage-admins] ' . $e->getMessage());
                setFlash('error', 'कार्य असफल भयो।');
            }
        }
        redirect('manage-admins.php');
    }

    /* ── ३. Toggle Active/Inactive ── */
    if ($action === 'toggle_active') {
        $targetId  = (int)($_POST['target_id'] ?? 0);
        $newStatus = (int)($_POST['new_status'] ?? 0);
        if (!$isSuperAdmin && $targetId === (int)$myId) {
            setFlash('error', 'आफ्नो account आफैँ disable गर्न मिल्दैन।');
        } elseif (admin_user_id_is_file_managed_superadmin($db, $targetId)) {
            setFlash('error', 'फाइल-सुपरएडमिन खाता यहाँबाट disable/enable गर्न मिल्दैन।');
        } else {
            try {
                $db->prepare("UPDATE admin_users SET is_active = ? WHERE id = ?")
                   ->execute([$newStatus, $targetId]);
                setFlash('success', 'Status अपडेट भयो।');
            } catch (Exception $e) {
                error_log('[manage-admins] ' . $e->getMessage());
                setFlash('error', 'कार्य असफल भयो।');
            }
        }
        redirect('manage-admins.php');
    }

    /* ── ४. Delete Admin ── */
    if ($action === 'delete_admin') {
        $targetId = (int)($_POST['target_id'] ?? 0);
        if (!$isSuperAdmin && $targetId === (int)$myId) {
            setFlash('error', 'आफ्नो account आफैँ मेटाउन मिल्दैन।');
        } elseif (admin_user_id_is_file_managed_superadmin($db, $targetId)) {
            setFlash('error', 'फाइल-सुपरएडमिन row DB मा रहन्छ तर यहाँबाट मेटाउन मिल्दैन।');
        } else {
            try {
                $db->prepare("DELETE FROM admin_users WHERE id = ?")
                   ->execute([$targetId]);
                setFlash('success', 'Admin user मेटाइयो।');
            } catch (Exception $e) {
                error_log('[manage-admins] ' . $e->getMessage());
                setFlash('error', 'कार्य असफल भयो।');
            }
        }
        redirect('manage-admins.php');
    }

    /* ── ५. Alias-only role normalize (superadmin → super_admin; privilege unchanged) ── */
    if ($action === 'normalize_role_aliases') {
        if (!$isSuperAdmin) {
            setFlash('error', 'यो कार्य केवल Superadmin ले गर्न सक्छ।');
        } elseif (!function_exists('coop_normalize_admin_role_aliases')) {
            setFlash('error', 'Role normalize helper उपलब्ध छैन।');
        } else {
            try {
                $norm = coop_normalize_admin_role_aliases($db);
                if (!empty($norm['error'])) {
                    setFlash('error', 'Role normalize असफल: ' . $norm['error']);
                } elseif (!empty($norm['skipped'])) {
                    setFlash('info', 'Role ENUM मा दुवै alias छैनन् वा normalize आवश्यक छैन।');
                } else {
                    $n = (int) ($norm['updated'] ?? 0);
                    setFlash(
                        'success',
                        $n > 0
                            ? "{$n} admin role row(s) normalized (superadmin → super_admin). Privilege unchanged."
                            : 'सबै role spelling पहिले नै canonical छन्।'
                    );
                }
            } catch (Throwable $e) {
                error_log('[manage-admins] role normalize: ' . $e->getMessage());
                setFlash('error', 'Role normalize असफल भयो।');
            }
        }
        redirect('manage-admins.php');
    }

    /* ── ६. Reset 2FA (device change / QR mismatch → re-scan on next login) ── */
    if ($action === 'reset_2fa') {
        $targetId = (int) ($_POST['target_id'] ?? 0);
        if ($targetId < 1) {
            setFlash('error', 'अमान्य admin ID।');
        } elseif (!$isSuperAdmin) {
            setFlash('error', '2FA reset केवल Superadmin ले गर्न सक्छ।');
        } else {
            try {
                $cols = [];
                if (function_exists('safeColumnExists')) {
                    foreach (['twofa_enabled', 'twofa_secret', 'twofa_backup_codes', 'twofa_enabled_at'] as $c) {
                        if (safeColumnExists('admin_users', $c)) {
                            $cols[] = $c;
                        }
                    }
                } else {
                    $cols = ['twofa_enabled', 'twofa_secret', 'twofa_backup_codes', 'twofa_enabled_at'];
                }
                if (!$cols) {
                    setFlash('error', '2FA columns उपलब्ध छैनन्।');
                } else {
                    $sets = [];
                    $params = [];
                    foreach ($cols as $c) {
                        if ($c === 'twofa_enabled') {
                            $sets[] = 'twofa_enabled = 0';
                        } else {
                            $sets[] = $c . ' = NULL';
                        }
                    }
                    $params[] = $targetId;
                    $db->prepare('UPDATE admin_users SET ' . implode(', ', $sets) . ' WHERE id = ?')
                       ->execute($params);
                    if (function_exists('logActivity')) {
                        logActivity('admin_2fa_reset', 'admin_users', $targetId, '2FA QR reset by superadmin');
                    }
                    setFlash('success', '2FA reset भयो। अर्को login मा नयाँ QR scan गर्नुपर्छ।');
                }
            } catch (Throwable $e) {
                error_log('[manage-admins] 2fa reset: ' . $e->getMessage());
                setFlash('error', '2FA reset असफल भयो।');
            }
        }
        redirect('manage-admins.php');
    }
}

/* ── Admin list (फाइल-सुपरएडमिन सूचीबाट लुकाउने) ── */
try {
    $admins = $db->query("SELECT * FROM admin_users ORDER BY id ASC LIMIT 100")->fetchAll() ?: [];
    $admins = filter_out_file_managed_superadmin_rows($admins);
} catch (Exception $e) {
    $admins = [];
}

$legacySuperadminRows = 0;
try {
    $legacySuperadminRows = (int) $db->query(
        "SELECT COUNT(*) FROM admin_users WHERE role = 'superadmin'"
    )->fetchColumn();
} catch (Throwable $e) {
    $legacySuperadminRows = 0;
}

/* Tab — URL मा ?tab=add भए add tab active */
$tabRaw = $_GET['tab'] ?? 'list';
$activeTab = in_array($tabRaw, ['list', 'add'], true) ? $tabRaw : 'list';
?>

<!-- ════════════════════════════════════════════════
     PAGE HEADER
════════════════════════════════════════════════ -->
<?php echo adminPageHeader('Admin व्यवस्थापन','fa-user-shield','Admin accounts — थप्नुहोस्, पासवर्ड रिसेट, सक्रिय/निष्क्रिय।',
      '<span class="badge admin-stat-badge bg-success-subtle text-success border border-success border-opacity-25 me-2"><i class="lucide-icon me-1" data-lucide="users" aria-hidden="true"></i>जम्मा: ' . count($admins) . ' Admins</span>'
    . '<button type="button" class="btn btn-primary btn-sm" id="btnAddAdmin"><i class="lucide-icon me-1" data-lucide="plus" aria-hidden="true"></i>नयाँ Admin</button>'
  ); ?>

<!-- Flash Messages -->
<?php $flash = getFlash(); ?>
<?php if (!empty($flash)) { echo adminAlert($flash['type'] === 'success' ? 'success' : 'danger', $flash['message']); } ?>

<?php if ($isSuperAdmin && file_managed_superadmin_username() !== null): ?>
<div class="d-flex align-items-center gap-2 mb-2">
    <button type="button" class="btn btn-sm btn-outline-secondary px-2 py-1" data-bs-toggle="collapse" data-bs-target="#superadminFileHelp" aria-expanded="false" aria-controls="superadminFileHelp" title="फाइल-सुपरएडमिन के हो?">
        <i class="lucide-icon me-1" data-lucide="circle-help" aria-hidden="true"></i>फाइल-सुपरएडमिन के हो?
    </button>
</div>
<div class="collapse mb-3" id="superadminFileHelp">
    <div class="alert alert-info border-info border-start border-4 small mb-0" role="note">
        <strong><i class="lucide-icon me-1" data-lucide="shield-user" aria-hidden="true"></i>फाइल-सुपरएडमिन:</strong>
        User/password <code class="user-select-all">includes/superadmin-config.local.php</code> मा हुन्छ।
        <strong>यो सूचीमा देखिँदैन</strong> (DB मा भए पनि) — तल admin/editor मात्र। पासवर्ड बदल्न cPanel मा फाइल edit + login।
    </div>
</div>
<?php endif; ?>

<?php if ($isSuperAdmin): ?>
<div class="alert alert-light border small mb-3" role="note">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <div>
            <strong><i class="lucide-icon me-1" data-lucide="shuffle" aria-hidden="true"></i>Role spelling:</strong>
            Dual-read accepts <code>superadmin</code> र <code>super_admin</code>.
            Canonical write = <code>super_admin</code>.
            Privilege mass UPDATE हुँदैन — alias spelling मात्र।
            <?php if ($legacySuperadminRows > 0): ?>
                <span class="badge bg-warning text-dark ms-1"><?php echo (int) $legacySuperadminRows; ?> legacy spelling</span>
            <?php else: ?>
                <span class="badge bg-success-subtle text-success ms-1">canonical</span>
            <?php endif; ?>
        </div>
        <?php if ($legacySuperadminRows > 0): ?>
        <form method="post" class="m-0" onsubmit="return confirm('Normalize legacy superadmin → super_admin spelling only? Privilege level will not change.');">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="normalize_role_aliases">
            <button type="submit" class="btn btn-sm btn-outline-primary">
                <i class="lucide-icon me-1" data-lucide="wand-sparkles" aria-hidden="true"></i>Normalize aliases
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ════════════════════════════════════════════════
     TABS
════════════════════════════════════════════════ -->
<ul class="nav nav-tabs admin-nav-tabs mb-3" id="adminTabs">
    <li class="nav-item">
        <button type="button" class="nav-link <?php echo $activeTab==='list'?'active':''; ?>"
                data-bs-toggle="tab" data-bs-target="#tab-list" id="tabListBtn">
            <i class="lucide-icon me-2" data-lucide="list" aria-hidden="true"></i>Admin सूची
            <span class="badge bg-success ms-1"><?php echo count($admins); ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button type="button" class="nav-link <?php echo $activeTab==='add'?'active':''; ?>"
                data-bs-toggle="tab" data-bs-target="#tab-add" id="tabAddBtn">
            <i class="lucide-icon me-2" data-lucide="user-plus" aria-hidden="true"></i>नयाँ Admin बनाउनुहोस्
        </button>
    </li>
</ul>

<div class="tab-content">

    <!-- ════ TAB 1: ADMIN LIST ════ -->
    <div class="tab-pane fade <?php echo $activeTab==='list'?'show active':''; ?>" id="tab-list">
        <div class="card border-0 shadow-sm admin-table-card">
            <div class="card-body p-0">
                <div class="table-responsive table-responsive-stack">
                    <table class="table table-hover align-middle mb-0" id="adminTable">
                        <thead>
                            <tr class="ma-table-head-row">
                                <th class="ps-3">#</th>
                                <th><i class="lucide-icon me-1" data-lucide="user" aria-hidden="true"></i>पूरा नाम</th>
                                <th><i class="lucide-icon me-1" data-lucide="at" aria-hidden="true"></i>युजरनेम</th>
                                <th><i class="lucide-icon me-1" data-lucide="mail" aria-hidden="true"></i>इमेल</th>
                                <th><i class="lucide-icon me-1" data-lucide="shield-user" aria-hidden="true"></i>Role</th>
                                <th><i class="lucide-icon me-1" data-lucide="circle" aria-hidden="true"></i>अवस्था</th>
                                <th><i class="lucide-icon me-1" data-lucide="clock" aria-hidden="true"></i>अन्तिम Login</th>
                                <th class="text-center pe-3">कार्यहरू</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($admins)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5">
                                    <div class="ma-empty-icon mb-2">
                                        <i class="lucide-icon" data-lucide="users" aria-hidden="true"></i>
                                    </div>
                                    <div class="text-muted fw-semibold">कुनै admin user DB मा छैन।</div>
                                    <div class="text-muted small mt-1">
                                        माथि "नयाँ Admin बनाउनुहोस्" tab बाट पहिलो admin बनाउनुहोस्।
                                    </div>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php foreach ($admins as $adm):
                                $isMe = (!$isSuperAdmin && (int)$adm['id'] === (int)$myId);
                            ?>
                            <tr class="<?php echo !$adm['is_active'] ? 'table-secondary' : ''; ?>">

                                <td class="ps-3 text-muted small" data-label="#">#<?php echo (int)$adm['id']; ?></td>

                                <!-- नाम -->
                                <td data-label="पूरा नाम">
                                    <div class="d-flex align-items-center gap-2">
                                        <!-- Avatar circle -->
                                        <div class="ma-avatar-chip">
                                            <?php echo mb_strtoupper(mb_substr($adm['full_name'],0,1,'UTF-8'),'UTF-8'); ?>
                                        </div>
                                        <div>
                                            <div class="fw-semibold ma-tight-line">
                                                <?php echo htmlspecialchars($adm['full_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                <?php if ($isMe): ?>
                                                <span class="badge bg-primary ms-1 ma-you-badge">तपाईं</span>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted ma-id-text">
                                                ID: <?php echo (int)$adm['id']; ?>
                                            </small>
                                        </div>
                                    </div>
                                </td>

                                <!-- username -->
                                <td data-label="युजरनेम">
                                    <code class="small px-2 py-1 rounded ma-username-chip">
                                        <?php echo htmlspecialchars($adm['username'], ENT_QUOTES, 'UTF-8'); ?>
                                    </code>
                                </td>

                                <!-- email -->
                                <td class="small text-muted" data-label="इमेल">
                                    <?php if (!empty($adm['email'])): ?>
                                        <i class="lucide-icon me-1 opacity-50" data-lucide="mail" aria-hidden="true"></i>
                                        <?php echo htmlspecialchars($adm['email'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php else: ?>
                                        <span class="fst-italic opacity-50">—</span>
                                    <?php endif; ?>
                                </td>

                                <!-- role -->
                                <td data-label="Role">
                                    <?php if (function_exists('admin_db_role_is_superadmin') && admin_db_role_is_superadmin((string) ($adm['role'] ?? ''))): ?>
                                    <span class="badge rounded-pill ma-role-badge ma-role-super">
                                        <i class="lucide-icon me-1" data-lucide="crown" aria-hidden="true"></i>Super Admin
                                    </span>
                                    <?php elseif ($adm['role'] === 'admin'): ?>
                                    <span class="badge rounded-pill ma-role-badge ma-role-admin">
                                        <i class="lucide-icon me-1" data-lucide="shield-user" aria-hidden="true"></i>Admin
                                    </span>
                                    <?php else: ?>
                                    <span class="badge rounded-pill bg-secondary">
                                        <i class="lucide-icon me-1" data-lucide="pen" aria-hidden="true"></i>Editor
                                    </span>
                                    <?php endif; ?>
                                </td>

                                <!-- status -->
                                <td data-label="अवस्था">
                                    <?php if ($adm['is_active']): ?>
                                    <span class="badge rounded-pill ma-role-badge ma-status-active">
                                        <i class="lucide-icon me-1 ma-status-dot" data-lucide="circle" aria-hidden="true"></i>सक्रिय
                                    </span>
                                    <?php else: ?>
                                    <span class="badge rounded-pill bg-secondary">
                                        <i class="lucide-icon me-1 ma-status-dot" data-lucide="circle" aria-hidden="true"></i>निष्क्रिय
                                    </span>
                                    <?php endif; ?>
                                </td>

                                <!-- last login -->
                                <td class="small text-muted" data-label="अन्तिम Login">
                                    <?php if (!empty($adm['last_login'])): ?>
                                        <i class="lucide-icon me-1 opacity-50" data-lucide="clock" aria-hidden="true"></i>
                                        <?php echo date('Y-m-d H:i', strtotime($adm['last_login'])); ?>
                                    <?php else: ?>
                                        <span class="fst-italic opacity-50">कहिल्यै होइन</span>
                                    <?php endif; ?>
                                </td>

                                <!-- Actions -->
                                <td class="pe-3" data-label="कार्यहरू">
                                    <div class="adm-action-icons d-flex align-items-center justify-content-center gap-1 flex-wrap">

                                        <!-- Password Reset -->
                                        <button type="button"
                                                class="adm-icon-btn adm-icon-btn--edit ma-reset-btn"
                                                data-bs-toggle="modal"
                                                data-bs-target="#resetModal<?php echo (int)$adm['id']; ?>"
                                                title="Password Reset"
                                                aria-label="Password Reset">
                                            <i class="lucide-icon" data-lucide="key" aria-hidden="true"></i>
                                        </button>

                                        <!-- 2FA QR Reset (device change / mismatch) -->
                                        <?php
                                        $twofaOn = !empty($adm['twofa_enabled']) && trim((string) ($adm['twofa_secret'] ?? '')) !== '';
                                        if ($twofaOn):
                                        ?>
                                        <form method="POST" class="d-inline"
                                              onsubmit="return confirm('«<?php echo htmlspecialchars($adm['full_name'] ?? $adm['username'], ENT_QUOTES, 'UTF-8'); ?>» को 2FA QR reset गर्ने?\n\nअर्को login मा नयाँ QR scan गर्नुपर्छ।')">
                                            <input type="hidden" name="action" value="reset_2fa">
                                            <input type="hidden" name="target_id" value="<?php echo (int) $adm['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                            <button type="submit"
                                                    class="adm-icon-btn"
                                                    title="2FA QR Reset"
                                                    aria-label="2FA QR Reset">
                                                <i class="lucide-icon" data-lucide="qr-code" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>

                                        <!-- Toggle Active/Inactive -->
                                        <?php if (!$isMe): ?>
                                        <form method="POST" class="d-inline"
                                              onsubmit="return confirm('<?php echo $adm['is_active']
                                                  ? htmlspecialchars($adm['full_name'],ENT_QUOTES).' को account निष्क्रिय गर्ने?'
                                                  : htmlspecialchars($adm['full_name'],ENT_QUOTES).' को account सक्रिय गर्ने?'; ?>')">
                                            <input type="hidden" name="action"     value="toggle_active">
                                            <input type="hidden" name="target_id"  value="<?php echo (int)$adm['id']; ?>">
                                            <input type="hidden" name="new_status" value="<?php echo $adm['is_active'] ? 0 : 1; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                            <button type="submit"
                                                    class="adm-icon-btn <?php echo $adm['is_active'] ? '' : 'adm-icon-btn--view'; ?>"
                                                    title="<?php echo $adm['is_active'] ? 'Deactivate' : 'Activate'; ?>"
                                                    aria-label="<?php echo $adm['is_active'] ? 'Deactivate' : 'Activate'; ?>">
                                                <i class="lucide-icon" aria-hidden="true" data-lucide="<?php echo $adm['is_active'] ? 'ban' : 'check'; ?>"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>

                                        <!-- Delete -->
                                        <?php if (!$isMe): ?>
                                        <form method="POST" class="d-inline"
                                              onsubmit="return confirm('«<?php echo htmlspecialchars($adm['full_name'], ENT_QUOTES, 'UTF-8'); ?>» को account पूरै मेटाउने?\n\nयो कार्य फिर्ता हुँदैन!')">
                                            <input type="hidden" name="action"     value="delete_admin">
                                            <input type="hidden" name="target_id"  value="<?php echo (int)$adm['id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">
                                            <button type="submit" class="adm-icon-btn adm-icon-btn--delete" title="Delete" aria-label="Delete">
                                                <i class="lucide-icon" data-lucide="trash-2" aria-hidden="true"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>

                                    </div>
                                </td>
                            </tr>

                            <!-- ── Password Reset Modal ── -->
                            <div class="modal fade" id="resetModal<?php echo (int)$adm['id']; ?>"
                                 tabindex="-1" aria-hidden="true">
                                <div class="modal-dialog modal-dialog-centered modal-sm">
                                    <div class="modal-content border-0 shadow">
                                        <div class="modal-header py-3 ma-modal-header">
                                            <h6 class="modal-title mb-0">
                                                <i class="lucide-icon me-2" data-lucide="key" aria-hidden="true"></i>Password Reset
                                            </h6>
                                            <button type="button" class="btn-close btn-close-white"
                                                    data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body p-4">

                                            <!-- Admin info pill -->
                                            <div class="d-flex align-items-center gap-2 p-2 rounded-3 mb-3 ma-modal-admin-pill">
                                                <div class="ma-avatar-chip ma-avatar-chip-sm">
                                                    <?php echo mb_strtoupper(mb_substr($adm['full_name'],0,1,'UTF-8'),'UTF-8'); ?>
                                                </div>
                                                <div>
                                                    <div class="fw-semibold small">
                                                        <?php echo htmlspecialchars($adm['full_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                    </div>
                                                    <code class="text-muted ma-id-text">
                                                        @<?php echo htmlspecialchars($adm['username'], ENT_QUOTES, 'UTF-8'); ?>
                                                    </code>
                                                </div>
                                            </div>

                                            <form method="POST" action="">
                                                <input type="hidden" name="action"     value="reset_password">
                                                <input type="hidden" name="target_id"  value="<?php echo (int)$adm['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

                                                <div class="mb-3">
                                                    <label for="rp_new_<?php echo (int)$adm['id']; ?>" class="form-label fw-semibold small">
                                                        नयाँ पासवर्ड <span class="text-danger">*</span>
                                                    </label>
                                                    <div class="input-group">
                                                        <span class="input-group-text">
                                                            <i class="lucide-icon" data-lucide="lock" aria-hidden="true"></i>
                                                        </span>
                                                        <input type="password"
                                                               name="new_password"
                                                               id="rp_new_<?php echo (int)$adm['id']; ?>"
                                                               class="form-control"
                                                               minlength="8"
                                                               placeholder="कम्तिमा ८ अक्षर"
                                                               required
                                                               autocomplete="new-password">
                                                        <button type="button"
                                                                class="btn btn-outline-secondary"
                                                                onclick="togglePwd('rp_new_<?php echo (int)$adm['id']; ?>','rp_eye1_<?php echo (int)$adm['id']; ?>')"
                                                                aria-label="Show password" title="Show password">
                                                            <i class="lucide-icon" data-lucide="eye" aria-hidden="true"
                                                               id="rp_eye1_<?php echo (int)$adm['id']; ?>"></i>
                                                        </button>
                                                    </div>
                                                </div>

                                                <div class="mb-3">
                                                    <label for="rp_confirm_<?php echo (int)$adm['id']; ?>" class="form-label fw-semibold small">
                                                        पासवर्ड पुष्टि <span class="text-danger">*</span>
                                                    </label>
                                                    <div class="input-group">
                                                        <span class="input-group-text">
                                                            <i class="lucide-icon" data-lucide="lock" aria-hidden="true"></i>
                                                        </span>
                                                        <input type="password"
                                                               name="confirm_password"
                                                               id="rp_confirm_<?php echo (int)$adm['id']; ?>"
                                                               class="form-control"
                                                               minlength="8"
                                                               placeholder="माथिकै पासवर्ड फेरि"
                                                               required
                                                               autocomplete="new-password">
                                                        <button type="button"
                                                                class="btn btn-outline-secondary"
                                                                onclick="togglePwd('rp_confirm_<?php echo (int)$adm['id']; ?>','rp_eye2_<?php echo (int)$adm['id']; ?>')"
                                                                aria-label="Show password" title="Show password">
                                                            <i class="lucide-icon" data-lucide="eye" aria-hidden="true"
                                                               id="rp_eye2_<?php echo (int)$adm['id']; ?>"></i>
                                                        </button>
                                                    </div>
                                                    <div id="rp_match_<?php echo (int)$adm['id']; ?>"
                                                         class="form-text"></div>
                                                </div>

                                                <div class="p-2 rounded-2 small mb-3 ma-password-hint-box">
                                                    <i class="lucide-icon text-warning me-1" data-lucide="info" aria-hidden="true"></i>
                                                    ८+ अक्षर — ठूलो+सानो अक्षर + अंक + विशेष चिन्ह सिफारिस छ।
                                                </div>

                                                <button type="submit"
                                                        class="btn btn-warning w-100 fw-semibold"
                                                        onclick="return confirm('«<?php echo htmlspecialchars($adm['full_name'],ENT_QUOTES); ?>» को पासवर्ड reset गर्ने?')">
                                                    <i class="lucide-icon me-2" data-lucide="key" aria-hidden="true"></i>Password Reset गर्नुहोस्
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <!-- /modal -->

                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if ($isSuperAdmin): ?>
            <div class="card-footer py-2 small text-muted bg-light d-flex align-items-center gap-2">
                <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none" data-bs-toggle="collapse" data-bs-target="#manageAdminsFooterHelp" aria-expanded="false" aria-controls="manageAdminsFooterHelp">
                    <i class="lucide-icon me-1" data-lucide="info" aria-hidden="true"></i>नोट
                </button>
                <div class="collapse w-100" id="manageAdminsFooterHelp">
                    <div class="pt-2 small">
                        फाइल-सुपरएडमिन (<code>superadmin-config.local.php</code>) यो सूचीमा लुकेको छ।
                        अरू admin लाई Password Reset गर्दा उनीहरूले अर्को login मा नयाँ पासवर्ड राख्नुपर्छ; public reset URL छैन।
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Security note -->
        <div class="mt-3 p-3 rounded-3 small ma-security-note">
            <i class="lucide-icon me-2" data-lucide="shield" aria-hidden="true"></i>
            <strong>सुरक्षा नोट:</strong>
            Admin password reset गरेपछि नयाँ पासवर्ड सम्बन्धित admin लाई तुरुन्त व्यक्तिगत रूपमा जानकारी दिनुहोस्।
        </div>
    </div>
    <!-- /tab-list -->

    <!-- ════ TAB 2: CREATE ADMIN ════ -->
    <div class="tab-pane fade <?php echo $activeTab==='add'?'show active':''; ?>" id="tab-add">
        <div class="card border-0 shadow-sm">
            <div class="card-header py-3 ma-modal-header">
                <h6 class="mb-0">
                    <i class="lucide-icon me-2" data-lucide="user-plus" aria-hidden="true"></i>नयाँ Admin User बनाउनुहोस्
                </h6>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="" id="createAdminForm" class="needs-validation" novalidate>
                    <input type="hidden" name="action"     value="create_admin">
                    <input type="hidden" name="csrf_token" value="<?php echo e($csrfToken); ?>">

                    <div class="row g-3">

                        <!-- युजरनेम -->
                        <div class="col-md-4">
                            <label for="ma_username" class="form-label fw-semibold">
                                युजरनेम <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="lucide-icon text-muted" data-lucide="at" aria-hidden="true"></i>
                                </span>
                                <input type="text" name="username" id="ma_username" class="form-control"
                                       placeholder="uniqueusername" required
                                       pattern="[a-zA-Z0-9_]{3,30}"
                                       title="३–३० अक्षर: a-z, 0-9, _ मात्र">
                            </div>
                            <div class="form-text">
                                <i class="lucide-icon me-1" data-lucide="info" aria-hidden="true"></i>
                                ३–३० अक्षर, space नराख्नुहोस् (a-z, 0-9, _)
                            </div>
                        </div>

                        <!-- पूरा नाम -->
                        <div class="col-md-4">
                            <label for="ma_full_name" class="form-label fw-semibold">
                                पूरा नाम <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="lucide-icon text-muted" data-lucide="user" aria-hidden="true"></i>
                                </span>
                                <input type="text" name="full_name" id="ma_full_name" class="form-control"
                                       placeholder="Admin को पूरा नाम" required>
                            </div>
                        </div>

                        <!-- इमेल -->
                        <div class="col-md-4">
                            <label for="ma_email" class="form-label fw-semibold">
                                इमेल <span class="text-muted fw-normal small">(optional)</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="lucide-icon text-muted" data-lucide="mail" aria-hidden="true"></i>
                                </span>
                                <input type="email" name="email" id="ma_email" class="form-control"
                                       placeholder="admin@example.com">
                            </div>
                        </div>

                        <!-- Role -->
                        <div class="col-md-4">
                            <label for="ma_role" class="form-label fw-semibold">
                                Role <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="lucide-icon text-muted" data-lucide="shield-user" aria-hidden="true"></i>
                                </span>
                                <select name="role" id="ma_role" class="form-select">
                                    <option value="admin">Admin — सबै काम गर्न सक्छ</option>
                                    <option value="editor">Editor — content मात्र edit गर्न सक्छ</option>
                                </select>
                            </div>
                        </div>

                        <!-- पासवर्ड -->
                        <div class="col-md-4">
                            <label for="cp_new" class="form-label fw-semibold">
                                पासवर्ड <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="lucide-icon text-muted" data-lucide="lock" aria-hidden="true"></i>
                                </span>
                                <input type="password" name="new_password" id="cp_new"
                                       class="form-control" minlength="8"
                                       placeholder="कम्तिमा ८ अक्षर" required
                                       autocomplete="new-password">
                                <button type="button" class="btn btn-outline-secondary"
                                        onclick="togglePwd('cp_new','cp_eye1')" aria-label="View" title="View">
                                    <i class="lucide-icon" data-lucide="eye" aria-hidden="true" id="cp_eye1"></i>
                                </button>
                            </div>
                        </div>

                        <!-- पासवर्ड पुष्टि -->
                        <div class="col-md-4">
                            <label for="cp_confirm" class="form-label fw-semibold">
                                पासवर्ड पुष्टि <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text bg-light">
                                    <i class="lucide-icon text-muted" data-lucide="lock" aria-hidden="true"></i>
                                </span>
                                <input type="password" name="confirm_password" id="cp_confirm"
                                       class="form-control" minlength="8"
                                       placeholder="माथिकै पासवर्ड फेरि" required
                                       autocomplete="new-password">
                                <button type="button" class="btn btn-outline-secondary"
                                        onclick="togglePwd('cp_confirm','cp_eye2')" aria-label="View" title="View">
                                    <i class="lucide-icon" data-lucide="eye" aria-hidden="true" id="cp_eye2"></i>
                                </button>
                            </div>
                            <div id="cp_match" class="form-text mt-1"></div>
                        </div>

                        <!-- Active toggle -->
                        <div class="col-md-4 d-flex align-items-end pb-1">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox"
                                       name="is_active" id="isActiveCheck"
                                       checked role="switch">
                                <label class="form-check-label ms-2 fw-semibold" for="isActiveCheck">
                                    सक्रिय (Active)
                                </label>
                            </div>
                        </div>

                    </div>

                    <!-- Password policy hint -->
                    <div class="mt-3 p-3 rounded-3 small ma-security-note">
                        <i class="lucide-icon me-2" data-lucide="shield" aria-hidden="true"></i>
                        <strong>सुरक्षित पासवर्ड:</strong>
                        ठूलो अक्षर (A-Z) + सानो अक्षर (a-z) + अंक (0-9) + विशेष चिन्ह (!@#$) मिसाउनुहोस्।
                    </div>

                    <div class="mt-4 d-flex gap-2">
                        <button type="submit" class="btn btn-primary px-4 fw-semibold" id="createSubmitBtn">
                            <i class="lucide-icon me-2" data-lucide="user-plus" aria-hidden="true"></i>Admin बनाउनुहोस्
                        </button>
                        <button type="reset" class="btn btn-outline-secondary">
                            <i class="lucide-icon me-1" data-lucide="rotate-ccw" aria-hidden="true"></i>Clear
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <!-- /tab-add -->

</div><!-- /tab-content -->

<script>
/* ── Password show/hide ── */

/* ── Create form: real-time password match ── */
(function () {
    var n = document.getElementById('cp_new');
    var c = document.getElementById('cp_confirm');
    var m = document.getElementById('cp_match');
    if (!n || !c || !m) return;
    function check() {
        if (!c.value) { m.textContent = ''; return; }
        if (n.value === c.value) {
            m.innerHTML = '<span class="ma-pass-ok"><i class="lucide-icon me-1" data-lucide="circle-check" aria-hidden="true"></i>पासवर्ड मिल्यो</span>';
        } else {
            m.innerHTML = '<span class="ma-pass-bad"><i class="lucide-icon me-1" data-lucide="circle-x" aria-hidden="true"></i>पासवर्ड मिलेन</span>';
        }
    }
    n.addEventListener('input', check);
    c.addEventListener('input', check);
})();

/* ── Reset modals: real-time password match + clear on close ── */
document.querySelectorAll('[id^="resetModal"]').forEach(function (modal) {
    var id = modal.id.replace('resetModal', '');
    var n  = document.getElementById('rp_new_' + id);
    var c  = document.getElementById('rp_confirm_' + id);
    var m  = document.getElementById('rp_match_' + id);
    if (!n || !c || !m) return;
    function check() {
        if (!c.value) { m.textContent = ''; return; }
        if (n.value === c.value) {
            m.innerHTML = '<span class="ma-pass-ok"><i class="lucide-icon me-1" data-lucide="circle-check" aria-hidden="true"></i>पासवर्ड मिल्यो</span>';
        } else {
            m.innerHTML = '<span class="ma-pass-bad"><i class="lucide-icon me-1" data-lucide="circle-x" aria-hidden="true"></i>पासवर्ड मिलेन</span>';
        }
    }
    n.addEventListener('input', check);
    c.addEventListener('input', check);
    modal.addEventListener('hidden.bs.modal', function () {
        n.value = ''; c.value = ''; m.textContent = '';
    });
});

/* Add New tab click गर्दा scroll to top */
var tabAddBtn = document.getElementById('tabAddBtn');
if (tabAddBtn) {
    tabAddBtn.addEventListener('show.bs.tab', function () {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
}

/* Header "नयाँ Admin" button -> reliably open Add tab */
var btnAddAdmin = document.getElementById('btnAddAdmin');
if (btnAddAdmin && tabAddBtn) {
    btnAddAdmin.addEventListener('click', function (ev) {
        ev.preventDefault();
        if (window.bootstrap && bootstrap.Tab) {
            bootstrap.Tab.getOrCreateInstance(tabAddBtn).show();
        } else {
            tabAddBtn.click();
        }
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
}
</script>

<?php require_once 'includes/admin-footer.php'; ?>
