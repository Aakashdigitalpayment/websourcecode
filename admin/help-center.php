<?php
$pageTitle = 'सहायता केन्द्र व्यवस्थापन (Help Center)';
require_once '../includes/config.php';

if (!isAdminLoggedIn()) {
    redirect(ADMIN_URL . 'index.php');
}

/* ── Early CSRF Protection ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCSRFToken()) {
    setFlash('error', 'सुरक्षा जाँच असफल। कृपया पुनः प्रयास गर्नुहोस्।');
    redirect('help-center.php');
}

$db     = getDB();
$action = $_GET['action'] ?? 'list';
if (!in_array($action, ['list', 'edit', 'add'], true)) {
    $action = 'list';
}
$editId = (int)($_GET['id'] ?? 0);

/* ── Table existence check ── */
$tableExists = false;
try {
    $chk = $db->query("SHOW TABLES LIKE 'chatbot_faqs'");
    if ($chk && $chk->fetch() !== false) $tableExists = true;
} catch (Exception $e) {}

/* ══════════════════════════════════════
   POST HANDLERS
══════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();
    $postAction = $_POST['action'] ?? '';
    try {
        if ($postAction === 'add') {
            $db->prepare("INSERT INTO chatbot_faqs
                (question, question_en, answer, answer_en, category, keywords, display_order, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
               ->execute([
                    clean_text($_POST['question']      ?? ''),
                    clean_text($_POST['question_en']   ?? ''),
                    clean_text($_POST['answer']        ?? ''),
                    clean_text($_POST['answer_en']     ?? ''),
                    clean_text($_POST['category']      ?? 'general'),
                    clean_text($_POST['keywords']      ?? ''),
                    (int)($_POST['display_order']    ?? 0),
                    isset($_POST['is_active']) ? 1 : 0,
               ]);
            setFlash('success', 'सहायता प्रश्न सफलतापूर्वक थपियो।');
            redirect('help-center.php');
        }

        if ($postAction === 'edit') {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare("UPDATE chatbot_faqs SET
                question=?, question_en=?, answer=?, answer_en=?,
                category=?, keywords=?, display_order=?, is_active=?
                WHERE id=?")
               ->execute([
                    clean_text($_POST['question']      ?? ''),
                    clean_text($_POST['question_en']   ?? ''),
                    clean_text($_POST['answer']        ?? ''),
                    clean_text($_POST['answer_en']     ?? ''),
                    clean_text($_POST['category']      ?? 'general'),
                    clean_text($_POST['keywords']      ?? ''),
                    (int)($_POST['display_order']    ?? 0),
                    isset($_POST['is_active']) ? 1 : 0,
                    $id,
               ]);
            setFlash('success', 'सहायता प्रश्न सफलतापूर्वक अपडेट भयो।');
            redirect('help-center.php');
        }

        if ($postAction === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $db->prepare("DELETE FROM chatbot_faqs WHERE id=?")->execute([$id]);
            setFlash('success', 'सहायता प्रश्न मेटाइयो।');
            redirect('help-center.php');
        }

    } catch (Exception $e) {
        setFlash('error', 'त्रुटि भयो: ' . $e->getMessage());
        redirect('help-center.php');
    }
}

/* ══════════════════════════════════════
   EDIT — pre-load record
══════════════════════════════════════ */
$editItem = null;
if ($action === 'edit' && $editId > 0 && $tableExists) {
    try {
        $s = $db->prepare("SELECT * FROM chatbot_faqs WHERE id=?");
        $s->execute([$editId]);
        $editItem = $s->fetch();
        if (!$editItem) { setFlash('error', 'प्रश्न फेला परेन।'); redirect('help-center.php'); }
    } catch (Exception $e) { redirect('help-center.php'); }
}

/* ══════════════════════════════════════
   LIST — stat counts + search/filter
══════════════════════════════════════ */
$helpItems    = [];
$totalCount   = $activeCount = $inactiveCount = 0;
$faqCategories  = ['general', 'interest', 'membership', 'loan', 'service'];
$categoryFilter = trim((string)($_GET['category'] ?? ''));
if ($categoryFilter !== '' && !in_array($categoryFilter, $faqCategories, true)) {
    $categoryFilter = '';
}
$search = mb_substr(trim((string)($_GET['search'] ?? '')), 0, 200, 'UTF-8');

if ($action === 'list' && $tableExists) {
    try {
        /* counts */
        $totalCount    = (int)$db->query("SELECT COUNT(*)    FROM chatbot_faqs")->fetchColumn();
        $activeCount   = (int)$db->query("SELECT COUNT(*)    FROM chatbot_faqs WHERE is_active=1")->fetchColumn();
        $inactiveCount = $totalCount - $activeCount;

        /* filtered list */
        $where  = '1=1'; $params = [];
        if ($categoryFilter) { $where .= ' AND category = ?'; $params[] = $categoryFilter; }
        if ($search !== '') {
            $where .= ' AND (question LIKE ? OR question_en LIKE ? OR answer LIKE ? OR keywords LIKE ?)';
            $t = "%$search%"; $params = array_merge($params, [$t,$t,$t,$t]);
        }
        $stmt = $db->prepare("SELECT * FROM chatbot_faqs WHERE $where ORDER BY display_order ASC, id DESC");
        $stmt->execute($params);
        $helpItems = $stmt->fetchAll();
    } catch (Exception $e) { $helpItems = []; }
}

require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
$flash = getFlash();
?>

<?php
/* ── Page header button changes per action ── */
if ($action === 'add' || $action === 'edit') {
    echo adminPageHeader(
        $action === 'add' ? 'नयाँ सहायता प्रश्न थप्नुहोस्' : 'सहायता प्रश्न सम्पादन',
        'fa-headset',
        'च्याटबट FAQ — ' . ($action === 'add' ? 'नयाँ प्रश्न' : 'प्रश्न अपडेट'),
        adminBackBtn('help-center.php')
    );
} else {
    echo adminPageHeader(
        'सहायता केन्द्र व्यवस्थापन',
        'fa-headset',
        'च्याटबट FAQ प्रश्नहरू — थप्नुहोस्, सम्पादन गर्नुहोस् र व्यवस्थापन गर्नुहोस्।',
        '<a href="?action=add" class="btn btn-primary"><i class="fas fa-plus me-1"></i>नयाँ प्रश्न थप्नुहोस्</a>'
        . ' <a href="help-guide.php" class="btn btn-outline-success"><i class="fas fa-book-open me-1"></i>Quick Guide</a>'
        . ' ' . adminStatLink('?is_active=0', 'warning', 'निष्क्रिय', $inactiveCount)
        . ' ' . adminStatLink('help-center.php', 'secondary', 'जम्मा', $totalCount)
    );
}
?>

<?php if (!empty($flash)): ?>
<?php echo adminAlert($flash['type'] === 'success' ? 'success' : 'danger', $flash['message']); ?>
<?php endif; ?>

<?php if (!$tableExists): ?>
<div class="alert alert-danger">
    <i class="fas fa-exclamation-triangle me-2"></i>
    chatbot_faqs टेबल छैन। कृपया <a href="run-migration.php">यहाँ क्लिक गरेर</a> database migration चलाउनुहोस्।
</div>
<?php endif; ?>

<?php /* ══════════════════════════════════════
          ADD FORM
   ══════════════════════════════════════ */ ?>
<?php if ($action === 'add' || ($action === 'edit' && $editItem)): ?>
<div class="card admin-table-card">
    <div class="card-header gradient-card-header">
        <h5 class="mb-0">
            <i class="fas fa-<?php echo ($action === 'edit') ? 'edit' : 'plus-circle'; ?> me-2"></i>
            <?php echo ($action === 'edit') ? 'सहायता प्रश्न सम्पादन गर्नुहोस्' : 'नयाँ सहायता प्रश्न थप्नुहोस्'; ?>
        </h5>
    </div>
    <div class="card-body">
        <form method="POST" action="help-center.php">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="<?php echo ($action === 'edit') ? 'edit' : 'add'; ?>">
            <?php if ($action === 'edit' && $editItem): ?>
            <input type="hidden" name="id" value="<?php echo (int)$editItem['id']; ?>">
            <?php endif; ?>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">प्रश्न (नेपाली) <span class="text-danger">*</span></label>
                    <input type="text" name="question" class="form-control" required
                           value="<?php echo htmlspecialchars($editItem['question'] ?? ''); ?>"
                           placeholder="कार्यालय समय कति हो?">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Question (English)</label>
                    <input type="text" name="question_en" class="form-control"
                           value="<?php echo htmlspecialchars($editItem['question_en'] ?? ''); ?>"
                           placeholder="What are office hours?">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">उत्तर (नेपाली) <span class="text-danger">*</span></label>
                    <textarea name="answer" class="form-control" rows="4" required
                              placeholder="नेपालीमा उत्तर लेख्नुहोस्..."><?php echo htmlspecialchars($editItem['answer'] ?? ''); ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Answer (English)</label>
                    <textarea name="answer_en" class="form-control" rows="4"
                              placeholder="Write answer in English..."><?php echo htmlspecialchars($editItem['answer_en'] ?? ''); ?></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">वर्ग</label>
                    <select name="category" class="form-select">
                        <?php foreach (['general'=>'सामान्य','interest'=>'ब्याज दर','membership'=>'सदस्यता','loan'=>'ऋण','service'=>'सेवा'] as $val=>$lbl): ?>
                        <option value="<?php echo $val; ?>" <?php echo (($editItem['category'] ?? 'general') === $val) ? 'selected' : ''; ?>>
                            <?php echo $lbl; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">खोज कुञ्जी शब्दहरू</label>
                    <input type="text" name="keywords" class="form-control"
                           value="<?php echo htmlspecialchars($editItem['keywords'] ?? ''); ?>"
                           placeholder="समय,time,office,कार्यालय">
                    <small class="text-muted">अल्पविराम (,) ले विभाजित गर्नुहोस्</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">क्रम नम्बर</label>
                    <input type="number" name="display_order" class="form-control" value="<?php echo (int)($editItem['display_order'] ?? 0); ?>" min="0">
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input type="checkbox" name="is_active" id="add_is_active" class="form-check-input" <?php echo (($editItem['is_active'] ?? 1) ? 'checked' : ''); ?>>
                        <label class="form-check-label" for="add_is_active">सक्रिय</label>
                    </div>
                </div>
                <div class="col-12 border-top pt-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-1"></i><?php echo ($action === 'edit') ? 'अपडेट गर्नुहोस्' : 'सेभ गर्नुहोस्'; ?>
                    </button>
                    <a href="help-center.php" class="btn btn-secondary">
                        <i class="fas fa-times me-1"></i>रद्द
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<?php /* ══════════════════════════════════════
          EDIT FORM
   ══════════════════════════════════════ */ ?>
<?php elseif (false && $action === 'edit' && $editItem): ?>
<div class="card admin-table-card">
    <div class="card-header gradient-card-header">
        <h5 class="mb-0"><i class="fas fa-edit me-2"></i>सहायता प्रश्न सम्पादन गर्नुहोस्</h5>
    </div>
    <div class="card-body">
        <form method="POST" action="help-center.php">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" value="<?php echo (int)$editItem['id']; ?>">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">प्रश्न (नेपाली) <span class="text-danger">*</span></label>
                    <input type="text" name="question" class="form-control" required
                           value="<?php echo htmlspecialchars($editItem['question'] ?? ''); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Question (English)</label>
                    <input type="text" name="question_en" class="form-control"
                           value="<?php echo htmlspecialchars($editItem['question_en'] ?? ''); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">उत्तर (नेपाली) <span class="text-danger">*</span></label>
                    <textarea name="answer" class="form-control" rows="5" required
                    ><?php echo htmlspecialchars($editItem['answer'] ?? ''); ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Answer (English)</label>
                    <textarea name="answer_en" class="form-control" rows="5"
                    ><?php echo htmlspecialchars($editItem['answer_en'] ?? ''); ?></textarea>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">वर्ग</label>
                    <select name="category" class="form-select">
                        <?php foreach (['general'=>'सामान्य','interest'=>'ब्याज दर','membership'=>'सदस्यता','loan'=>'ऋण','service'=>'सेवा'] as $val=>$lbl): ?>
                        <option value="<?php echo $val; ?>" <?php echo ($editItem['category'] ?? '') === $val ? 'selected' : ''; ?>>
                            <?php echo $lbl; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">खोज कुञ्जी शब्दहरू</label>
                    <input type="text" name="keywords" class="form-control"
                           value="<?php echo htmlspecialchars($editItem['keywords'] ?? ''); ?>">
                    <small class="text-muted">अल्पविराम (,) ले विभाजित गर्नुहोस्</small>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">क्रम नम्बर</label>
                    <input type="number" name="display_order" class="form-control" min="0"
                           value="<?php echo (int)($editItem['display_order'] ?? 0); ?>">
                </div>
                <div class="col-12">
                    <div class="form-check">
                        <input type="checkbox" name="is_active" id="edit_is_active" class="form-check-input"
                               <?php echo ($editItem['is_active'] ?? 0) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="edit_is_active">सक्रिय</label>
                    </div>
                </div>
                <div class="col-12 border-top pt-3 d-flex gap-2">
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-save me-1"></i>अपडेट गर्नुहोस्
                    </button>
                    <a href="help-center.php" class="btn btn-secondary">
                        <i class="fas fa-times me-1"></i>रद्द
                    </a>
                </div>
            </div>
        </form>
    </div>
</div>

<?php /* ══════════════════════════════════════
          LIST VIEW
   ══════════════════════════════════════ */ ?>
<?php else: ?>

<?php if ($tableExists): ?>
<!-- Stat Mini Row -->
<div class="stat-mini-row no-print">
    <a href="help-center.php" class="stat-mini <?php echo !$categoryFilter&&!$search?'active-filter':''; ?>">
        <div class="sm-icon ic-total"><i class="fas fa-headset"></i></div>
        <div class="sm-val"><?php echo $totalCount; ?></div>
        <div class="sm-lbl">जम्मा प्रश्न</div>
    </a>
    <a href="help-center.php" class="stat-mini <?php echo $categoryFilter===''&&!$search?'':''; ?>">
        <div class="sm-icon ic-approved"><i class="fas fa-toggle-on"></i></div>
        <div class="sm-val"><?php echo $activeCount; ?></div>
        <div class="sm-lbl">सक्रिय</div>
    </a>
    <a href="help-center.php" class="stat-mini">
        <div class="sm-icon ic-rejected"><i class="fas fa-toggle-off"></i></div>
        <div class="sm-val"><?php echo $inactiveCount; ?></div>
        <div class="sm-lbl">निष्क्रिय</div>
    </a>
</div>

<!-- Filter Bar -->
<div class="adm-filter-bar no-print">
    <form method="GET" class="adm-filter-form">
        <div class="afb-group">
            <label>वर्ग</label>
            <select name="category" class="afb-select">
                <option value="">सबै वर्ग</option>
                <?php foreach (['general'=>'सामान्य','interest'=>'ब्याज दर','membership'=>'सदस्यता','loan'=>'ऋण','service'=>'सेवा'] as $val=>$lbl): ?>
                <option value="<?php echo $val; ?>" <?php echo $categoryFilter===$val?'selected':''; ?>><?php echo $lbl; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="afb-group afb-search">
            <label>खोज्नुहोस्</label>
            <div class="afb-search-wrap">
                <i class="fas fa-search afb-search-icon"></i>
                <input type="text" name="search" class="afb-input"
                       value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="प्रश्न, उत्तर, keyword...">
            </div>
        </div>
        <button type="submit" class="afb-btn-search"><i class="fas fa-search me-1"></i>खोज</button>
        <?php if ($categoryFilter || $search): ?>
        <a href="help-center.php" class="afb-btn-reset"><i class="fas fa-times me-1"></i>रिसेट</a>
        <?php endif; ?>
    </form>
</div>

<!-- FAQ Table -->
<div class="app-table">
    <div class="tbl-header-bar">
        <span class="tbl-title"><i class="fas fa-question-circle me-2"></i>FAQ सूची</span>
        <span class="tbl-count"><?php echo count($helpItems); ?> प्रश्न</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="hc-col-order">क्रम</th>
                        <th>प्रश्न</th>
                        <th>वर्ग</th>
                        <th>स्थिति</th>
                        <th class="hc-col-actions">कार्य</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($helpItems as $item): ?>
                    <tr>
                        <td class="text-center fw-bold"><?php echo (int)($item['display_order'] ?? 0); ?></td>
                        <td>
                            <strong><?php echo htmlspecialchars(mb_substr($item['question'] ?? '', 0, 70)); ?></strong><br>
                            <small class="text-muted"><?php echo htmlspecialchars(mb_substr($item['question_en'] ?? '', 0, 70)); ?></small>
                        </td>
                        <td>
                            <?php
                            $catLabels = ['general'=>'सामान्य','interest'=>'ब्याज दर','membership'=>'सदस्यता','loan'=>'ऋण','service'=>'सेवा'];
                            $catColors = ['general'=>'secondary','interest'=>'info','membership'=>'primary','loan'=>'warning','service'=>'success'];
                            $cat = $item['category'] ?? 'general';
                            ?>
                            <span class="badge bg-<?php echo $catColors[$cat] ?? 'secondary'; ?>">
                                <?php echo $catLabels[$cat] ?? $cat; ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($item['is_active']): ?>
                                <span class="badge bg-success">सक्रिय</span>
                            <?php else: ?>
                                <span class="badge bg-danger">निष्क्रिय</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="?action=edit&id=<?php echo (int)$item['id']; ?>"
                               class="btn btn-sm btn-primary" title="सम्पादन">
                                    <i class="fas fa-edit"></i>
                            </a>
                            <form method="POST" class="svc-inline-form"
                                  onsubmit="return confirm('के तपाईं यो प्रश्न मेटाउन निश्चित हुनुहुन्छ?');">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-danger" title="मेटाउनुहोस्">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($helpItems)): ?>
                    <?php echo adminEmptyRow(5, 'fa-question-circle', 'कुनै प्रश्न फेला परेन'); ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php require_once 'includes/admin-footer.php'; ?>
