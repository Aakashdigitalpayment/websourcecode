<?php
/**
 * 👤 HRM — कर्मचारी प्रोफाइल (Tabs: Overview / Contracts / Documents / Education / Experience / Family / Bank / History)
 */
require_once __DIR__ . '/includes/admin-header.php';
require_once __DIR__ . '/../includes/auth-roles.php';
require_once __DIR__ . '/includes/hrm-tables.php';
require_role('admin');

$db = getDB();
ensureHrmTables($db);

$id = (int)($_GET['id'] ?? 0);
$me = (int)($_SESSION['admin_id'] ?? 0);

if ($id <= 0) { header('Location: hrm-employees.php'); exit; }

$departments = hrmListDepartments($db);
$branches    = hrmListBranches($db);

/* ── POST handlers ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';

    if ($a === 'add_contract') {
        $end = trim($_POST['end_date_ad'] ?? '');
        $stmt = $db->prepare("INSERT INTO hrm_employee_contracts
            (employee_id,contract_no,contract_type,designation,department_id,branch_id,start_date_bs,start_date_ad,end_date_bs,end_date_ad,basic_salary,allowance,notes,file_path,is_active,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)");
        $rel = !empty($_FILES['file']['name']) ? hrmHandleUpload($_FILES['file'], 'contracts') : null;
        $stmt->execute([$id,
            trim($_POST['contract_no'] ?? '') ?: null,
            $_POST['contract_type'] ?? 'appointment',
            trim($_POST['designation'] ?? '') ?: null,
            (int)($_POST['department_id'] ?? 0) ?: null,
            (int)($_POST['branch_id'] ?? 0) ?: null,
            trim($_POST['start_date_bs'] ?? '') ?: null,
            trim($_POST['start_date_ad'] ?? '') ?: null,
            trim($_POST['end_date_bs'] ?? '') ?: null,
            $end !== '' ? $end : null,
            (float)($_POST['basic_salary'] ?? 0),
            (float)($_POST['allowance'] ?? 0),
            trim($_POST['notes'] ?? '') ?: null,
            $rel, $me
        ]);
        setFlash('success', 'करार पत्र थपियो।');
        header("Location: hrm-employee-view.php?id=$id#contracts"); exit;
    }

    if ($a === 'add_document') {
        $rel = !empty($_FILES['file']['name']) ? hrmHandleUpload($_FILES['file'], 'docs') : null;
        $db->prepare("INSERT INTO hrm_employee_documents
            (employee_id,doc_type,title,doc_number,issued_by,issued_date_bs,issued_date_ad,expiry_date_ad,file_path,notes,uploaded_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$id,
              $_POST['doc_type'] ?? 'other',
              trim($_POST['title'] ?? 'कागजात'),
              trim($_POST['doc_number'] ?? '') ?: null,
              trim($_POST['issued_by'] ?? '') ?: null,
              trim($_POST['issued_date_bs'] ?? '') ?: null,
              trim($_POST['issued_date_ad'] ?? '') ?: null,
              trim($_POST['expiry_date_ad'] ?? '') ?: null,
              $rel, trim($_POST['notes'] ?? '') ?: null, $me
           ]);
        setFlash('success', 'कागजात थपियो।');
        header("Location: hrm-employee-view.php?id=$id#documents"); exit;
    }

    if ($a === 'add_education') {
        $db->prepare("INSERT INTO hrm_employee_education
            (employee_id,level,board_university,institution,major,passed_year,division_grade,percentage)
            VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$id,
             trim($_POST['level'] ?? ''),
             trim($_POST['board_university'] ?? '') ?: null,
             trim($_POST['institution'] ?? '') ?: null,
             trim($_POST['major'] ?? '') ?: null,
             trim($_POST['passed_year'] ?? '') ?: null,
             trim($_POST['division_grade'] ?? '') ?: null,
             trim($_POST['percentage'] ?? '') ?: null,
           ]);
        setFlash('success', 'शैक्षिक योग्यता थपियो।');
        header("Location: hrm-employee-view.php?id=$id#education"); exit;
    }

    if ($a === 'add_experience') {
        $db->prepare("INSERT INTO hrm_employee_experience
            (employee_id,organization,designation,from_date_ad,to_date_ad,responsibilities)
            VALUES (?,?,?,?,?,?)")
           ->execute([$id,
              trim($_POST['organization'] ?? ''),
              trim($_POST['designation'] ?? '') ?: null,
              trim($_POST['from_date_ad'] ?? '') ?: null,
              trim($_POST['to_date_ad'] ?? '') ?: null,
              trim($_POST['responsibilities'] ?? '') ?: null
           ]);
        setFlash('success', 'अनुभव थपियो।');
        header("Location: hrm-employee-view.php?id=$id#experience"); exit;
    }

    if ($a === 'add_family') {
        $db->prepare("INSERT INTO hrm_employee_family
            (employee_id,relation,full_name,contact,occupation,is_nominee,nominee_share,notes)
            VALUES (?,?,?,?,?,?,?,?)")
           ->execute([$id,
              trim($_POST['relation'] ?? ''),
              trim($_POST['full_name'] ?? ''),
              trim($_POST['contact'] ?? '') ?: null,
              trim($_POST['occupation'] ?? '') ?: null,
              !empty($_POST['is_nominee']) ? 1 : 0,
              (float)($_POST['nominee_share'] ?? 0),
              trim($_POST['notes'] ?? '') ?: null
           ]);
        setFlash('success', 'पारिवारिक विवरण थपियो।');
        header("Location: hrm-employee-view.php?id=$id#family"); exit;
    }

    if ($a === 'save_bank') {
        $exists = (int)$db->prepare("SELECT COUNT(*) FROM hrm_employee_bank WHERE employee_id=?")->execute([$id]);
        $cnt = (int)$db->query("SELECT COUNT(*) FROM hrm_employee_bank WHERE employee_id=$id")->fetchColumn();
        $vals = [
            trim($_POST['bank_name'] ?? '') ?: null,
            trim($_POST['branch'] ?? '') ?: null,
            trim($_POST['account_no'] ?? '') ?: null,
            trim($_POST['account_name'] ?? '') ?: null,
            trim($_POST['pf_no'] ?? '') ?: null,
            trim($_POST['cit_no'] ?? '') ?: null,
            trim($_POST['ssf_no'] ?? '') ?: null,
            trim($_POST['insurance_no'] ?? '') ?: null,
            trim($_POST['notes'] ?? '') ?: null,
        ];
        if ($cnt) {
            $vals[] = $id;
            $db->prepare("UPDATE hrm_employee_bank SET bank_name=?,branch=?,account_no=?,account_name=?,pf_no=?,cit_no=?,ssf_no=?,insurance_no=?,notes=? WHERE employee_id=?")
               ->execute($vals);
        } else {
            array_unshift($vals, $id);
            $db->prepare("INSERT INTO hrm_employee_bank (employee_id,bank_name,branch,account_no,account_name,pf_no,cit_no,ssf_no,insurance_no,notes) VALUES (?,?,?,?,?,?,?,?,?,?)")
               ->execute($vals);
        }
        setFlash('success', 'बैंक/PF विवरण सुरक्षित भयो।');
        header("Location: hrm-employee-view.php?id=$id#bank"); exit;
    }

    if ($a === 'add_history') {
        $rel = !empty($_FILES['file']['name']) ? hrmHandleUpload($_FILES['file'], 'history') : null;
        $db->prepare("INSERT INTO hrm_employee_history
            (employee_id,event_type,event_date_bs,event_date_ad,from_designation,to_designation,from_department_id,to_department_id,from_branch_id,to_branch_id,reference_no,description,file_path,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$id,
              $_POST['event_type'] ?? 'other',
              trim($_POST['event_date_bs'] ?? '') ?: null,
              trim($_POST['event_date_ad'] ?? '') ?: null,
              trim($_POST['from_designation'] ?? '') ?: null,
              trim($_POST['to_designation'] ?? '') ?: null,
              (int)($_POST['from_department_id'] ?? 0) ?: null,
              (int)($_POST['to_department_id'] ?? 0) ?: null,
              (int)($_POST['from_branch_id'] ?? 0) ?: null,
              (int)($_POST['to_branch_id'] ?? 0) ?: null,
              trim($_POST['reference_no'] ?? '') ?: null,
              trim($_POST['description'] ?? '') ?: null,
              $rel, $me
           ]);
        setFlash('success', 'सेवा-घटना थपियो।');
        header("Location: hrm-employee-view.php?id=$id#history"); exit;
    }

    if ($a === 'delete_child') {
        $tbl = $_POST['tbl'] ?? '';
        $childId = (int)($_POST['child_id'] ?? 0);
        $allowed = ['hrm_employee_contracts','hrm_employee_documents','hrm_employee_education','hrm_employee_experience','hrm_employee_family','hrm_employee_history'];
        if (in_array($tbl, $allowed, true) && $childId > 0) {
            $db->prepare("DELETE FROM $tbl WHERE id=? AND employee_id=?")->execute([$childId, $id]);
            setFlash('success', 'हटाइयो।');
        }
        header("Location: hrm-employee-view.php?id=$id"); exit;
    }
}

$emp = $db->prepare("SELECT e.*, d.name_np AS dept_name FROM hrm_employees e LEFT JOIN hrm_departments d ON d.id=e.department_id WHERE e.id=?");
$emp->execute([$id]);
$emp = $emp->fetch(PDO::FETCH_ASSOC);
if (!$emp) { setFlash('error','कर्मचारी फेला परेन।'); header('Location: hrm-employees.php'); exit; }

$contracts  = $db->prepare("SELECT * FROM hrm_employee_contracts WHERE employee_id=? ORDER BY id DESC");        $contracts->execute([$id]); $contracts = $contracts->fetchAll(PDO::FETCH_ASSOC);
$documents  = $db->prepare("SELECT * FROM hrm_employee_documents WHERE employee_id=? ORDER BY id DESC");        $documents->execute([$id]); $documents = $documents->fetchAll(PDO::FETCH_ASSOC);
$education  = $db->prepare("SELECT * FROM hrm_employee_education WHERE employee_id=? ORDER BY sort_order, id"); $education->execute([$id]); $education = $education->fetchAll(PDO::FETCH_ASSOC);
$experience = $db->prepare("SELECT * FROM hrm_employee_experience WHERE employee_id=? ORDER BY from_date_ad DESC"); $experience->execute([$id]); $experience = $experience->fetchAll(PDO::FETCH_ASSOC);
$family     = $db->prepare("SELECT * FROM hrm_employee_family WHERE employee_id=? ORDER BY id");                $family->execute([$id]);    $family = $family->fetchAll(PDO::FETCH_ASSOC);
$bank       = $db->prepare("SELECT * FROM hrm_employee_bank WHERE employee_id=?");                              $bank->execute([$id]);      $bank = $bank->fetch(PDO::FETCH_ASSOC) ?: [];
$history    = $db->prepare("SELECT * FROM hrm_employee_history WHERE employee_id=? ORDER BY event_date_ad DESC, id DESC"); $history->execute([$id]); $history = $history->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="admin-content">
  <div class="page-header stf-page-head">
    <div class="d-flex align-items-center gap-3">
      <img src="<?= e(hrmEmployeePhotoUrl($emp['photo'])) ?>" alt="" style="width:64px;height:64px;border-radius:50%;object-fit:cover;background:#eee">
      <div>
        <h1 class="stf-title mb-0"><?= e($emp['full_name_np']) ?> <?= hrmStatusBadge($emp['status']) ?></h1>
        <p class="stf-subtitle mb-0"><code><?= e($emp['employee_code']) ?></code> · <?= e($emp['designation']) ?> · <?= e($emp['dept_name']) ?></p>
      </div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
      <a class="btn-coop" target="_blank" href="hrm-employee-id-card.php?id=<?= (int)$emp['id'] ?>"><i class="fas fa-id-card"></i> Digital ID Card</a>
      <a class="btn-coop btn-outline" href="hrm-employees.php"><i class="fas fa-arrow-left"></i> सूचीमा फर्क</a>
    </div>
  </div>

  <?php if ($f = getFlash()): ?>
    <div class="alert alert-<?= $f['type']==='error'?'danger':'success' ?>"><?= e($f['message']) ?></div>
  <?php endif; ?>

  <ul class="nav nav-tabs mb-3" id="empTabs" role="tablist">
    <?php
    $tabs = [
      ['overview','📋 सारांश'], ['contracts','📄 करार ('.count($contracts).')'],
      ['documents','🪪 कागजात ('.count($documents).')'], ['education','🎓 शिक्षा ('.count($education).')'],
      ['experience','💼 अनुभव ('.count($experience).')'], ['family','👨‍👩‍👧 परिवार ('.count($family).')'],
      ['bank','🏦 बैंक/PF'], ['history','🕘 सेवा-इतिहास ('.count($history).')'],
    ];
    foreach ($tabs as $i=>$t): ?>
      <li class="nav-item"><button class="nav-link <?= $i===0?'active':'' ?>" data-bs-toggle="tab" data-bs-target="#tab-<?= $t[0] ?>" type="button"><?= $t[1] ?></button></li>
    <?php endforeach; ?>
  </ul>

  <div class="tab-content">

    <!-- OVERVIEW -->
    <div class="tab-pane fade show active" id="tab-overview">
      <div class="card-coop p-3">
        <div class="row g-3">
          <?php
          $info = [
            'पूरा नाम (Eng)'=>$emp['full_name_en'], 'लिङ्ग'=>$emp['gender'],
            'जन्म मिति'=> ($emp['dob_bs'] ?: '') . ' / ' . ($emp['dob_ad'] ?: ''),
            'रक्त समूह'=>$emp['blood_group'], 'वैवाहिक स्थिति'=>$emp['marital_status'],
            'मोबाइल'=>$emp['mobile'], 'Email'=>$emp['email'],
            'नागरिकता'=>$emp['citizenship_no'], 'PAN'=>$emp['pan_no'],
            'स्थायी ठेगाना'=> trim(($emp['perm_municipality'] ?? '').' - '.($emp['perm_ward'] ?? '').', '.($emp['perm_district'] ?? ''), ' -,'),
            'नियुक्ति'=> ($emp['join_date_bs'] ?: '') . ' / ' . ($emp['join_date_ad'] ?: ''),
            'सेवा प्रकार'=>$emp['employment_type'], 'तह/श्रेणी'=>trim(($emp['level'] ?? '').' / '.($emp['grade'] ?? ''), ' /'),
            'कैफियत'=>$emp['remarks'],
          ];
          foreach ($info as $k=>$v): ?>
            <div class="col-md-4">
              <div class="text-muted small"><?= e($k) ?></div>
              <div><strong><?= e($v ?: '—') ?></strong></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- CONTRACTS -->
    <div class="tab-pane fade" id="tab-contracts">
      <div class="card-coop p-3 mb-3">
        <h5 class="stf-section-title">नयाँ करार थप्नुहोस्</h5>
        <form method="post" enctype="multipart/form-data" class="row g-2">
          <?= csrfField() ?><input type="hidden" name="action" value="add_contract">
          <div class="col-md-2"><label class="small">करार नं.</label><input class="field-coop" name="contract_no"></div>
          <div class="col-md-2"><label class="small">प्रकार</label>
            <select class="field-coop" name="contract_type">
              <?php foreach (['appointment'=>'नियुक्ति','contract'=>'करार','renewal'=>'नविकरण','promotion'=>'बढुवा','transfer'=>'सरुवा','amendment'=>'संशोधन'] as $k=>$v): ?>
                <option value="<?= $k ?>"><?= $v ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3"><label class="small">पद</label><input class="field-coop" name="designation" value="<?= e($emp['designation']) ?>"></div>
          <div class="col-md-2"><label class="small">सुरु (BS)</label><input class="field-coop" name="start_date_bs"></div>
          <div class="col-md-2"><label class="small">सुरु (AD)</label><input class="field-coop" type="date" name="start_date_ad"></div>
          <div class="col-md-2"><label class="small">अन्त्य (AD)</label><input class="field-coop" type="date" name="end_date_ad"></div>
          <div class="col-md-2"><label class="small">तलब</label><input class="field-coop" type="number" step="0.01" name="basic_salary"></div>
          <div class="col-md-2"><label class="small">भत्ता</label><input class="field-coop" type="number" step="0.01" name="allowance"></div>
          <div class="col-md-3"><label class="small">फाइल</label><input class="field-coop" type="file" name="file" accept=".pdf,.jpg,.png"></div>
          <div class="col-md-3"><label class="small">कैफियत</label><input class="field-coop" name="notes"></div>
          <div class="col-12 text-end"><button class="btn-coop"><i class="fas fa-plus"></i> थप्नुहोस्</button></div>
        </form>
      </div>
      <div class="card-coop">
        <table class="table table-hover mb-0">
          <thead><tr><th>करार नं.</th><th>प्रकार</th><th>पद</th><th>सुरु</th><th>अन्त्य</th><th>तलब</th><th>फाइल</th><th></th></tr></thead>
          <tbody>
          <?php if (!$contracts): ?><tr><td colspan="8" class="text-center text-muted py-3">कुनै करार छैन।</td></tr><?php endif; ?>
          <?php foreach ($contracts as $c): ?>
            <tr>
              <td><code><?= e($c['contract_no']) ?></code></td>
              <td><small><?= e($c['contract_type']) ?></small></td>
              <td><?= e($c['designation']) ?></td>
              <td><?= e($c['start_date_ad']) ?></td>
              <td><?= e($c['end_date_ad'] ?: '—') ?></td>
              <td class="text-end">रू <?= number_format((float)$c['basic_salary'], 2) ?></td>
              <td><?php if ($c['file_path']): ?><a class="btn btn-sm btn-outline-primary" target="_blank" href="../<?= e($c['file_path']) ?>"><i class="fas fa-file-pdf"></i></a><?php endif; ?></td>
              <td class="text-end">
                <form method="post" class="stf-inline-form" onsubmit="return confirm('हटाउने?');">
                  <?= csrfField() ?><input type="hidden" name="action" value="delete_child">
                  <input type="hidden" name="tbl" value="hrm_employee_contracts">
                  <input type="hidden" name="child_id" value="<?= (int)$c['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" aria-label="Delete" title="Delete"><i class="fas fa-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- DOCUMENTS -->
    <div class="tab-pane fade" id="tab-documents">
      <div class="card-coop p-3 mb-3">
        <h5 class="stf-section-title">नयाँ कागजात</h5>
        <form method="post" enctype="multipart/form-data" class="row g-2">
          <?= csrfField() ?><input type="hidden" name="action" value="add_document">
          <div class="col-md-2"><label class="small">प्रकार</label>
            <select class="field-coop" name="doc_type">
              <?php foreach (['citizenship'=>'नागरिकता','pan'=>'PAN','license'=>'सवारी इजाजत','passport'=>'राहदानी','certificate'=>'प्रमाण-पत्र','training'=>'तालिम','medical'=>'मेडिकल','other'=>'अन्य'] as $k=>$v): ?>
                <option value="<?= $k ?>"><?= $v ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3"><label class="small">शीर्षक *</label><input class="field-coop" name="title" required></div>
          <div class="col-md-2"><label class="small">नं.</label><input class="field-coop" name="doc_number"></div>
          <div class="col-md-2"><label class="small">जारीकर्ता</label><input class="field-coop" name="issued_by"></div>
          <div class="col-md-1"><label class="small">जारी (BS)</label><input class="field-coop" name="issued_date_bs"></div>
          <div class="col-md-1"><label class="small">जारी (AD)</label><input class="field-coop" type="date" name="issued_date_ad"></div>
          <div class="col-md-1"><label class="small">म्याद</label><input class="field-coop" type="date" name="expiry_date_ad"></div>
          <div class="col-md-3"><label class="small">फाइल</label><input class="field-coop" type="file" name="file"></div>
          <div class="col-md-9"><label class="small">कैफियत</label><input class="field-coop" name="notes"></div>
          <div class="col-12 text-end"><button class="btn-coop"><i class="fas fa-cloud-arrow-up"></i> अपलोड</button></div>
        </form>
      </div>
      <div class="card-coop">
        <table class="table table-hover mb-0">
          <thead><tr><th>शीर्षक</th><th>प्रकार</th><th>नं.</th><th>जारी</th><th>म्याद</th><th>फाइल</th><th></th></tr></thead>
          <tbody>
          <?php if (!$documents): ?><tr><td colspan="7" class="text-center text-muted py-3">कागजात थपिएको छैन।</td></tr><?php endif; ?>
          <?php foreach ($documents as $d): ?>
            <?php $exp = $d['expiry_date_ad']; $expSoon = $exp && strtotime($exp) <= strtotime('+90 days'); ?>
            <tr>
              <td><strong><?= e($d['title']) ?></strong><br><small class="text-muted"><?= e($d['notes']) ?></small></td>
              <td><small><?= e($d['doc_type']) ?></small></td>
              <td><code><?= e($d['doc_number']) ?></code></td>
              <td><small><?= e($d['issued_date_ad'] ?: $d['issued_date_bs']) ?></small></td>
              <td>
                <?php if ($exp): ?>
                  <span class="badge bg-<?= $expSoon?'warning text-dark':'light text-dark' ?>"><?= e($exp) ?></span>
                <?php else: ?><small class="text-muted">—</small><?php endif; ?>
              </td>
              <td><?php if ($d['file_path']): ?><a class="btn btn-sm btn-outline-primary" target="_blank" href="../<?= e($d['file_path']) ?>"><i class="fas fa-file"></i></a><?php endif; ?></td>
              <td class="text-end">
                <form method="post" class="stf-inline-form" onsubmit="return confirm('हटाउने?');">
                  <?= csrfField() ?><input type="hidden" name="action" value="delete_child">
                  <input type="hidden" name="tbl" value="hrm_employee_documents"><input type="hidden" name="child_id" value="<?= (int)$d['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" aria-label="Delete" title="Delete"><i class="fas fa-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- EDUCATION -->
    <div class="tab-pane fade" id="tab-education">
      <div class="card-coop p-3 mb-3">
        <form method="post" class="row g-2">
          <?= csrfField() ?><input type="hidden" name="action" value="add_education">
          <div class="col-md-2"><label class="small">तह *</label><input class="field-coop" name="level" placeholder="SLC/+2/Bachelor" required></div>
          <div class="col-md-3"><label class="small">बोर्ड/विश्वविद्यालय</label><input class="field-coop" name="board_university"></div>
          <div class="col-md-3"><label class="small">संस्था</label><input class="field-coop" name="institution"></div>
          <div class="col-md-2"><label class="small">मुख्य विषय</label><input class="field-coop" name="major"></div>
          <div class="col-md-1"><label class="small">वर्ष</label><input class="field-coop" name="passed_year"></div>
          <div class="col-md-1"><label class="small">श्रेणी</label><input class="field-coop" name="division_grade"></div>
          <div class="col-12 text-end"><button class="btn-coop"><i class="fas fa-plus"></i> थप</button></div>
        </form>
      </div>
      <div class="card-coop">
        <table class="table table-hover mb-0">
          <thead><tr><th>तह</th><th>बोर्ड</th><th>संस्था</th><th>विषय</th><th>वर्ष</th><th>श्रेणी</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($education as $ed): ?>
            <tr>
              <td><?= e($ed['level']) ?></td>
              <td><?= e($ed['board_university']) ?></td>
              <td><?= e($ed['institution']) ?></td>
              <td><?= e($ed['major']) ?></td>
              <td><?= e($ed['passed_year']) ?></td>
              <td><?= e($ed['division_grade']) ?></td>
              <td class="text-end">
                <form method="post" class="stf-inline-form" onsubmit="return confirm('हटाउने?');">
                  <?= csrfField() ?><input type="hidden" name="action" value="delete_child">
                  <input type="hidden" name="tbl" value="hrm_employee_education"><input type="hidden" name="child_id" value="<?= (int)$ed['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" aria-label="Delete" title="Delete"><i class="fas fa-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- EXPERIENCE -->
    <div class="tab-pane fade" id="tab-experience">
      <div class="card-coop p-3 mb-3">
        <form method="post" class="row g-2">
          <?= csrfField() ?><input type="hidden" name="action" value="add_experience">
          <div class="col-md-4"><label class="small">संस्था *</label><input class="field-coop" name="organization" required></div>
          <div class="col-md-3"><label class="small">पद</label><input class="field-coop" name="designation"></div>
          <div class="col-md-2"><label class="small">देखि</label><input class="field-coop" type="date" name="from_date_ad"></div>
          <div class="col-md-2"><label class="small">सम्म</label><input class="field-coop" type="date" name="to_date_ad"></div>
          <div class="col-md-12"><label class="small">जिम्मेवारी</label><textarea class="field-coop" name="responsibilities" rows="2"></textarea></div>
          <div class="col-12 text-end"><button class="btn-coop"><i class="fas fa-plus"></i> थप</button></div>
        </form>
      </div>
      <div class="card-coop">
        <table class="table table-hover mb-0">
          <thead><tr><th>संस्था</th><th>पद</th><th>देखि</th><th>सम्म</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($experience as $ex): ?>
            <tr>
              <td><strong><?= e($ex['organization']) ?></strong><br><small class="text-muted"><?= e($ex['responsibilities']) ?></small></td>
              <td><?= e($ex['designation']) ?></td>
              <td><?= e($ex['from_date_ad']) ?></td>
              <td><?= e($ex['to_date_ad']) ?></td>
              <td class="text-end">
                <form method="post" class="stf-inline-form" onsubmit="return confirm('हटाउने?');">
                  <?= csrfField() ?><input type="hidden" name="action" value="delete_child">
                  <input type="hidden" name="tbl" value="hrm_employee_experience"><input type="hidden" name="child_id" value="<?= (int)$ex['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" aria-label="Delete" title="Delete"><i class="fas fa-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- FAMILY -->
    <div class="tab-pane fade" id="tab-family">
      <div class="card-coop p-3 mb-3">
        <form method="post" class="row g-2">
          <?= csrfField() ?><input type="hidden" name="action" value="add_family">
          <div class="col-md-2"><label class="small">नाता *</label><input class="field-coop" name="relation" required></div>
          <div class="col-md-3"><label class="small">पूरा नाम *</label><input class="field-coop" name="full_name" required></div>
          <div class="col-md-2"><label class="small">सम्पर्क</label><input class="field-coop" name="contact"></div>
          <div class="col-md-2"><label class="small">पेशा</label><input class="field-coop" name="occupation"></div>
          <div class="col-md-1"><label class="small">Nominee</label>
            <select class="field-coop" name="is_nominee"><option value="0">होइन</option><option value="1">हो</option></select>
          </div>
          <div class="col-md-2"><label class="small">हिस्सा (%)</label><input class="field-coop" type="number" step="0.01" name="nominee_share"></div>
          <div class="col-12 text-end"><button class="btn-coop"><i class="fas fa-plus"></i> थप</button></div>
        </form>
      </div>
      <div class="card-coop">
        <table class="table table-hover mb-0">
          <thead><tr><th>नाता</th><th>नाम</th><th>सम्पर्क</th><th>पेशा</th><th>Nominee</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($family as $fm): ?>
            <tr>
              <td><?= e($fm['relation']) ?></td>
              <td><?= e($fm['full_name']) ?></td>
              <td><?= e($fm['contact']) ?></td>
              <td><?= e($fm['occupation']) ?></td>
              <td><?= $fm['is_nominee'] ? '<span class="badge bg-success">'.((float)$fm['nominee_share']).'%</span>' : '—' ?></td>
              <td class="text-end">
                <form method="post" class="stf-inline-form" onsubmit="return confirm('हटाउने?');">
                  <?= csrfField() ?><input type="hidden" name="action" value="delete_child">
                  <input type="hidden" name="tbl" value="hrm_employee_family"><input type="hidden" name="child_id" value="<?= (int)$fm['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" aria-label="Delete" title="Delete"><i class="fas fa-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <!-- BANK -->
    <div class="tab-pane fade" id="tab-bank">
      <div class="card-coop p-3">
        <form method="post" class="row g-2">
          <?= csrfField() ?><input type="hidden" name="action" value="save_bank">
          <div class="col-md-4"><label class="small">बैंक</label><input class="field-coop" name="bank_name" value="<?= e($bank['bank_name'] ?? '') ?>"></div>
          <div class="col-md-3"><label class="small">शाखा</label><input class="field-coop" name="branch" value="<?= e($bank['branch'] ?? '') ?>"></div>
          <div class="col-md-3"><label class="small">खाता नं.</label><input class="field-coop" name="account_no" value="<?= e($bank['account_no'] ?? '') ?>"></div>
          <div class="col-md-2"><label class="small">खाता नाम</label><input class="field-coop" name="account_name" value="<?= e($bank['account_name'] ?? '') ?>"></div>
          <div class="col-md-3"><label class="small">PF नं.</label><input class="field-coop" name="pf_no" value="<?= e($bank['pf_no'] ?? '') ?>"></div>
          <div class="col-md-3"><label class="small">CIT नं.</label><input class="field-coop" name="cit_no" value="<?= e($bank['cit_no'] ?? '') ?>"></div>
          <div class="col-md-3"><label class="small">SSF नं.</label><input class="field-coop" name="ssf_no" value="<?= e($bank['ssf_no'] ?? '') ?>"></div>
          <div class="col-md-3"><label class="small">बीमा नं.</label><input class="field-coop" name="insurance_no" value="<?= e($bank['insurance_no'] ?? '') ?>"></div>
          <div class="col-md-12"><label class="small">कैफियत</label><input class="field-coop" name="notes" value="<?= e($bank['notes'] ?? '') ?>"></div>
          <div class="col-12 text-end"><button class="btn-coop"><i class="fas fa-save"></i> सुरक्षित</button></div>
        </form>
      </div>
    </div>

    <!-- HISTORY -->
    <div class="tab-pane fade" id="tab-history">
      <div class="card-coop p-3 mb-3">
        <form method="post" enctype="multipart/form-data" class="row g-2">
          <?= csrfField() ?><input type="hidden" name="action" value="add_history">
          <div class="col-md-2"><label class="small">घटना *</label>
            <select class="field-coop" name="event_type" required>
              <?php foreach (['promotion'=>'बढुवा','transfer'=>'सरुवा','confirmation'=>'स्थायी','suspension'=>'निलम्बन','reinstatement'=>'पुनर्बहाली','warning'=>'चेतावनी','award'=>'पुरस्कार','leave'=>'बिदा','resignation'=>'राजीनामा','termination'=>'बर्खास्त','retirement'=>'अवकाश','other'=>'अन्य'] as $k=>$v): ?>
                <option value="<?= $k ?>"><?= $v ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-2"><label class="small">मिति (BS)</label><input class="field-coop" name="event_date_bs"></div>
          <div class="col-md-2"><label class="small">मिति (AD)</label><input class="field-coop" type="date" name="event_date_ad"></div>
          <div class="col-md-3"><label class="small">देखि (पद/विभाग)</label>
            <input class="field-coop mb-1" name="from_designation" placeholder="पुरानो पद">
            <select class="field-coop" name="from_department_id"><option value="0">— पुरानो विभाग —</option>
              <?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name_np']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3"><label class="small">सम्म (पद/विभाग)</label>
            <input class="field-coop mb-1" name="to_designation" placeholder="नयाँ पद">
            <select class="field-coop" name="to_department_id"><option value="0">— नयाँ विभाग —</option>
              <?php foreach ($departments as $d): ?><option value="<?= (int)$d['id'] ?>"><?= e($d['name_np']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3"><label class="small">पत्र नं.</label><input class="field-coop" name="reference_no"></div>
          <div class="col-md-6"><label class="small">विवरण</label><input class="field-coop" name="description"></div>
          <div class="col-md-3"><label class="small">फाइल</label><input class="field-coop" type="file" name="file"></div>
          <div class="col-12 text-end"><button class="btn-coop"><i class="fas fa-plus"></i> थप</button></div>
        </form>
      </div>
      <div class="card-coop p-3">
        <ul class="timeline list-unstyled mb-0">
          <?php if (!$history): ?><li class="text-muted">कुनै सेवा-इतिहास छैन।</li><?php endif; ?>
          <?php foreach ($history as $h): ?>
            <li class="mb-3 pb-3 border-bottom">
              <div class="d-flex justify-content-between">
                <div>
                  <span class="badge bg-primary me-2"><?= e($h['event_type']) ?></span>
                  <strong><?= e($h['to_designation'] ?: '—') ?></strong>
                  <?php if ($h['from_designation']): ?><span class="text-muted">← <?= e($h['from_designation']) ?></span><?php endif; ?>
                </div>
                <small class="text-muted"><?= e($h['event_date_bs'] ?: $h['event_date_ad']) ?></small>
              </div>
              <div class="small text-muted mt-1"><?= e($h['description']) ?>
                <?php if ($h['reference_no']): ?> · पत्र नं: <code><?= e($h['reference_no']) ?></code><?php endif; ?>
                <?php if ($h['file_path']): ?> · <a target="_blank" href="../<?= e($h['file_path']) ?>"><i class="fas fa-paperclip"></i> फाइल</a><?php endif; ?>
              </div>
              <form method="post" class="stf-inline-form mt-1" onsubmit="return confirm('हटाउने?');">
                <?= csrfField() ?><input type="hidden" name="action" value="delete_child">
                <input type="hidden" name="tbl" value="hrm_employee_history"><input type="hidden" name="child_id" value="<?= (int)$h['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" aria-label="Delete" title="Delete"><i class="fas fa-trash"></i></button>
              </form>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>

  </div>
</div>

<script>
// re-open the tab from URL hash
document.addEventListener('DOMContentLoaded', () => {
  const h = location.hash.replace('#','');
  if (!h) return;
  const btn = document.querySelector(`[data-bs-target="#tab-${h}"]`);
  if (btn) new bootstrap.Tab(btn).show();
});
</script>
<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>
