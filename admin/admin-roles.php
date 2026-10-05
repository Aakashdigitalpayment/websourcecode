<?php
/**
 * भूमिका र अनुमति (Custom admin roles) — superadmin only.
 * Each role = per-menu हेर्ने / थप्ने / सम्पादन / हटाउने. Assign roles in Admin व्यवस्थापन.
 * Engine + enforcement: includes/admin-permissions.php.
 */
require_once __DIR__ . '/includes/admin-page-boot.php';
$pageTitle   = 'भूमिका र अनुमति';
$currentPage = 'admin-roles';
$activeGroup = 'superadmin';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once __DIR__ . '/../includes/admin-permissions.php';

if (empty($_SESSION['is_superadmin'])) {
    setFlash('error', 'भूमिका र अनुमति केवल Superadmin ले खोल्न सक्छ।');
    redirect('dashboard.php');
    exit;
}

$db = getDB();
coop_perm_ensure_schema($db);
checkCSRF();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save_role') {
        $rid = (int)($_POST['role_id'] ?? 0);
        $name = mb_substr(trim(clean_text($_POST['name'] ?? '')), 0, 100);
        $desc = mb_substr(trim(clean_text($_POST['description'] ?? '')), 0, 255);
        $perms = coop_perm_normalize($_POST['perm'] ?? []);
        if ($name === '') {
            setFlash('error', 'भूमिकाको नाम अनिवार्य छ।');
            redirect('admin-roles.php' . ($rid > 0 ? '?edit=' . $rid : '?new=1'));
        }
        try {
            if ($rid > 0) {
                $db->prepare('UPDATE admin_roles SET name = ?, description = ?, permissions = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$name, $desc, json_encode($perms, JSON_UNESCAPED_UNICODE), $rid]);
            } else {
                $db->prepare('INSERT INTO admin_roles (name, description, permissions) VALUES (?, ?, ?)')
                    ->execute([$name, $desc, json_encode($perms, JSON_UNESCAPED_UNICODE)]);
                $rid = (int)$db->lastInsertId();
            }
            if (function_exists('writeAuditLog')) {
                writeAuditLog('admin_role_save', 'भूमिका: ' . $name . ' — ' . count($perms) . ' menu', 'admin_role', $rid);
            }
            setFlash('success', 'भूमिका "' . $name . '" सेभ भयो (' . count($perms) . ' menu मा अनुमति)। यो भूमिका भएका admin मा तुरुन्तै लागू हुन्छ।');
        } catch (Throwable $e) {
            error_log('[admin-roles] save: ' . $e->getMessage());
            setFlash('error', 'सेभ गर्न सकिएन।');
        }
        redirect('admin-roles.php');
    }
    if ($action === 'delete_role') {
        $rid = (int)($_POST['role_id'] ?? 0);
        $st = $db->prepare('SELECT COUNT(*) FROM admin_users WHERE custom_role_id = ?');
        $st->execute([$rid]);
        if ((int)$st->fetchColumn() > 0) {
            setFlash('error', 'यो भूमिका admin लाई दिइएको छ — पहिले Admin व्यवस्थापनबाट हटाउनुहोस्।');
        } elseif ($rid > 0) {
            $db->prepare('DELETE FROM admin_roles WHERE id = ?')->execute([$rid]);
            if (function_exists('writeAuditLog')) {
                writeAuditLog('admin_role_delete', 'भूमिका हटाइयो #' . $rid, 'admin_role', $rid);
            }
            setFlash('success', 'भूमिका हटाइयो।');
        }
        redirect('admin-roles.php');
    }
}

$roles = $db->query('SELECT r.*, (SELECT COUNT(*) FROM admin_users u WHERE u.custom_role_id = r.id) AS user_count FROM admin_roles r ORDER BY r.name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$editId = (int)($_GET['edit'] ?? 0);
$editing = null;
foreach ($roles as $r) {
    if ((int)$r['id'] === $editId) {
        $editing = $r;
    }
}
$showForm = $editing !== null || isset($_GET['new']);
$cur = $editing ? coop_perm_normalize($editing['permissions'] ?? '') : [];
$actions = coop_perm_actions();
$presets = coop_perm_presets();
?>
<div class="container-fluid py-3 roles-page">
  <?php echo adminPageHeader('भूमिका र अनुमति', 'shield-check',
      'Admin लाई कुन menu मा के गर्न दिने — हेर्ने / थप्ने / सम्पादन / हटाउने। भूमिका Admin व्यवस्थापनमा user लाई दिनुहोस्।',
      '<div class="d-flex gap-2 flex-wrap">'
      . '<a href="admin-roles.php?new=1" class="btn btn-success btn-sm"><i class="lucide-icon me-1" data-lucide="plus" aria-hidden="true"></i>नयाँ भूमिका</a>'
      . '<a href="manage-admins.php" class="btn btn-outline-secondary btn-sm"><i class="lucide-icon me-1" data-lucide="users" aria-hidden="true"></i>Admin व्यवस्थापन</a>'
      . '</div>'); ?>
  <?php if ($f = getFlash()): ?><div class="mb-3"><?php echo adminAlert($f['type'], $f['message']); ?></div><?php endif; ?>

  <div class="alert alert-info small">
    <strong>कसरी काम गर्छ:</strong> भूमिका दिइएका admin ले छानिएका menu मात्र देख्छन्; बाँकी sidebar बाट लुक्छन् र URL बाट खोल्दा पनि रोकिन्छ।
    <em>थप्ने / सम्पादन / हटाउने</em> छान्दा <em>हेर्ने</em> आफैँ आउँछ। Admin users, सुरक्षा, Backup, DB जस्ता superadmin पेज कुनै भूमिकामा दिन मिल्दैन।
    भूमिका नदिइएका admin / editor पहिलेजस्तै चल्छन्।
  </div>

  <?php if ($showForm): ?>
  <div class="card admin-table-card mb-4">
    <div class="card-header"><h6 class="mb-0"><?php echo $editing ? 'भूमिका सम्पादन — ' . htmlspecialchars((string)$editing['name']) : 'नयाँ भूमिका'; ?></h6></div>
    <div class="card-body">
      <form method="post" id="roleForm">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="save_role">
        <input type="hidden" name="role_id" value="<?php echo (int)($editing['id'] ?? 0); ?>">
        <div class="row g-3 mb-3">
          <div class="col-md-4"><label class="form-label" for="role_name">भूमिकाको नाम <span class="text-danger">*</span></label>
            <input class="form-control" id="role_name" name="name" maxlength="100" required placeholder="उदा. ऋण शाखा staff" value="<?php echo htmlspecialchars((string)($editing['name'] ?? '')); ?>"></div>
          <div class="col-md-5"><label class="form-label" for="role_desc">विवरण</label>
            <input class="form-control" id="role_desc" name="description" maxlength="255" placeholder="छोटो विवरण (ऐच्छिक)" value="<?php echo htmlspecialchars((string)($editing['description'] ?? '')); ?>"></div>
          <div class="col-md-3"><label class="form-label" for="role_preset">Preset बाट भर्नुहोस्</label>
            <select class="form-select" id="role_preset">
              <option value="">— छान्नुहोस् —</option>
              <?php foreach ($presets as $k => $p): ?><option value="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars($p['label']); ?></option><?php endforeach; ?>
              <option value="__none">सबै खाली गर्नुहोस्</option>
            </select></div>
        </div>

        <div class="table-responsive roles-matrix-wrap">
          <table class="table table-sm align-middle roles-matrix mb-0">
            <thead><tr><th>Menu</th><?php foreach ($actions as $fl => $lb): ?><th class="text-center"><?php echo htmlspecialchars($lb); ?></th><?php endforeach; ?><th class="text-center">सबै</th></tr></thead>
            <?php foreach (coop_perm_registry() as $gk => $g): ?>
            <tbody data-group="<?php echo htmlspecialchars($gk); ?>">
              <tr class="roles-group-row">
                <th scope="rowgroup"><?php echo htmlspecialchars($g['label']); ?></th>
                <?php foreach ($actions as $fl => $lb): ?>
                <td class="text-center"><input type="checkbox" class="form-check-input js-col" data-flag="<?php echo $fl; ?>" aria-label="<?php echo htmlspecialchars($g['label'] . ' — सबैमा ' . $lb); ?>"></td>
                <?php endforeach; ?>
                <td></td>
              </tr>
              <?php foreach ($g['items'] as $page => $label): $have = $cur[$page] ?? ''; ?>
              <tr data-page="<?php echo htmlspecialchars($page); ?>">
                <td><?php echo htmlspecialchars($label); ?> <span class="text-muted small font-monospace"><?php echo htmlspecialchars($page); ?></span></td>
                <?php foreach ($actions as $fl => $lb): ?>
                <td class="text-center"><input type="checkbox" class="form-check-input js-perm" data-flag="<?php echo $fl; ?>" name="perm[<?php echo htmlspecialchars($page); ?>][<?php echo $fl; ?>]" value="1"<?php echo str_contains($have, $fl) ? ' checked' : ''; ?> aria-label="<?php echo htmlspecialchars($label . ' — ' . $lb); ?>"></td>
                <?php endforeach; ?>
                <td class="text-center"><input type="checkbox" class="form-check-input js-row" aria-label="<?php echo htmlspecialchars($label . ' — सबै'); ?>"></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <?php endforeach; ?>
          </table>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
          <button type="submit" class="btn btn-success"><i class="lucide-icon me-1" data-lucide="save" aria-hidden="true"></i>भूमिका सेभ गर्नुहोस्</button>
          <a href="admin-roles.php" class="btn btn-outline-secondary">रद्द</a>
          <span class="small text-muted" id="roleCount"></span>
        </div>
      </form>
    </div>
  </div>
  <script>
  (function () {
    var form = document.getElementById('roleForm'); if (!form) return;
    var presets = <?php echo json_encode(array_map(static fn ($p) => $p['perms'], $presets), JSON_UNESCAPED_UNICODE); ?>;
    var countEl = document.getElementById('roleCount');
    function rowBoxes(tr) { return tr.querySelectorAll('.js-perm'); }
    function syncRow(tr) {
      var boxes = rowBoxes(tr), v = tr.querySelector('.js-perm[data-flag="v"]'), any = false, all = true;
      boxes.forEach(function (b) { if (b.dataset.flag !== 'v' && b.checked) any = true; });
      if (any) v.checked = true;
      boxes.forEach(function (b) { if (!b.checked) all = false; });
      var r = tr.querySelector('.js-row'); if (r) r.checked = all;
    }
    function syncAll() {
      var n = 0;
      form.querySelectorAll('tr[data-page]').forEach(function (tr) { syncRow(tr); if (tr.querySelector('.js-perm[data-flag="v"]').checked) n++; });
      form.querySelectorAll('tbody[data-group]').forEach(function (tb) {
        tb.querySelectorAll('.js-col').forEach(function (c) {
          var bs = tb.querySelectorAll('.js-perm[data-flag="' + c.dataset.flag + '"]'), all = bs.length > 0;
          bs.forEach(function (b) { if (!b.checked) all = false; }); c.checked = all;
        });
      });
      countEl.textContent = n + ' menu मा पहुँच';
    }
    form.addEventListener('change', function (e) {
      var t = e.target, tr = t.closest('tr');
      if (t.classList.contains('js-perm')) {
        if (t.dataset.flag === 'v' && !t.checked) rowBoxes(tr).forEach(function (b) { b.checked = false; });
      } else if (t.classList.contains('js-row')) {
        rowBoxes(tr).forEach(function (b) { b.checked = t.checked; });
      } else if (t.classList.contains('js-col')) {
        t.closest('tbody').querySelectorAll('tr[data-page]').forEach(function (r) {
          var b = r.querySelector('.js-perm[data-flag="' + t.dataset.flag + '"]'); b.checked = t.checked;
          if (t.dataset.flag === 'v' && !t.checked) rowBoxes(r).forEach(function (x) { x.checked = false; });
        });
      }
      syncAll();
    });
    document.getElementById('role_preset').addEventListener('change', function () {
      var k = this.value; if (!k) return;
      var p = presets[k] || {};
      form.querySelectorAll('tr[data-page]').forEach(function (tr) {
        var f = p[tr.dataset.page] || '';
        rowBoxes(tr).forEach(function (b) { b.checked = f.indexOf(b.dataset.flag) !== -1; });
      });
      syncAll(); this.value = '';
    });
    syncAll();
  })();
  </script>
  <?php endif; ?>

  <div class="card admin-table-card">
    <div class="card-header"><h6 class="mb-0">भूमिकाहरू (<?php echo count($roles); ?>)</h6></div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead><tr><th>भूमिका</th><th>पहुँच</th><th class="text-center">Admin users</th><th>कार्य</th></tr></thead>
        <tbody>
        <?php if (!$roles): ?>
          <tr><td colspan="4" class="text-center text-muted py-4">अहिलेसम्म भूमिका छैन — <a href="admin-roles.php?new=1">नयाँ भूमिका बनाउनुहोस्</a>।</td></tr>
        <?php endif; foreach ($roles as $r): $p = coop_perm_normalize($r['permissions'] ?? '');
            $tally = ['v' => 0, 'c' => 0, 'e' => 0, 'd' => 0];
            foreach ($p as $fl) { foreach (str_split($fl) as $ch) { $tally[$ch]++; } } ?>
          <tr>
            <td><strong><?php echo htmlspecialchars((string)$r['name']); ?></strong>
              <?php if ((string)$r['description'] !== ''): ?><div class="small text-muted"><?php echo htmlspecialchars((string)$r['description']); ?></div><?php endif; ?></td>
            <td class="small"><?php foreach ($actions as $fl => $lb): ?><span class="badge bg-light text-dark border me-1"><?php echo htmlspecialchars($lb); ?>: <?php echo (int)$tally[$fl]; ?></span><?php endforeach; ?></td>
            <td class="text-center"><?php echo (int)$r['user_count']; ?></td>
            <td><div class="prog-row-actions">
              <a class="adm-icon-btn adm-icon-btn--edit" href="admin-roles.php?edit=<?php echo (int)$r['id']; ?>" title="सम्पादन" aria-label="सम्पादन — <?php echo htmlspecialchars((string)$r['name']); ?>"><i class="lucide-icon" data-lucide="pen" aria-hidden="true"></i></a>
              <form method="post" class="d-inline" onsubmit="return confirm('यो भूमिका हटाउने?');"><?php echo csrfField(); ?><input type="hidden" name="action" value="delete_role"><input type="hidden" name="role_id" value="<?php echo (int)$r['id']; ?>">
                <button type="submit" class="adm-icon-btn adm-icon-btn--delete" title="हटाउनुहोस्" aria-label="हटाउनुहोस् — <?php echo htmlspecialchars((string)$r['name']); ?>"<?php echo (int)$r['user_count'] > 0 ? ' disabled' : ''; ?>><i class="lucide-icon" data-lucide="trash-2" aria-hidden="true"></i></button></form>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require_once 'includes/admin-footer.php'; ?>
