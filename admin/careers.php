<?php
/**
 * रोजगारी व्यवस्थापन — Careers Management
 * Tab UI: सूची + Add/Edit form (modal popup हटाइएको)
 */
$__t = static function (string $np, string $en): string {
    $lang = (string)($_SESSION['admin_lang'] ?? $_SESSION['lang'] ?? 'np');
    return strtolower($lang) === 'en' ? $en : $np;
};
$pageTitle = $__t('रोजगारी व्यवस्थापन', 'Career Management');
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$db = getDB();

/* CSRF सुरक्षा: POST अनुरोध प्रमाणित गर्नुहोस् */
checkCSRF();

/* extra column migration — v2: safeColumnExists() बाट SQL-injection safe */
try {
    $extra = ['vacancies'=>"INT DEFAULT 1",'min_qualification'=>"VARCHAR(200) NULL",'experience_required'=>"VARCHAR(200) NULL",'salary_range'=>"VARCHAR(200) NULL",'allow_online_apply'=>"TINYINT(1) DEFAULT 1"];
    foreach ($extra as $col => $def) {
        if (!safeColumnExists('careers', $col)) {
            /* $col whitelist check पास भएको — definition hardcoded array बाट */
            $db->exec("ALTER TABLE `careers` ADD COLUMN `{$col}` {$def}");
        }
    }
} catch (Throwable $e) { error_log("[careers.php] " . $e->getMessage()); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add' || $action === 'edit') {
            $id          = $_POST['id'] ?? null;
            $title       = clean_text($_POST['title']          ?? '');
            $title_np    = clean_text($_POST['title_np']       ?? '');
            $dept        = clean_text($_POST['department']     ?? '');
            $loc         = clean_text($_POST['location']       ?? '');
            $jtype       = clean_text($_POST['job_type']       ?? 'full-time');
            $desc        = $_POST['description']             ?? '';
            $desc_np     = $_POST['description_np']         ?? '';
            $req         = $_POST['requirements']            ?? '';
            $deadline    = $_POST['deadline']                ?? null;
            $vacancies   = max(1,(int)($_POST['vacancies']   ?? 1));
            $min_qual    = clean_text($_POST['min_qualification']   ?? '');
            $exp_req     = clean_text($_POST['experience_required'] ?? '');
            $salary      = clean_text($_POST['salary_range']        ?? '');
            $allow_apply = isset($_POST['allow_online_apply']) ? 1 : 0;
            $is_active   = isset($_POST['is_active']) ? 1 : 0;
            $attachment  = $_POST['existing_attachment'] ?? '';

            if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
                $up = uploadFile($_FILES['attachment'], 'careers');
                if ($up['success']) $attachment = $up['path'];
            }

            if ($action === 'add') {
                $db->prepare("INSERT INTO careers (title, title_np, department, location, job_type, description, description_np, requirements, deadline, attachment, vacancies, min_qualification, experience_required, salary_range, allow_online_apply, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                   ->execute([$title, $title_np, $dept, $loc, $jtype, $desc, $desc_np, $req, $deadline, $attachment, $vacancies, $min_qual, $exp_req, $salary, $allow_apply, $is_active]);
                setFlash('success', $__t('रोजगारी थपियो।', 'Career added.'));
            } else {
                $db->prepare("UPDATE careers SET title=?, title_np=?, department=?, location=?, job_type=?, description=?, description_np=?, requirements=?, deadline=?, attachment=?, vacancies=?, min_qualification=?, experience_required=?, salary_range=?, allow_online_apply=?, is_active=? WHERE id=?")
                   ->execute([$title, $title_np, $dept, $loc, $jtype, $desc, $desc_np, $req, $deadline, $attachment, $vacancies, $min_qual, $exp_req, $salary, $allow_apply, $is_active, $id]);
                setFlash('success', $__t('रोजगारी अपडेट भयो।', 'Career updated.'));
            }
        } elseif ($action === 'delete') {
            $db->prepare("DELETE FROM careers WHERE id=?")->execute([$_POST['id']]);
            setFlash('success', $__t('रोजगारी मेटाइयो।', 'Career deleted.'));
        }
    } catch (Exception $e) {
        setFlash('error', $__t('त्रुटि भयो। कृपया पछि प्रयास गर्नुहोस्।', 'An error occurred. Please try again later.'));
    }
    redirect('careers.php');
}

$isReadExists = false;
try {
    $cr = $db->query("SHOW COLUMNS FROM job_applications LIKE 'is_read'");
    $isReadExists = $cr && $cr->fetch() !== false;
} catch (Throwable $e) { error_log("[careers.php] " . $e->getMessage()); }

try {
    if ($isReadExists) {
        $careers = $db->query("SELECT c.*, (SELECT COUNT(*) FROM job_applications WHERE career_id=c.id) as application_count, (SELECT COUNT(*) FROM job_applications WHERE career_id=c.id AND is_read=0) as unread_count FROM careers c ORDER BY c.created_at DESC")->fetchAll();
    } else {
        $careers = $db->query("SELECT c.*, (SELECT COUNT(*) FROM job_applications WHERE career_id=c.id) as application_count, 0 as unread_count FROM careers c ORDER BY c.created_at DESC")->fetchAll();
    }
} catch (Exception $e) { $careers = []; }

/** सूची उप-ट्याब: सक्रिय = is_active र म्याद नसकेको (उही strtotime तर्क जस्तो पङ्क्तिमा) */
function career_admin_is_live(array $c): bool
{
    if (empty($c['is_active'])) {
        return false;
    }
    $d = trim((string)($c['deadline'] ?? ''));
    if ($d === '') {
        return true;
    }
    $ts = strtotime($d);
    if ($ts === false) {
        return true;
    }
    return $ts >= time();
}

$careersLive = [];
$careersArchived = [];
foreach ($careers as $c) {
    if (career_admin_is_live($c)) {
        $careersLive[] = $c;
    } else {
        $careersArchived[] = $c;
    }
}

/**
 * @param array<int,array<string,mixed>> $list
 */
function careers_admin_render_rows(array $list): void
{
    // Define translation function inside this scope
    $__t = static function (string $np, string $en): string {
        $lang = (string)($_SESSION['admin_lang'] ?? $_SESSION['lang'] ?? 'np');
        return strtolower($lang) === 'en' ? $en : $np;
    };
    foreach ($list as $c) {
        ?>
                            <tr>
                                <td class="ps-3">
                                    <div class="fw-semibold"><?php echo htmlspecialchars($c['title_np'] ?: $c['title']); ?></div>
                                    <small class="badge bg-light text-dark border"><?php echo htmlspecialchars($c['job_type'] ?? 'full-time'); ?></small>
                                    <?php if (!empty($c['salary_range'])): ?><small class="text-muted ms-1"><?php echo htmlspecialchars($c['salary_range']); ?></small><?php endif; ?>
                                </td>
                                <td class="text-center"><small><?php echo htmlspecialchars($c['department'] ?? '—'); ?></small></td>
                                <td class="text-center"><span class="badge bg-info text-white"><?php echo (int)($c['vacancies'] ?? 1); ?> <?php echo $__t('जना', 'posts'); ?></span></td>
                                <td class="text-center">
                                    <small class="<?php echo ($c['deadline'] && strtotime($c['deadline']) < time()) ? 'text-danger fw-semibold' : ''; ?>">
                                        <?php echo htmlspecialchars($c['deadline'] ?? '—'); ?>
                                        <?php if ($c['deadline'] && strtotime($c['deadline']) < time()): ?><br><span class="badge bg-danger"><?php echo $__t('समाप्त', 'Expired'); ?></span><?php endif; ?>
                                    </small>
                                </td>
                                <td class="text-center">
                                    <a href="job-applications.php?career_id=<?php echo $c['id']; ?>" class="text-decoration-none">
                                        <span class="badge bg-primary"><?php echo $c['application_count'] ?? 0; ?></span>
                                        <?php if (($c['unread_count'] ?? 0) > 0): ?><span class="badge bg-danger ms-1"><?php echo $c['unread_count']; ?> <?php echo $__t('नयाँ', 'new'); ?></span><?php endif; ?>
                                    </a>
                                </td>
                                <td class="text-center"><span class="badge bg-<?php echo $c['is_active'] ? 'success' : 'secondary'; ?>"><?php echo $c['is_active'] ? $__t('सक्रिय', 'Active') : $__t('निष्क्रिय', 'Inactive'); ?></span></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-primary me-1 btn-edit-career"
                                            data-id="<?php echo $c['id']; ?>"
                                            data-title="<?php echo htmlspecialchars($c['title'], ENT_QUOTES); ?>"
                                            data-title-np="<?php echo htmlspecialchars($c['title_np'] ?? '', ENT_QUOTES); ?>"
                                            data-dept="<?php echo htmlspecialchars($c['department'] ?? '', ENT_QUOTES); ?>"
                                            data-loc="<?php echo htmlspecialchars($c['location'] ?? '', ENT_QUOTES); ?>"
                                            data-jtype="<?php echo htmlspecialchars($c['job_type'] ?? 'full-time', ENT_QUOTES); ?>"
                                            data-desc="<?php echo htmlspecialchars($c['description'] ?? '', ENT_QUOTES); ?>"
                                            data-desc-np="<?php echo htmlspecialchars($c['description_np'] ?? '', ENT_QUOTES); ?>"
                                            data-req="<?php echo htmlspecialchars($c['requirements'] ?? '', ENT_QUOTES); ?>"
                                            data-deadline="<?php echo htmlspecialchars($c['deadline'] ?? '', ENT_QUOTES); ?>"
                                            data-vacancies="<?php echo (int)($c['vacancies'] ?? 1); ?>"
                                            data-qual="<?php echo htmlspecialchars($c['min_qualification'] ?? '', ENT_QUOTES); ?>"
                                            data-exp="<?php echo htmlspecialchars($c['experience_required'] ?? '', ENT_QUOTES); ?>"
                                            data-salary="<?php echo htmlspecialchars($c['salary_range'] ?? '', ENT_QUOTES); ?>"
                                            data-allow-apply="<?php echo $c['allow_online_apply'] ?? 1; ?>"
                                            data-active="<?php echo $c['is_active']; ?>"
                                            data-attachment="<?php echo htmlspecialchars($c['attachment'] ?? '', ENT_QUOTES); ?>"
                                            title="<?php echo $__t('सम्पादन', 'Edit'); ?>">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('<?php echo addslashes($__t('के तपाईं यो रोजगारी मेटाउन निश्चित हुनुहुन्छ?', 'Are you sure you want to delete this career item?')); ?>')">
    <?php echo csrfField(); ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $c['id']; ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="<?php echo $__t('मेटाउनुहोस्', 'Delete'); ?>"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
        <?php
    }
}

$flash = getFlash();
?>

<?php echo adminPageHeader(
    $__t('रोजगारी व्यवस्थापन', 'Career Management'),
    'fa-briefcase',
    $__t('खाली पदहरू र रोजगारी सूचनाहरू।', 'Vacancies and career notices.'),
    '<span class="badge admin-stat-badge bg-success-subtle text-success border border-success border-opacity-25 me-2"><i class="fas fa-layer-group me-1"></i>' . $__t('जम्मा', 'Total') . ': ' . count($careers) . ' ' . $__t('पद', 'positions') . '</span>'
    . '<span class="badge admin-stat-badge bg-primary-subtle text-primary border border-primary border-opacity-25 me-2"><i class="fas fa-check-circle me-1"></i>' . $__t('सक्रिय', 'Active') . ': ' . count($careersLive) . '</span>'
    . '<span class="badge admin-stat-badge bg-secondary-subtle text-secondary border border-secondary border-opacity-25"><i class="fas fa-archive me-1"></i>' . $__t('अभिलेख', 'Archived') . ': ' . count($careersArchived) . '</span>'
); ?>

<?php if (!empty($flash)) { echo adminAlert($flash['type'] === 'success' ? 'success' : 'danger', $flash['message']); } ?>

<ul class="nav nav-tabs admin-nav-tabs mb-0">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#career-list" id="career-list-btn" title="<?php echo $__t('सक्रिय पद / जम्मा पद', 'Active positions / total positions'); ?>">
            <i class="fas fa-list me-2"></i><?php echo $__t('रोजगारी सूची', 'Career List'); ?>
            <span class="badge bg-success ms-1"><?php echo count($careersLive); ?> / <?php echo count($careers); ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#career-form" id="career-form-btn">
            <i class="fas fa-plus-circle me-2"></i><span id="careerFormTabLabel"><?php echo $__t('नयाँ थप्नुहोस्', 'Add New'); ?></span>
        </button>
    </li>
</ul>

<div class="tab-content">

    <!-- ══ TAB 1: सूची ══ -->
    <div class="tab-pane fade show active" id="career-list">
        <div class="card admin-table-card" style="border-top-left-radius:0!important;border-top-right-radius:0!important;">

            <!-- खोज बक्स — client-side filter -->
            <div class="admin-search-wrap px-3 py-2 border-bottom bg-light d-flex align-items-center gap-3" style="flex-wrap:wrap">
                <div class="input-group input-group-sm" style="max-width:300px">
                    <span class="input-group-text bg-white border-end-0"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" class="form-control border-start-0 career-list-search" placeholder="<?php echo $__t('नाम, विवरण अनुसार खोज्नुहोस्...', 'Search by title or details...'); ?>" autocomplete="off" aria-label="<?php echo $__t('रोजगारी खोज', 'Search careers'); ?>">
                </div>
                <small class="text-muted search-count"></small>
            </div>
            <ul class="nav nav-pills admin-inner-tabstrip flex-wrap gap-2 px-3 py-2 mx-3 mt-2 mb-2" id="career-subtabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active py-2" id="career-sub-live-btn" data-bs-toggle="tab" data-bs-target="#careers-sub-live" type="button" role="tab" aria-controls="careers-sub-live" aria-selected="true">
                        <i class="fas fa-bolt me-1"></i><?php echo $__t('सक्रिय पद', 'Active Positions'); ?>
                        <span class="badge bg-success ms-1"><?php echo count($careersLive); ?></span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link py-2" id="career-sub-arch-btn" data-bs-toggle="tab" data-bs-target="#careers-sub-arch" type="button" role="tab" aria-controls="careers-sub-arch" aria-selected="false">
                        <i class="fas fa-archive me-1"></i><?php echo $__t('अभिलेख', 'Archived'); ?>
                        <span class="badge bg-secondary ms-1"><?php echo count($careersArchived); ?></span>
                    </button>
                </li>
            </ul>
            <p class="px-3 pt-2 mb-0 small text-muted"><i class="fas fa-info-circle me-1"></i><?php echo $__t('अभिलेखमा म्याद सकिएका वा निष्क्रिय पदहरू देखिन्छन्।', 'Archived tab shows expired or inactive positions.'); ?></p>
            <div class="tab-content card-body p-0" id="career-subtabs-content">
                <div class="tab-pane fade show active" id="careers-sub-live" role="tabpanel" aria-labelledby="career-sub-live-btn">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 career-sub-table">
                            <thead>
                                <tr>
                                    <th class="ps-3">पद / शीर्षक</th>
                                    <th class="text-center" width="110">विभाग</th>
                                    <th class="text-center" width="80">रिक्त</th>
                                    <th class="text-center" width="110">म्याद</th>
                                    <th class="text-center" width="90">आवेदन</th>
                                    <th class="text-center" width="90">स्थिति</th>
                                    <th class="text-center" width="140">कार्य</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($careersLive) && empty($careers)): ?>
                                <tr><td colspan="7" class="text-center py-5 text-muted">
                                    <i class="fas fa-briefcase fa-3x mb-2 d-block opacity-25"></i>
                                    कुनै रोजगारी छैन।
                                </td></tr>
                                <?php elseif (empty($careersLive)): ?>
                                <tr><td colspan="7" class="text-center py-5 text-muted">
                                    <i class="fas fa-check-circle fa-3x mb-2 d-block opacity-25 text-success"></i>
                                    हाल सक्रिय पद छैन। अभिलेख हेर्नुहोस्।
                                </td></tr>
                                <?php else: careers_admin_render_rows($careersLive); endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="tab-pane fade" id="careers-sub-arch" role="tabpanel" aria-labelledby="career-sub-arch-btn">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 career-sub-table">
                            <thead>
                                <tr>
                                    <th class="ps-3">पद / शीर्षक</th>
                                    <th class="text-center" width="110">विभाग</th>
                                    <th class="text-center" width="80">रिक्त</th>
                                    <th class="text-center" width="110">म्याद</th>
                                    <th class="text-center" width="90">आवेदन</th>
                                    <th class="text-center" width="90">स्थिति</th>
                                    <th class="text-center" width="140">कार्य</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($careersArchived)): ?>
                                <tr><td colspan="7" class="text-center py-5 text-muted">
                                    <i class="fas fa-folder-open fa-3x mb-2 d-block opacity-25"></i>
                                    अभिलेखमा कुनै पद छैन।
                                </td></tr>
                                <?php else: careers_admin_render_rows($careersArchived); endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ TAB 2: Add / Edit Form ══ -->
    <div class="tab-pane fade" id="career-form">
        <div class="card" style="border-top-left-radius:0!important;border-top-right-radius:0!important;">
            <div class="card-header d-flex justify-content-between align-items-center" style="background:linear-gradient(135deg,var(--primary-color),var(--primary-light));color:#fff;">
                <h5 class="mb-0 fw-bold" id="careerFormTitle">
                    <i class="fas fa-plus-circle me-2"></i><?php echo $__t('नयाँ रोजगारी थप्नुहोस्', 'Add New Career'); ?>
                </h5>
                <button type="button" class="btn btn-light btn-sm" id="btnCancelCareer">
                    <i class="fas fa-arrow-left me-1"></i><?php echo $__t('सूचीमा फर्कनुहोस्', 'Back to list'); ?>
                </button>
            </div>
            <div class="card-body p-4">
                <form method="POST" enctype="multipart/form-data" id="careerForm" class="needs-validation" novalidate>
    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" id="crf_action" value="add">
                    <input type="hidden" name="id" id="crf_id" value="">
                    <input type="hidden" name="existing_attachment" id="crf_att" value="">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success"><?php echo $__t('शीर्षक (नेपाली)', 'Job Title (Nepali)'); ?> <span class="text-danger">*</span></label>
                            <input type="text" name="title_np" id="crf_title_np" class="form-control admin-fancy-input" required placeholder="<?php echo $__t('पदको नाम नेपालीमा', 'Position title in Nepali'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">Job Title (English)</label>
                            <input type="text" name="title" id="crf_title" class="form-control admin-fancy-input" placeholder="Position title in English">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-success"><?php echo $__t('विभाग', 'Department'); ?></label>
                            <input type="text" name="department" id="crf_dept" class="form-control admin-fancy-input" placeholder="Loans / IT / Admin...">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-success"><?php echo $__t('स्थान', 'Location'); ?></label>
                            <input type="text" name="location" id="crf_loc" class="form-control admin-fancy-input" placeholder="Head Office">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-success"><?php echo $__t('कामको प्रकार', 'Job Type'); ?></label>
                            <select name="job_type" id="crf_jtype" class="form-select admin-fancy-input">
                                <option value="full-time">Full Time</option>
                                <option value="part-time">Part Time</option>
                                <option value="contract">Contract</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold text-success"><?php echo $__t('रिक्त पद संख्या', 'Number of Vacancies'); ?></label>
                            <input type="number" name="vacancies" id="crf_vac" class="form-control admin-fancy-input" value="1" min="1">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold text-success"><?php echo $__t('न्यूनतम योग्यता', 'Minimum Qualification'); ?></label>
                            <input type="text" name="min_qualification" id="crf_qual" class="form-control admin-fancy-input" placeholder="+2 / Bachelor">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold text-success"><?php echo $__t('अनुभव', 'Experience'); ?></label>
                            <input type="text" name="experience_required" id="crf_exp" class="form-control admin-fancy-input" placeholder="२ वर्ष">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold text-success"><?php echo $__t('तलब दायरा', 'Salary Range'); ?></label>
                            <input type="text" name="salary_range" id="crf_salary" class="form-control admin-fancy-input" placeholder="<?php echo $__t('रु. ३०,०००–४०,०००', 'NPR 30,000-40,000'); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">Deadline (मिति बि.सं.)</label>
                            <div class="input-group">
                                <input type="text" name="deadline" id="crf_deadline" class="form-control admin-fancy-input nepali-datepicker" placeholder="२०८२-०१-३०">
                                <span class="input-group-text bg-success text-white"><i class="fas fa-calendar-alt"></i></span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">संलग्नक (PDF/DOC)
                                <small class="text-muted fw-normal" id="crf_att_note"></small>
                            </label>
                            <input type="file" name="attachment" class="form-control admin-fancy-input" accept=".pdf,.doc,.docx">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold text-success">विवरण (नेपाली)</label>
                            <textarea name="description_np" id="crf_desc_np" class="form-control admin-fancy-input" rows="3" placeholder="रोजगारीको विवरण नेपालीमा..."></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold text-success">Requirements / आवश्यकताहरू</label>
                            <textarea name="requirements" id="crf_req" class="form-control admin-fancy-input" rows="3" placeholder="Required skills, qualifications..."></textarea>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch fs-5">
                                <input class="form-check-input" type="checkbox" name="allow_online_apply" id="crf_allow" checked>
                                <label class="form-check-label fw-semibold" for="crf_allow"><?php echo $__t('अनलाइन आवेदन खुला', 'Online Application Open'); ?></label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch fs-5">
                                <input class="form-check-input" type="checkbox" name="is_active" id="crf_active" checked>
                                <label class="form-check-label fw-semibold" for="crf_active"><?php echo $__t('सक्रिय', 'Active'); ?></label>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">
                    <div class="d-flex gap-3">
                        <button type="submit" id="crf_submit" class="btn btn-success px-5 fw-semibold">
                            <i class="fas fa-plus-circle me-2"></i><?php echo $__t('थप्नुहोस्', 'Add'); ?>
                        </button>
                        <button type="button" id="crf_cancel2" class="btn btn-outline-secondary px-4">
                            <i class="fas fa-times me-1"></i><?php echo $__t('रद्द', 'Cancel'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var careerI18n = {
        addBtn: <?php echo json_encode('<i class="fas fa-plus-circle me-2"></i>' . $__t('थप्नुहोस्', 'Add')); ?>,
        editBtn: <?php echo json_encode('<i class="fas fa-save me-2"></i>' . $__t('अपडेट गर्नुहोस्', 'Update')); ?>,
        addTitle: <?php echo json_encode('<i class="fas fa-plus-circle me-2"></i>' . $__t('नयाँ रोजगारी थप्नुहोस्', 'Add New Career')); ?>,
        editTitle: <?php echo json_encode('<i class="fas fa-edit me-2"></i>' . $__t('रोजगारी सम्पादन', 'Edit Career')); ?>,
        addTab: <?php echo json_encode($__t('नयाँ थप्नुहोस्', 'Add New')); ?>,
        editTab: <?php echo json_encode($__t('सम्पादन', 'Edit')); ?>,
        attachmentKeep: <?php echo json_encode($__t(' — नयाँ फाइल नचुने भने पुरानै रहन्छ', ' - keep empty to retain current file')); ?>
    };

    var listBtn = document.getElementById('career-list-btn');
    var formBtn = document.getElementById('career-form-btn');

    function switchToList() { adminSwitchTab(listBtn, formBtn); }
    function switchToForm() { adminSwitchTab(formBtn, listBtn); }

    function clearForm() {
        document.getElementById('crf_action').value   = 'add';
        document.getElementById('crf_id').value       = '';
        document.getElementById('crf_att').value      = '';
        document.getElementById('crf_title').value    = '';
        document.getElementById('crf_title_np').value = '';
        document.getElementById('crf_dept').value     = '';
        document.getElementById('crf_loc').value      = '';
        document.getElementById('crf_vac').value      = '1';
        document.getElementById('crf_qual').value     = '';
        document.getElementById('crf_exp').value      = '';
        document.getElementById('crf_salary').value   = '';
        document.getElementById('crf_deadline').value = '';
        document.getElementById('crf_desc_np').value  = '';
        document.getElementById('crf_req').value      = '';
        document.getElementById('crf_allow').checked  = true;
        document.getElementById('crf_active').checked = true;
        document.getElementById('crf_att_note').textContent = '';
        document.getElementById('crf_jtype').selectedIndex  = 0;
        document.getElementById('crf_submit').innerHTML = careerI18n.addBtn;
        document.getElementById('careerFormTitle').innerHTML = careerI18n.addTitle;
        document.getElementById('careerFormTabLabel').textContent = careerI18n.addTab;
    }

    document.getElementById('btnAddCareer')?.addEventListener('click', function() { clearForm(); switchToForm(); });

    ['btnCancelCareer','crf_cancel2'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('click', function() { clearForm(); switchToList(); });
    });

    /* उप-ट्याब: खोज केवल खुला टेबलको पङ्क्तिमा लागू */
    (function () {
        var inp = document.querySelector('#career-list .career-list-search');
        var subContent = document.getElementById('career-subtabs-content');
        if (!inp || !subContent) return;
        var wrap = inp.closest('.admin-search-wrap');
        var countEl = wrap ? wrap.querySelector('.search-count') : null;
        function activeTbody() {
            var pane = subContent.querySelector('.tab-pane.active');
            return pane ? pane.querySelector('tbody') : null;
        }
        function runCareerListSearch() {
            var val = inp.value.toLowerCase().trim();
            var tbody = activeTbody();
            if (!tbody) return;
            var rows = tbody.querySelectorAll('tr');
            var shown = 0;
            rows.forEach(function (row) {
                var cells = row.querySelectorAll('td');
                var isPlaceholder = cells.length === 1 && cells[0].getAttribute('colspan');
                if (isPlaceholder) {
                    row.style.display = val ? 'none' : '';
                    return;
                }
                var match = !val || row.textContent.toLowerCase().includes(val);
                row.style.display = match ? '' : 'none';
                if (match) shown++;
            });
            if (countEl) {
                var total = 0;
                rows.forEach(function (row) {
                    var cells = row.querySelectorAll('td');
                    if (!(cells.length === 1 && cells[0].getAttribute('colspan'))) total++;
                });
                countEl.textContent = shown + ' / ' + total;
            }
        }
        inp.addEventListener('input', runCareerListSearch);
        document.querySelectorAll('#career-subtabs [data-bs-toggle="tab"]').forEach(function (btn) {
            btn.addEventListener('shown.bs.tab', runCareerListSearch);
        });
        runCareerListSearch();
    })();

    document.querySelectorAll('.btn-edit-career').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var d = this.dataset;
            document.getElementById('crf_action').value   = 'edit';
            document.getElementById('crf_id').value       = d.id;
            document.getElementById('crf_title').value    = d.title     || '';
            document.getElementById('crf_title_np').value = d.titleNp   || '';
            document.getElementById('crf_dept').value     = d.dept      || '';
            document.getElementById('crf_loc').value      = d.loc       || '';
            document.getElementById('crf_vac').value      = d.vacancies || 1;
            document.getElementById('crf_qual').value     = d.qual      || '';
            document.getElementById('crf_exp').value      = d.exp       || '';
            document.getElementById('crf_salary').value   = d.salary    || '';
            document.getElementById('crf_deadline').value = d.deadline  || '';
            document.getElementById('crf_desc_np').value  = d.descNp   || '';
            document.getElementById('crf_req').value      = d.req       || '';
            document.getElementById('crf_att').value      = d.attachment || '';
            document.getElementById('crf_allow').checked  = d.allowApply === '1';
            document.getElementById('crf_active').checked = d.active === '1';
            document.getElementById('crf_att_note').textContent = d.attachment ? careerI18n.attachmentKeep : '';
            var sel = document.getElementById('crf_jtype');
            for (var i = 0; i < sel.options.length; i++) {
                if (sel.options[i].value === d.jtype) { sel.selectedIndex = i; break; }
            }
            document.getElementById('crf_submit').innerHTML = careerI18n.editBtn;
            document.getElementById('careerFormTitle').innerHTML = careerI18n.editTitle;
            document.getElementById('careerFormTabLabel').textContent = careerI18n.editTab;
            switchToForm();
        });
    });
});
</script>

<?php require_once 'includes/admin-footer.php'; ?>
