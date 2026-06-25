/**
 * प्रश्नोत्तर व्यवस्थापन — FAQs Management
 * Tab UI: सूची + Add/Edit form (modal popup हटाइएको)
 */
$pageTitle = 'प्रश्नोत्तर व्यवस्थापन';
require_once '../includes/config.php';
if (!isAdminLoggedIn()) redirect(ADMIN_URL . 'index.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken()) {
        $_SESSION['flash'] = ['type' => 'error', 'message' => 'सुरक्षा जाँच असफल। कृपया पुनः प्रयास गर्नुहोस्।'];
        $ref = $_SERVER['HTTP_REFERER'] ?? (ADMIN_URL . 'dashboard.php');
        header('Location: ' . $ref); exit;
    }
}
if (empty($csrfToken)) $csrfToken = generateCSRFToken();

$success = '';
$error   = '';
$db      = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $act = $_POST['action'];
        if ($act === 'add' || $act === 'edit') {
            $question    = clean_text($_POST['question']    ?? '');
            $question_np = clean_text($_POST['question_np'] ?? $question);
            $answer      = clean_text($_POST['answer']      ?? '');
            $answer_np   = clean_text($_POST['answer_np']   ?? $answer);
            $category    = clean_text($_POST['category']    ?? 'general');
            $order       = (int)($_POST['display_order']  ?? 0);
            $is_active   = isset($_POST['is_active']) ? 1 : 0;

            if ($act === 'add') {
                $db->prepare("INSERT INTO faqs (question, question_np, answer, answer_np, category, display_order, is_active) VALUES (?,?,?,?,?,?,?)")
                   ->execute([$question, $question_np, $answer, $answer_np, $category, $order, $is_active]);
                $success = 'प्रश्नोत्तर सफलतापूर्वक थपियो।';
            } else {
                $db->prepare("UPDATE faqs SET question=?, question_np=?, answer=?, answer_np=?, category=?, display_order=?, is_active=? WHERE id=?")
                   ->execute([$question, $question_np, $answer, $answer_np, $category, $order, $is_active, (int)$_POST['id']]);
                $success = 'प्रश्नोत्तर सफलतापूर्वक अपडेट भयो।';
            }
        } elseif ($act === 'delete') {
            $db->prepare("DELETE FROM faqs WHERE id=?")->execute([(int)$_POST['id']]);
            $success = 'प्रश्नोत्तर मेटाइयो।';
        } elseif ($act === 'bulk_status') {
            $bulk = clean_text($_POST['bulk'] ?? '');
            $selected = $_POST['selected_ids'] ?? [];
            $ids = array_values(array_filter(array_map('intval', (array)$selected), fn($v) => $v > 0));
            if (empty($ids) || !in_array($bulk, ['active','inactive'], true)) {
                $error = 'Bulk update का लागि rows छान्नुहोस्।';
            } else {
                $target = $bulk === 'active' ? 1 : 0;
                $ph = implode(',', array_fill(0, count($ids), '?'));
                $st = $db->prepare("UPDATE faqs SET is_active = ? WHERE id IN ($ph)");
                $st->execute(array_merge([$target], $ids));
                $success = 'Bulk status update सफल भयो।';
            }
        }
    } catch (Exception $e) {
        $error = 'त्रुटि भयो। कृपया पछि प्रयास गर्नुहोस्।';
    }
}

$faqs = [];
try {
    $checkTable = $db->query("SHOW TABLES LIKE 'faqs'");
    if ($checkTable->fetch() !== false) {
        $faqs = $db->query("SELECT * FROM faqs ORDER BY display_order, id DESC LIMIT 500")->fetchAll();
    }
} catch (Exception $e) { $faqs = []; }

require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$faqPart = adminPartitionRowsByIsActive($faqs);
$faqsLive = $faqPart['live'];
$faqsArch = $faqPart['archived'];
?>

<?php echo adminPageHeader(
    'प्रश्नोत्तर (FAQs)',
    'fa-circle-question',
    'सदस्यहरूको सामान्य प्रश्न र उत्तरहरू।',
    '<span class="badge admin-stat-badge bg-success-subtle text-success border border-success border-opacity-25 me-2"><i class="fas fa-layer-group me-1"></i>जम्मा: ' . count($faqs) . '</span>'
    . '<span class="badge admin-stat-badge bg-primary-subtle text-primary border border-primary border-opacity-25 me-2"><i class="lucide-icon me-1" aria-hidden="true" data-lucide="check-circle"></i>सक्रिय: ' . count($faqsLive) . '</span>'
    . '<span class="badge admin-stat-badge bg-secondary-subtle text-secondary border border-secondary border-opacity-25"><i class="lucide-icon me-1" aria-hidden="true" data-lucide="archive"></i>अभिलेख: ' . count($faqsArch) . '</span>'
);
?>
<?php echo adminHelpTip('यो पृष्ठबाट Frequently Asked Questions (FAQ) थप्न र अपडेट गर्न सकिन्छ।', ['FAQ थप्न: "+" बटन थिच्नुहोस्।', 'नेपाली र अंग्रेजी दुवैमा लेख्नुहोस् ताकि सबैलाई बुझिओस्।', 'Display Order: सानो number = पहिला देखिन्छ।']); ?>

<?php echo adminAlert('success', $success) . adminAlert('danger', $error); ?>

<ul class="nav nav-tabs admin-nav-tabs mb-0">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#faq-list" id="faq-list-btn" title="सक्रिय / जम्मा">
            <i class="fas fa-list me-2"></i>प्रश्नोत्तर सूची
            <span class="badge bg-success ms-1"><?php echo count($faqsLive); ?> / <?php echo count($faqs); ?></span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#faq-form" id="faq-form-btn">
            <i class="fas fa-plus-circle me-2"></i><span id="faqFormTabLabel">नयाँ थप्नुहोस्</span>
        </button>
    </li>
</ul>

<div class="tab-content">

    <!-- ══ TAB 1: सूची ══ -->
    <div class="tab-pane fade show active" id="faq-list">
        <div class="card admin-table-card" style="border-top-left-radius:0!important;border-top-right-radius:0!important;">

            <!-- खोज बक्स — client-side filter -->
            <div class="admin-search-wrap px-3 py-2 border-bottom bg-light d-flex align-items-center gap-3" style="flex-wrap:wrap">
                <div class="input-group input-group-sm" style="max-width:300px">
                    <span class="input-group-text bg-white border-end-0"><i class="lucide-icon text-muted" aria-hidden="true" data-lucide="search"></i></span>
                    <input type="text" class="form-control border-start-0 admin-table-search" placeholder="नाम, विवरण अनुसार खोज्नुहोस्..." autocomplete="off">
                </div>
                <small class="text-muted search-count"></small>
            </div>
            <div class="card-body p-0">
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="action" value="bulk_status">
                        <div class="px-3 py-2 border-bottom bg-light d-flex justify-content-end gap-2">
                            <button type="submit" name="bulk" value="active" class="btn btn-sm btn-outline-success">Bulk Active</button>
                            <button type="submit" name="bulk" value="inactive" class="btn btn-sm btn-outline-secondary">Bulk Inactive</button>
                        </div>
                    <?php echo adminListSubtabPills('faq-sub', count($faqsLive), count($faqsArch)); ?>
                    <div class="tab-content admin-table-subtab-content">
                    <div class="tab-pane fade show active" id="faq-sub-live" role="tabpanel">
                    <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 coop-table">
                        <thead>
                            <tr>
                                <th width="40" class="text-center"><input type="checkbox" onclick="document.querySelectorAll('#faq-sub-live .faq-select').forEach(c=>c.checked=this.checked)"></th>
                                <th class="ps-3" width="40">#</th>
                                <th>प्रश्न</th>
                                <th width="110" class="text-center">वर्ग</th>
                                <th width="90" class="text-center">स्थिति</th>
                                <th width="140" class="text-center">कार्य</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($faqs)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">
                                <i class="fas fa-question-circle fa-3x mb-2 d-block opacity-25"></i>
                                कुनै प्रश्नोत्तर छैन।
                            </td></tr>
                            <?php elseif (empty($faqsLive)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">
                                <i class="lucide-icon fa-3x mb-2 d-block opacity-25 text-success" aria-hidden="true" data-lucide="check-circle"></i>
                                सक्रिय प्रश्नोत्तर छैन। अभिलेख हेर्नुहोस्।
                            </td></tr>
                            <?php endif; ?>
                            <?php foreach ($faqsLive as $i => $f): ?>
                            <tr>
                                <td class="text-center"><input type="checkbox" class="faq-select" name="selected_ids[]" value="<?php echo (int)$f['id']; ?>"></td>
                                <td class="ps-3 text-muted"><?php echo $i+1; ?></td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($f['question_np'] ?: $f['question']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars(mb_substr($f['answer_np'] ?: $f['answer'], 0, 60)); ?>…</small>
                                </td>
                                <td class="text-center"><span class="badge bg-info text-white"><?php echo htmlspecialchars($f['category']); ?></span></td>
                                <td class="text-center"><span class="badge bg-<?php echo $f['is_active'] ? 'success' : 'secondary'; ?>"><?php echo $f['is_active'] ? 'सक्रिय' : 'निष्क्रिय'; ?></span></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-primary me-1 btn-edit-faq"
                                            data-id="<?php echo $f['id']; ?>"
                                            data-question="<?php echo htmlspecialchars($f['question_np'] ?: $f['question'], ENT_QUOTES); ?>"
                                            data-answer="<?php echo htmlspecialchars($f['answer_np'] ?: $f['answer'], ENT_QUOTES); ?>"
                                            data-question-en="<?php echo htmlspecialchars($f['question'], ENT_QUOTES); ?>"
                                            data-answer-en="<?php echo htmlspecialchars($f['answer'], ENT_QUOTES); ?>"
                                            data-category="<?php echo htmlspecialchars($f['category'], ENT_QUOTES); ?>"
                                            data-order="<?php echo $f['display_order']; ?>"
                                            data-active="<?php echo $f['is_active']; ?>"
                                            title="सम्पादन">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('के तपाईं यो मेटाउन निश्चित हुनुहुन्छ?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $f['id']; ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="मेटाउनुहोस्"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    </div>
                    <div class="tab-pane fade" id="faq-sub-arch" role="tabpanel">
                    <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 coop-table">
                        <thead>
                            <tr>
                                <th width="40" class="text-center"><input type="checkbox" onclick="document.querySelectorAll('#faq-sub-arch .faq-select').forEach(c=>c.checked=this.checked)"></th>
                                <th class="ps-3" width="40">#</th>
                                <th>प्रश्न</th>
                                <th width="110" class="text-center">वर्ग</th>
                                <th width="90" class="text-center">स्थिति</th>
                                <th width="140" class="text-center">कार्य</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($faqsArch)): ?>
                            <tr><td colspan="6" class="text-center py-5 text-muted">
                                <i class="lucide-icon fa-3x mb-2 d-block opacity-25" aria-hidden="true" data-lucide="folder-open"></i>
                                अभिलेखमा कुनै प्रश्नोत्तर छैन।
                            </td></tr>
                            <?php endif; ?>
                            <?php foreach ($faqsArch as $i => $f): ?>
                            <tr>
                                <td class="text-center"><input type="checkbox" class="faq-select" name="selected_ids[]" value="<?php echo (int)$f['id']; ?>"></td>
                                <td class="ps-3 text-muted"><?php echo $i+1; ?></td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($f['question_np'] ?: $f['question']); ?></div>
                                    <small class="text-muted"><?php echo htmlspecialchars(mb_substr($f['answer_np'] ?: $f['answer'], 0, 60)); ?>…</small>
                                </td>
                                <td class="text-center"><span class="badge bg-info text-white"><?php echo htmlspecialchars($f['category']); ?></span></td>
                                <td class="text-center"><span class="badge bg-<?php echo $f['is_active'] ? 'success' : 'secondary'; ?>"><?php echo $f['is_active'] ? 'सक्रिय' : 'निष्क्रिय'; ?></span></td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-primary me-1 btn-edit-faq"
                                            data-id="<?php echo $f['id']; ?>"
                                            data-question="<?php echo htmlspecialchars($f['question_np'] ?: $f['question'], ENT_QUOTES); ?>"
                                            data-answer="<?php echo htmlspecialchars($f['answer_np'] ?: $f['answer'], ENT_QUOTES); ?>"
                                            data-question-en="<?php echo htmlspecialchars($f['question'], ENT_QUOTES); ?>"
                                            data-answer-en="<?php echo htmlspecialchars($f['answer'], ENT_QUOTES); ?>"
                                            data-category="<?php echo htmlspecialchars($f['category'], ENT_QUOTES); ?>"
                                            data-order="<?php echo $f['display_order']; ?>"
                                            data-active="<?php echo $f['is_active']; ?>"
                                            title="सम्पादन">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <form method="POST" style="display:inline" onsubmit="return confirm('के तपाईं यो मेटाउन निश्चित हुनुहुन्छ?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo $f['id']; ?>">
                                        <button class="btn btn-sm btn-outline-danger" title="मेटाउनुहोस्"><i class="fas fa-trash"></i></button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    </div>
                    </div>
                    </form>
            </div>
        </div>
    </div>

    <!-- ══ TAB 2: Add / Edit Form ══ -->
    <div class="tab-pane fade" id="faq-form">
        <div class="card" style="border-top-left-radius:0!important;border-top-right-radius:0!important;">
            <div class="card-header d-flex justify-content-between align-items-center" style="background:linear-gradient(135deg,var(--primary-color),var(--primary-light));color:#fff;">
                <h5 class="mb-0 fw-bold" id="faqFormTitle">
                    <i class="fas fa-plus-circle me-2"></i>नयाँ प्रश्नोत्तर थप्नुहोस्
                </h5>
                <button type="button" class="btn btn-light btn-sm" id="btnCancelFaq">
                    <i class="lucide-icon me-1" aria-hidden="true" data-lucide="arrow-left"></i>सूचीमा फर्कनुहोस्
                </button>
            </div>
            <div class="card-body p-4">
                <form method="POST" id="faqForm" class="needs-validation" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" id="faqf_action" value="add">
                    <input type="hidden" name="id" id="faqf_id" value="">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">प्रश्न (नेपाली) <span class="text-danger">*</span></label>
                            <input type="text" name="question_np" id="faqf_question" class="form-control admin-fancy-input" required placeholder="प्रश्न नेपालीमा">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">Question (English)</label>
                            <input type="text" name="question" id="faqf_question_en" class="form-control admin-fancy-input" placeholder="Question in English">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">उत्तर (नेपाली) <span class="text-danger">*</span></label>
                            <textarea name="answer_np" id="faqf_answer" class="form-control admin-fancy-input" rows="4" required placeholder="उत्तर नेपालीमा..."></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold text-success">Answer (English)</label>
                            <textarea name="answer" id="faqf_answer_en" class="form-control admin-fancy-input" rows="4" placeholder="Answer in English..."></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-success">वर्ग</label>
                            <select name="category" id="faqf_category" class="form-select admin-fancy-input">
                                <option value="general">General</option>
                                <option value="saving">Saving / बचत</option>
                                <option value="loan">Loan / ऋण</option>
                                <option value="membership">Membership</option>
                                <option value="digital">Digital Services</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold text-success">प्रदर्शन क्रम</label>
                            <input type="number" name="display_order" id="faqf_order" class="form-control admin-fancy-input" value="0" min="0">
                        </div>
                        <div class="col-md-4 d-flex align-items-end pb-1">
                            <div class="form-check form-switch fs-5">
                                <input class="form-check-input" type="checkbox" name="is_active" id="faqf_active" checked>
                                <label class="form-check-label fw-semibold" for="faqf_active">सक्रिय</label>
                            </div>
                        </div>
                    </div>

                    <hr class="my-4">
                    <div class="d-flex gap-3">
                        <button type="submit" id="faqf_submit" class="btn btn-success px-5 fw-semibold">
                            <i class="fas fa-plus-circle me-2"></i>थप्नुहोस्
                        </button>
                        <button type="button" id="faqf_cancel2" class="btn btn-outline-secondary px-4">
                            <i class="fas fa-times me-1"></i>रद्द
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

</div>



/* ── Client-side search (खोज बक्स) ── */
    var listBtn = document.getElementById('faq-list-btn');
    var formBtn = document.getElementById('faq-form-btn');

    function switchToList() { adminSwitchTab(listBtn, formBtn); }
    function switchToForm() { adminSwitchTab(formBtn, listBtn); }

    function clearForm() {
        document.getElementById('faqf_action').value      = 'add';
        document.getElementById('faqf_id').value          = '';
        document.getElementById('faqf_question').value    = '';
        document.getElementById('faqf_question_en').value = '';
        document.getElementById('faqf_answer').value      = '';
        document.getElementById('faqf_answer_en').value   = '';
        document.getElementById('faqf_order').value       = '0';
        document.getElementById('faqf_active').checked    = true;
        document.getElementById('faqf_category').selectedIndex = 0;
        document.getElementById('faqf_submit').innerHTML = '<i class="fas fa-plus-circle me-2"></i>थप्नुहोस्';
        document.getElementById('faqFormTitle').innerHTML = '<i class="fas fa-plus-circle me-2"></i>नयाँ प्रश्नोत्तर थप्नुहोस्';
        document.getElementById('faqFormTabLabel').textContent = 'नयाँ थप्नुहोस्';
    }

    document.getElementById('btnAddFaq')?.addEventListener('click', function() { clearForm(); switchToForm(); });

    ['btnCancelFaq','faqf_cancel2'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.addEventListener('click', function() { clearForm(); switchToList(); });
    });

    document.querySelectorAll('.btn-edit-faq').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var d = this.dataset;
            document.getElementById('faqf_action').value      = 'edit';
            document.getElementById('faqf_id').value          = d.id;
            document.getElementById('faqf_question').value    = d.question;
            document.getElementById('faqf_question_en').value = d.questionEn || '';
            document.getElementById('faqf_answer').value      = d.answer;
            document.getElementById('faqf_answer_en').value   = d.answerEn || '';
            document.getElementById('faqf_order').value       = d.order;
            document.getElementById('faqf_active').checked    = d.active === '1';
            var sel = document.getElementById('faqf_category');
            for (var i=0; i<sel.options.length; i++) {
                if (sel.options[i].value === d.category) { sel.selectedIndex = i; break; }
            }
            document.getElementById('faqf_submit').innerHTML = '<i class="lucide-icon me-2" aria-hidden="true" data-lucide="save"></i>अपडेट गर्नुहोस्';
            document.getElementById('faqFormTitle').innerHTML = '<i class="fas fa-edit me-2"></i>प्रश्नोत्तर सम्पादन';
            document.getElementById('faqFormTabLabel').textContent = 'सम्पादन';
            switchToForm();
        });
    });
});
</script>





/* ── Client-side search (खोज बक्स) ── */
(function() {
    var inp = document.querySelector('#faq-list .admin-table-search');
    var cnt = document.querySelector('#faq-list .search-count');
    if (!inp) return;
    
    function filter() {
        var q = inp.value.toLowerCase();
        var pane = document.querySelector('#faq-list .tab-pane.active');
        if (!pane) return;
        var rows = pane.querySelectorAll('tbody tr');
        var vis = 0;
        rows.forEach(function(r) {
            var show = r.textContent.toLowerCase().includes(q);
            r.style.display = show ? '' : 'none';
            if (show) vis++;
        });
        if (cnt) cnt.textContent = vis + ' / ' + rows.length;
    }
    
    inp.addEventListener('input', filter);
    document.addEventListener('shown.bs.tab', function(e) {
        if (e.target.id === 'faq-sub-live' || e.target.id === 'faq-sub-arch') filter();
    });
    filter();
})();


<?php require_once 'includes/admin-footer.php'; ?>
