<?php
$pageTitle = 'Update Checklist';
$currentPage = 'update-checklist';
require_once 'includes/admin-header.php';
?>

<div class="container-fluid py-4">
    <div class="row mb-4">
        <div class="col">
            <div class="d-flex align-items-center gap-3">
                <div class="bg-info bg-opacity-10 p-3 rounded-3">
                    <i class="fas fa-list-check fa-2x text-info"></i>
                </div>
                <div>
                    <h2 class="mb-0 fw-bold">Update Checklist</h2>
                    <p class="text-muted mb-0">Website update गर्नु अघि र पछि follow गर्ने सजिलो steps।</p>
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-warning d-flex align-items-start gap-2">
        <i class="fas fa-triangle-exclamation mt-1"></i>
        <div>
            <strong>Important:</strong> नयाँ files upload गर्नु अघि database backup download गर्नुहोस्।
            Backup बिना update गर्दा data recover गर्न गाह्रो हुन सक्छ।
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Before Update</h5>
                </div>
                <div class="card-body">
                    <div class="form-check mb-3">
                        <input class="form-check-input checklist-item" type="checkbox" id="before1">
                        <label class="form-check-label" for="before1">Admin → Backup / Restore बाट database backup download गर्नुहोस्।</label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input checklist-item" type="checkbox" id="before2">
                        <label class="form-check-label" for="before2">cPanel File Manager मा पुरानो files को backup/copy राख्नुहोस्।</label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input checklist-item" type="checkbox" id="before3">
                        <label class="form-check-label" for="before3">नयाँ package भित्रको `public_html` folder खोल्नुहोस्।</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input checklist-item" type="checkbox" id="before4">
                        <label class="form-check-label" for="before4">`includes/database.local.php` (वा पुरानो `database.php`) मा DB details मिलेको छ कि हेर्नुहोस्।</label>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fas fa-upload me-2"></i>During Update</h5>
                </div>
                <div class="card-body">
                    <div class="form-check mb-3">
                        <input class="form-check-input checklist-item" type="checkbox" id="during1">
                        <label class="form-check-label" for="during1">`public_html` भित्रका files/folders मात्र cPanel public_html मा upload गर्नुहोस्।</label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input checklist-item" type="checkbox" id="during2">
                        <label class="form-check-label" for="during2">`public_html` folder आफैं nested भएर upload नगर्नुहोस्।</label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input checklist-item" type="checkbox" id="during3">
                        <label class="form-check-label" for="during3">नयाँ database changes चाहिँ `database/install.sql` बाट import/run गर्नुहोस्।</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input checklist-item" type="checkbox" id="during4">
                        <label class="form-check-label" for="during4">Upload overwrite confirmation carefully accept गर्नुहोस्।</label>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-dark text-white">
                    <h5 class="mb-0"><i class="fas fa-magnifying-glass me-2"></i>After Update</h5>
                </div>
                <div class="card-body">
                    <div class="form-check mb-3">
                        <input class="form-check-input checklist-item" type="checkbox" id="after1">
                        <label class="form-check-label" for="after1">Homepage खोल्दा PHP code text जसरी देखिएको छैन भनेर check गर्नुहोस्।</label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input checklist-item" type="checkbox" id="after2">
                        <label class="form-check-label" for="after2">Admin login खुल्छ कि check गर्नुहोस्।</label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input checklist-item" type="checkbox" id="after3">
                        <label class="form-check-label" for="after3">Admin → System Info मा database connection ठीक छ कि हेर्नुहोस्।</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input checklist-item" type="checkbox" id="after4">
                        <label class="form-check-label" for="after4">Contact/form submit गरेर basic test गर्नुहोस्।</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mt-4">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <h5 class="fw-bold mb-1">Progress</h5>
                    <p class="text-muted mb-0"><span id="doneCount">0</span> / <span id="totalCount">12</span> steps completed</p>
                </div>
                <div class="progress flex-grow-1" style="height: 12px; min-width: 220px;">
                    <div id="checklistProgress" class="progress-bar bg-success" style="width: 0%"></div>
                </div>
                <button type="button" class="btn btn-outline-secondary" onclick="resetChecklist()">
                    <i class="fas fa-rotate-left me-1"></i> Reset
                </button>
            </div>
        </div>
    </div>
</div>

<script>
const checklistKey = 'admin_update_checklist_state';
const items = document.querySelectorAll('.checklist-item');

function loadChecklist() {
    const saved = JSON.parse(localStorage.getItem(checklistKey) || '{}');
    items.forEach(item => {
        item.checked = saved[item.id] === true;
        item.addEventListener('change', saveChecklist);
    });
    updateProgress();
}

function saveChecklist() {
    const data = {};
    items.forEach(item => data[item.id] = item.checked);
    localStorage.setItem(checklistKey, JSON.stringify(data));
    updateProgress();
}

function updateProgress() {
    const total = items.length;
    const done = Array.from(items).filter(item => item.checked).length;
    document.getElementById('doneCount').textContent = done;
    document.getElementById('totalCount').textContent = total;
    document.getElementById('checklistProgress').style.width = total ? ((done / total) * 100) + '%' : '0%';
}

function resetChecklist() {
    if (!confirm('Checklist reset गर्ने?')) return;
    localStorage.removeItem(checklistKey);
    items.forEach(item => item.checked = false);
    updateProgress();
}

loadChecklist();
</script>

<?php require_once 'includes/admin-footer.php'; ?>
