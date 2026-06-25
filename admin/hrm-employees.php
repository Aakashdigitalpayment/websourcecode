<?php
/**
 * 👥 HRM — कर्मचारी सूची र थप/सम्पादन
 */
$currentPage = 'hrm-employees';
$pageTitle   = 'कर्मचारीहरू';
require_once __DIR__ . '/includes/admin-header.php';
require_once __DIR__ . '/../includes/auth-roles.php';
require_once __DIR__ . '/../includes/election-tables.php';
require_once __DIR__ . '/includes/hrm-tables.php';
require_role('admin');

$db = getDB();
ensureHrmTables($db);
ensureDesignationsTable($db);

$me           = (int)($_SESSION['admin_id'] ?? 0);
$departments  = hrmListDepartments($db);
$branches     = hrmListBranches($db);
$designations = fetchDesignations($db, ['staff','admin']);

/* ── POST ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $code = trim($_POST['employee_code'] ?? '');
        if ($code === '') $code = hrmGenerateEmployeeCode($db);

        $fields = [
            'employee_code' => $code,
            'full_name_np'  => trim($_POST['full_name_np'] ?? ''),
            'full_name_en'  => trim($_POST['full_name_en'] ?? '') ?: null,
            'gender'        => $_POST['gender'] ?? 'male',
            'dob_bs'        => trim($_POST['dob_bs'] ?? '') ?: null,
            'dob_ad'        => trim($_POST['dob_ad'] ?? '') ?: null,
            'blood_group'   => trim($_POST['blood_group'] ?? '') ?: null,
            'marital_status'=> $_POST['marital_status'] ?? 'single',
            'citizenship_no'=> trim($_POST['citizenship_no'] ?? '') ?: null,
            'pan_no'        => trim($_POST['pan_no'] ?? '') ?: null,
            'mobile'        => trim($_POST['mobile'] ?? '') ?: null,
            'email'         => trim($_POST['email'] ?? '') ?: null,
            'perm_district' => trim($_POST['perm_district'] ?? '') ?: null,
            'perm_municipality' => trim($_POST['perm_municipality'] ?? '') ?: null,
            'perm_ward'     => trim($_POST['perm_ward'] ?? '') ?: null,
            'designation'   => trim($_POST['designation'] ?? '') ?: null,
            'department_id' => (int)($_POST['department_id'] ?? 0) ?: null,
            'branch_id'     => (int)($_POST['branch_id'] ?? 0) ?: null,
            'employment_type' => $_POST['employment_type'] ?? 'permanent',
            'grade'         => trim($_POST['grade'] ?? '') ?: null,
            'level'         => trim($_POST['level'] ?? '') ?: null,
            'join_date_bs'  => trim($_POST['join_date_bs'] ?? '') ?: null,
            'join_date_ad'  => trim($_POST['join_date_ad'] ?? '') ?: null,
            'status'        => $_POST['status'] ?? 'active',
            'remarks'       => trim($_POST['remarks'] ?? '') ?: null,
        ];

        if ($fields['full_name_np'] === '') {
            setFlash('error', 'पूरा नाम (नेपाली) आवश्यक छ।');
            header('Location: hrm-employees.php'); exit;
        }

        // photo upload
        if (!empty($_FILES['photo']['name'])) {
            $rel = hrmHandleUpload($_FILES['photo'], 'photos');
            if ($rel) $fields['photo'] = $rel;
        }

        try {
            if ($id > 0) {
                $set = []; $vals = [];
                foreach ($fields as $k=>$v) { $set[] = "$k=?"; $vals[] = $v; }
                $vals[] = $me; $vals[] = $id;
                $sql = "UPDATE hrm_employees SET ".implode(',', $set).", updated_by=? WHERE id=?";
                $db->prepare($sql)->execute($vals);
                setFlash('success', 'कर्मचारी विवरण अद्यावधिक भयो।');
            } else {
                $cols = array_keys($fields);
                $cols[] = 'created_by';
                $place = rtrim(str_repeat('?,', count($cols)), ',');
                $vals = array_values($fields); $vals[] = $me;
                $sql = "INSERT INTO hrm_employees (".implode(',', $cols).") VALUES ($place)";
                $stmt = $db->prepare($sql);
                $stmt->execute($vals);
                $newId = (int)$db->lastInsertId();
                // auto initial appointment history
                $db->prepare("INSERT INTO hrm_employee_history (employee_id,event_type,event_date_ad,to_designation,to_department_id,to_branch_id,description,created_by)
                              VALUES (?, 'appointment', ?, ?, ?, ?, 'प्रारम्भिक नियुक्ति', ?)")
                   ->execute([$newId, $fields['join_date_ad'], $fields['designation'], $fields['department_id'], $fields['branch_id'], $me]);
                setFlash('success', 'नयाँ कर्मचारी सफलतापूर्वक थपियो।');
            }
        } catch (\PDOException $e) {
            setFlash('error', 'त्रुटि: '.e($e->getMessage()));
        }
        header('Location: hrm-employees.php'); exit;
    }

    if ($action === 'delete' && is_superadmin()) {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $db->prepare("DELETE FROM hrm_employees WHERE id=?")->execute([$id]);
            setFlash('success', 'कर्मचारी हटाइयो।');
        }
        header('Location: hrm-employees.php'); exit;
    }
}

/* ── Filters ── */
$q       = trim($_GET['q'] ?? '');
$fStatus = $_GET['status'] ?? '';
$fDept   = (int)($_GET['dept'] ?? 0);

$where = []; $args = [];
if ($q !== '')      { $where[] = "(full_name_np LIKE ? OR employee_code LIKE ? OR mobile LIKE ?)"; $args[]="%$q%"; $args[]="%$q%"; $args[]="%$q%"; }
if ($fStatus !== ''){ $where[] = "status=?"; $args[]=$fStatus; }
if ($fDept > 0)    { $where[] = "department_id=?"; $args[]=$fDept; }
$whereSql = $where ? 'WHERE '.implode(' AND ', $where) : '';

$stmt = $db->prepare("SELECT e.*, d.name_np AS dept_name
                        FROM hrm_employees e
                        LEFT JOIN hrm_departments d ON d.id = e.department_id
                        $whereSql
                        ORDER BY e.id DESC");
$stmt->execute($args);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="admin-content">
    <div class="page-header stf-page-head">
        <div>
            <h1 class="stf-title">👥 कर्मचारी सूची</h1>
            <p class="stf-subtitle">मानव संशाधन — सम्पूर्ण कर्मचारी रेकर्ड</p>
        </div>
        <div class="d-flex gap-2">
          <a class="btn-coop" href="hrm-dashboard.php"><i class="fas fa-gauge"></i> ड्यासबोर्ड</a>
          <button class="btn-coop" onclick="var m=document.getElementById('empModal');m.style.display='flex';m.scrollIntoView({behavior:'smooth',block:'start'});">
              <i class="fas fa-user-plus"></i> नयाँ कर्मचारी
          </button>
        </div>
    </div>

    <?php if ($f = getFlash()): ?>
        <div class="alert alert-<?= $f['type']==='error'?'danger':'success' ?>"><?= e($f['message']) ?></div>
    <?php endif; ?>

    <form method="get" class="card-coop p-3 mb-3 d-flex flex-wrap gap-2 align-items-end">
        <div class="flex-grow-1" style="min-width:200px;">
          <label class="small text-muted">खोज (नाम/कोड/मोबाइल)</label>
          <input class="field-coop" name="q" value="<?= e($q) ?>" placeholder="खोज्नुहोस्…">
        </div>
        <div>
          <label class="small text-muted">अवस्था</label>
          <select class="field-coop" name="status">
            <option value="">— सबै —</option>
            <?php foreach (['active'=>'सक्रिय','probation'=>'परीक्षणकाल','on_leave'=>'बिदामा','suspended'=>'निलम्बित','resigned'=>'राजीनामा','terminated'=>'बर्खास्त','retired'=>'अवकाश'] as $k=>$v): ?>
              <option value="<?= $k ?>" <?= $fStatus===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="small text-muted">विभाग</label>
          <select class="field-coop" name="dept">
            <option value="0">— सबै —</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?= (int)$d['id'] ?>" <?= $fDept===(int)$d['id']?'selected':'' ?>><?= e($d['name_np']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="btn-coop"><i class="fas fa-filter"></i> फिल्टर</button>
    </form>

    <div class="card-coop stf-card-table-wrap admin-table-card">
        <table class="table table-hover mb-0 stf-table table-responsive-stack">
            <thead class="stf-soft-head">
                <tr>
                    <th>कोड</th><th>नाम</th><th>पद</th><th>विभाग</th><th>नियुक्ति</th><th>प्रकार</th><th>अवस्था</th><th class="stf-align-right">कार्य</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">कुनै कर्मचारी फेला परेन।</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><code><?= e($r['employee_code']) ?></code></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                          <img src="<?= e(hrmEmployeePhotoUrl($r['photo'])) ?>" alt="" style="width:34px;height:34px;border-radius:50%;object-fit:cover;background:#eee">
                          <div>
                            <strong><?= e($r['full_name_np']) ?></strong><br>
                            <small class="text-muted"><?= e($r['mobile']) ?></small>
                          </div>
                        </div>
                    </td>
                    <td><small><?= e($r['designation']) ?></small></td>
                    <td><small><?= e($r['dept_name']) ?></small></td>
                    <td><small><?= e($r['join_date_ad'] ?: $r['join_date_bs']) ?></small></td>
                    <td><small><?= e($r['employment_type']) ?></small></td>
                    <td><?= hrmStatusBadge($r['status']) ?></td>
                    <td class="stf-align-right">
                        <a class="btn btn-sm btn-outline-primary" href="hrm-employee-view.php?id=<?= (int)$r['id'] ?>"><i class="fas fa-eye"></i></a>
                        <a class="btn btn-sm btn-outline-success" target="_blank" title="Digital ID Card" href="hrm-employee-id-card.php?id=<?= (int)$r['id'] ?>"><i class="fas fa-id-card"></i></a>
                        <button class="btn btn-sm btn-outline-secondary" onclick='editEmp(<?= json_encode($r, JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'><i class="fas fa-pen"></i></button>
                        <?php if (is_superadmin()): ?>
                        <form method="post" class="stf-inline-form" onsubmit="return confirm('पक्का delete गर्ने?');">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn btn-sm btn-outline-danger" aria-label="Delete" title="Delete"><i class="fas fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add/Edit modal -->
<div id="empModal" class="stf-modal-backdrop">
  <div class="card-coop stf-modal-card stf-modal-card-lg" style="max-width:880px;width:96%;">
    <h3 class="stf-section-title" id="empModalTitle">नयाँ कर्मचारी</h3>
    <form method="post" enctype="multipart/form-data" class="needs-validation" novalidate>
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" id="f_id" value="0">

      <div class="row g-2">
        <div class="col-md-3"><label class="small">कर्मचारी कोड</label><input class="field-coop" name="employee_code" id="f_code" placeholder="EMP-2082-0001"></div>
        <div class="col-md-5"><label class="small">पूरा नाम (नेपाली) *</label><input class="field-coop" name="full_name_np" id="f_name_np" required></div>
        <div class="col-md-4"><label class="small">Full Name (English)</label><input class="field-coop" name="full_name_en" id="f_name_en"></div>

        <div class="col-md-3"><label class="small">लिङ्ग</label>
          <select class="field-coop" name="gender" id="f_gender">
            <option value="male">पुरुष</option><option value="female">महिला</option><option value="other">अन्य</option>
          </select>
        </div>
        <div class="col-md-3"><label class="small">जन्म मिति (BS)</label><input class="field-coop nepali-datepicker hrm-bs-date" name="dob_bs" id="f_dob_bs" placeholder="YYYY-MM-DD" autocomplete="off"></div>
        <div class="col-md-3"><label class="small">जन्म मिति (AD)</label><input class="field-coop" type="date" name="dob_ad" id="f_dob_ad"></div>
        <div class="col-md-3"><label class="small">रक्त समूह</label><input class="field-coop" name="blood_group" id="f_blood"></div>

        <div class="col-md-3"><label class="small">वैवाहिक स्थिति</label>
          <select class="field-coop" name="marital_status" id="f_ms">
            <?php foreach (['single'=>'अविवाहित','married'=>'विवाहित','widow'=>'विधवा/विधुर','divorced'=>'पारपाचुके'] as $k=>$v): ?>
              <option value="<?= $k ?>"><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3"><label class="small">मोबाइल</label><input class="field-coop" name="mobile" id="f_mobile"></div>
        <div class="col-md-3"><label class="small">Email</label><input class="field-coop" type="email" name="email" id="f_email"></div>
        <div class="col-md-3"><label class="small">फोटो</label><input class="field-coop" type="file" name="photo" accept="image/*"></div>

        <div class="col-md-4"><label class="small">नागरिकता नं.</label><input class="field-coop" name="citizenship_no" id="f_citi"></div>
        <div class="col-md-4"><label class="small">PAN नं.</label><input class="field-coop" name="pan_no" id="f_pan"></div>
        <div class="col-md-4"><label class="small">स्थायी जिल्ला/नगर/वडा</label>
          <div class="d-flex gap-1">
            <input class="field-coop" name="perm_district" id="f_pdist" placeholder="जिल्ला" list="hrmDistrictOptions">
            <input class="field-coop" name="perm_municipality" id="f_pmun" placeholder="न.पा./गा.पा." list="hrmLocalGovOptions">
            <input class="field-coop" name="perm_ward" id="f_pward" placeholder="वडा" style="max-width:80px;">
          </div>
          <datalist id="hrmDistrictOptions">
            <option value="काठमाडौं"><option value="ललितपुर"><option value="भक्तपुर"><option value="काभ्रेपलाञ्चोक"><option value="चितवन"><option value="मकवानपुर"><option value="कास्की"><option value="रुपन्देही"><option value="मोरङ"><option value="झापा">
          </datalist>
          <datalist id="hrmLocalGovOptions">
            <option value="महानगरपालिका"><option value="उपमहानगरपालिका"><option value="नगरपालिका"><option value="गाउँपालिका">
          </datalist>
        </div>

        <hr class="my-2">

        <div class="col-md-4"><label class="small">पद</label>
          <select class="field-coop" name="designation" id="f_desig">
            <option value="">— छान्नुहोस् —</option>
            <?php foreach ($designations as $d): ?>
              <option value="<?= e($d['title_np']) ?>"><?= e($d['title_np']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4"><label class="small">विभाग</label>
          <select class="field-coop" name="department_id" id="f_dept">
            <option value="0">— छान्नुहोस् —</option>
            <?php foreach ($departments as $d): ?>
              <option value="<?= (int)$d['id'] ?>"><?= e($d['name_np']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-4"><label class="small">शाखा</label>
          <select class="field-coop" name="branch_id" id="f_branch">
            <option value="0">— केन्द्रीय कार्यालय —</option>
            <?php foreach ($branches as $b): ?>
              <option value="<?= (int)$b['id'] ?>"><?= e($b['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-3"><label class="small">सेवा प्रकार</label>
          <select class="field-coop" name="employment_type" id="f_etype">
            <?php foreach (['permanent'=>'स्थायी','contract'=>'करार','probation'=>'परीक्षणकाल','temporary'=>'अस्थायी','intern'=>'इन्टर्न','consultant'=>'परामर्शदाता'] as $k=>$v): ?>
              <option value="<?= $k ?>"><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2"><label class="small">तह</label><input class="field-coop" name="level" id="f_level"></div>
        <div class="col-md-2"><label class="small">श्रेणी</label><input class="field-coop" name="grade" id="f_grade"></div>
        <div class="col-md-2"><label class="small">नियुक्ति (BS)</label><input class="field-coop nepali-datepicker hrm-bs-date" name="join_date_bs" id="f_jdbs" placeholder="YYYY-MM-DD" autocomplete="off"></div>
        <div class="col-md-3"><label class="small">नियुक्ति (AD)</label><input class="field-coop" type="date" name="join_date_ad" id="f_jdad"></div>

        <div class="col-md-4"><label class="small">अवस्था</label>
          <select class="field-coop" name="status" id="f_status">
            <?php foreach (['active'=>'सक्रिय','probation'=>'परीक्षणकाल','on_leave'=>'बिदामा','suspended'=>'निलम्बित','resigned'=>'राजीनामा','terminated'=>'बर्खास्त','retired'=>'अवकाश'] as $k=>$v): ?>
              <option value="<?= $k ?>"><?= $v ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-8"><label class="small">कैफियत</label><input class="field-coop" name="remarks" id="f_remarks"></div>
      </div>

      <div class="stf-actions-row stf-actions-row-lg mt-3">
        <button type="button" class="btn-coop btn-outline" onclick="document.getElementById('empModal').style.display='none';window.scrollTo({top:0,behavior:'smooth'});">रद्द</button>
        <button type="submit" class="btn-coop">Save / सुरक्षित गर्नुहोस्</button>
      </div>
    </form>
  </div>
</div>

<script>
function editEmp(r){
  const set = (id, v) => { const el = document.getElementById(id); if (el) el.value = v ?? ''; };
  set('f_id', r.id); set('f_code', r.employee_code);
  set('f_name_np', r.full_name_np); set('f_name_en', r.full_name_en);
  set('f_gender', r.gender); set('f_dob_bs', r.dob_bs); set('f_dob_ad', r.dob_ad);
  set('f_blood', r.blood_group); set('f_ms', r.marital_status);
  set('f_mobile', r.mobile); set('f_email', r.email);
  set('f_citi', r.citizenship_no); set('f_pan', r.pan_no);
  set('f_pdist', r.perm_district); set('f_pmun', r.perm_municipality); set('f_pward', r.perm_ward);
  set('f_desig', r.designation); set('f_dept', r.department_id || 0); set('f_branch', r.branch_id || 0);
  set('f_etype', r.employment_type); set('f_level', r.level); set('f_grade', r.grade);
  set('f_jdbs', r.join_date_bs); set('f_jdad', r.join_date_ad);
  set('f_status', r.status); set('f_remarks', r.remarks);
  document.getElementById('empModalTitle').textContent = 'कर्मचारी सम्पादन — ' + r.full_name_np;
  document.getElementById('empModal').style.display = 'flex';
}
</script>
<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>
