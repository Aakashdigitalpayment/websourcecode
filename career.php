<?php
require_once 'includes/config.php';
require_once 'includes/ensure-tables.php';
ensurePublicTables();
$pageTitle = isEnglish() ? 'Career Opportunities' : 'रोजगारीका अवसरहरू';
require_once 'includes/header.php';
$L = getLangStrings();

try {
    $db   = getDB();
    $jobs = $db->query("SELECT * FROM careers WHERE is_active = 1 ORDER BY created_at DESC LIMIT 20")->fetchAll();
} catch (Exception $e) {
    $jobs = [];
}

$openCount   = 0;
$closedCount = 0;
$deptSet     = [];
foreach ($jobs as $j) {
    $passed = !empty($j['deadline']) && strtotime($j['deadline']) < strtotime('today');
    if ($passed) $closedCount++; else $openCount++;
    if (!empty($j['department'])) $deptSet[$j['department']] = true;
}
$totalDepts = count($deptSet);
?>

<!-- Page Banner -->
<section class="page-banner page-banner-modern">
    <div class="container">
        <div class="banner-content-modern">
            <h1 class="page-title-modern"><?php echo isEnglish() ? 'Career Opportunities' : 'रोजगारीका अवसरहरू'; ?></h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb breadcrumb-modern">
                    <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>" class="breadcrumb-link-modern"><?php echo $L['home']; ?></a></li>
                    <li class="breadcrumb-item active"><?php echo isEnglish() ? 'Career' : 'क्यारियर'; ?></li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<div class="cr-hero">
    <div class="container">
        <div class="cr-hero-inner">
            <div class="cr-hero-text">
                <h2><?php echo isEnglish() ? 'Join Our Team' : 'हाम्रो टोलीमा सामेल हुनुहोस्'; ?></h2>
                <p><?php echo isEnglish()
                    ? 'Build your career with आकाश सहकारी — a trusted cooperative family.'
                    : 'आकाश सहकारीसँग आफ्नो क्यारियर निर्माण गर्नुहोस् — एक विश्वसनीय सहकारी परिवार।'; ?>
                </p>
            </div>
            <div class="cr-stats">
                <div class="cr-stat-box">
                    <div class="cr-stat-num"><?php echo $openCount; ?></div>
                    <div class="cr-stat-lbl"><?php echo isEnglish() ? 'Open' : 'खुला पद'; ?></div>
                </div>
                <?php if ($closedCount > 0): ?>
                <div class="cr-stat-box">
                    <div class="cr-stat-num red"><?php echo $closedCount; ?></div>
                    <div class="cr-stat-lbl"><?php echo isEnglish() ? 'Closed' : 'बन्द भएका'; ?></div>
                </div>
                <?php endif; ?>
                <?php if ($totalDepts > 0): ?>
                <div class="cr-stat-box">
                    <div class="cr-stat-num"><?php echo $totalDepts; ?></div>
                    <div class="cr-stat-lbl"><?php echo isEnglish() ? 'Depts' : 'विभागहरू'; ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Main Content -->
<section class="cr-layout">
<div class="container">
<div class="row g-4">

<!-- ── Main Column ── -->
<div class="col-lg-8 cr-main">

    <?php if (!empty($jobs)): ?>

    <!-- Filter Bar -->
    <div class="cr-filterbar" data-aos="fade-up">
        <div class="cr-search-row">
            <div class="cr-search-field">
                <i class="fas fa-search"></i>
                <input type="text" id="crSearch"
                       placeholder="<?php echo isEnglish() ? 'Search by title, department...' : 'पद, विभाग अनुसार खोज्नुहोस्...'; ?>"
                       oninput="crFilter()">
            </div>
        </div>
        <div class="cr-filter-chips">
            <div class="cr-chip active" data-filter="all" onclick="crChip(this)">
                <i class="fas fa-th-large"></i>
                <?php echo isEnglish() ? 'All' : 'सबै'; ?>
                <span class="cr-chip-count"><?php echo count($jobs); ?></span>
            </div>
            <div class="cr-chip" data-filter="open" onclick="crChip(this)">
                <i class="fas fa-door-open"></i>
                <?php echo isEnglish() ? 'Open' : 'खुला'; ?>
                <span class="cr-chip-count"><?php echo $openCount; ?></span>
            </div>
            <?php if ($closedCount > 0): ?>
            <div class="cr-chip" data-filter="closed" onclick="crChip(this)">
                <i class="fas fa-lock"></i>
                <?php echo isEnglish() ? 'Closed' : 'बन्द'; ?>
                <span class="cr-chip-count"><?php echo $closedCount; ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($totalDepts > 0): ?>
        <div class="cr-dept-chips">
            <span class="cr-dept-label">
                <i class="fas fa-building"></i><?php echo isEnglish() ? 'Dept:' : 'विभाग:'; ?>
            </span>
            <?php foreach (array_keys($deptSet) as $dname): ?>
            <div class="cr-dept-chip"
                 data-dept-filter="<?php echo strtolower(htmlspecialchars($dname)); ?>"
                 onclick="crDeptChip(this)">
                <?php echo htmlspecialchars($dname); ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Result count -->
    <div class="cr-result-count" id="crCount">
        <i class="fas fa-list-ul"></i>
        <span id="crCountText"><?php echo count($jobs); ?> <?php echo isEnglish() ? 'positions found' : 'पदहरू भेटिए'; ?></span>
    </div>

    <!-- Jobs List -->
    <div id="crGrid">
    <?php
    $deptIcons = [
        'IT' => 'fa-laptop-code', 'लेखा' => 'fa-calculator', 'Accounts' => 'fa-calculator',
        'HR' => 'fa-users', 'Operations' => 'fa-cogs', 'Marketing' => 'fa-bullhorn',
        'Finance' => 'fa-coins', 'Admin' => 'fa-building', 'Loan' => 'fa-hand-holding-usd',
        'Credit' => 'fa-credit-card', 'Audit' => 'fa-clipboard-check',
    ];

    foreach ($jobs as $idx => $job):
        $deadlinePassed = !empty($job['deadline']) && strtotime($job['deadline']) < strtotime('today');
        $daysLeft = 0;
        $totalDays = 0;
        if (!empty($job['deadline'])) {
            $daysLeft = (int)ceil((strtotime($job['deadline']) - time()) / 86400);
            if (!empty($job['created_at'])) {
                $totalDays = (int)ceil((strtotime($job['deadline']) - strtotime($job['created_at'])) / 86400);
            }
        }
        $isUrgent = (!$deadlinePassed && $daysLeft > 0 && $daysLeft <= 7);
        $dept = $job['department'] ?? '';
        $deptIcon = 'fa-briefcase';
        foreach ($deptIcons as $k => $v) {
            if (stripos($dept, $k) !== false) { $deptIcon = $v; break; }
        }
        $cardClass = $deadlinePassed ? 'cr-closed' : ($isUrgent ? 'cr-urgent' : '');

        // Progress bar: how much of deadline window remains
        $progressPct = 100;
        if (!$deadlinePassed && $totalDays > 0 && $daysLeft >= 0) {
            $progressPct = min(100, max(3, ($daysLeft / $totalDays) * 100));
        }
        $progressClass = $deadlinePassed ? 'gone' : (($daysLeft <= 7) ? 'near' : 'ok');
    ?>
    <div class="cr-job-card <?php echo $cardClass; ?>"
         data-status="<?php echo $deadlinePassed ? 'closed' : 'open'; ?>"
         data-title="<?php echo strtolower(htmlspecialchars(getLangField($job, 'title'))); ?>"
         data-dept="<?php echo strtolower(htmlspecialchars($dept)); ?>"
         data-aos="fade-up" data-aos-delay="<?php echo ($idx % 4) * 60; ?>">

        <?php if ($isUrgent): ?>
        <div class="cr-urgent-tag">
            <i class="fas fa-fire"></i>
            <?php echo isEnglish() ? 'Closes in '.$daysLeft.'d' : $daysLeft.' दिनमा बन्द'; ?>
        </div>
        <?php endif; ?>

        <div class="cr-card-inner">
            <!-- Top row -->
            <div class="cr-card-top">
                <div class="cr-dept-avatar">
                    <i class="fas <?php echo $deptIcon; ?>"></i>
                </div>
                <div class="cr-card-heading">
                    <div class="cr-job-title"><?php echo htmlspecialchars(getLangField($job, 'title')); ?></div>
                    <div class="cr-badge-row">
                        <?php if (!empty($job['job_type'])): ?>
                        <span class="cr-tag type"><?php echo htmlspecialchars($job['job_type']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($dept)): ?>
                        <span class="cr-tag dept"><?php echo htmlspecialchars($dept); ?></span>
                        <?php endif; ?>
                        <?php if ($deadlinePassed): ?>
                        <span class="cr-tag closed-tag"><i class="fas fa-lock me-1"></i><?php echo isEnglish() ? 'Closed' : 'बन्द'; ?></span>
                        <?php elseif ($isUrgent): ?>
                        <span class="cr-tag urgent-tag"><i class="fas fa-fire me-1"></i><?php echo isEnglish() ? 'Urgent' : 'अर्जेन्ट'; ?></span>
                        <?php else: ?>
                        <span class="cr-tag open"><i class="fas fa-circle me-1 cr-inline-dot"></i><?php echo isEnglish() ? 'Open' : 'खुला'; ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Meta info -->
            <div class="cr-meta">
                <?php if (!empty($job['location'])): ?>
                <span class="cr-meta-item">
                    <i class="fas fa-map-marker-alt"></i>
                    <?php echo htmlspecialchars($job['location']); ?>
                </span>
                <?php endif; ?>
                <?php if (!empty($job['vacancy_count'])): ?>
                <span class="cr-meta-item">
                    <i class="fas fa-users"></i>
                    <?php echo isEnglish() ? 'Vacancy: '.$job['vacancy_count'] : 'रिक्त: '.$job['vacancy_count']; ?>
                </span>
                <?php endif; ?>
                <?php if (!empty($job['salary_range'])): ?>
                <span class="cr-meta-item">
                    <i class="fas fa-coins"></i>
                    <?php echo htmlspecialchars($job['salary_range']); ?>
                </span>
                <?php endif; ?>
                <?php if (!empty($job['deadline'])): ?>
                <span class="cr-meta-item <?php echo $deadlinePassed ? 'deadline-gone' : ($daysLeft <= 7 ? 'deadline-near' : ''); ?>">
                    <i class="fas fa-calendar-alt"></i>
                    <?php echo isEnglish() ? 'Deadline:' : 'म्याद:'; ?>
                    <?php echo date('Y M d', strtotime($job['deadline'])); ?>
                    <?php if (!$deadlinePassed && $daysLeft > 0): ?>
                    <strong class="cr-meta-deadline-strong">(<?php echo isEnglish() ? $daysLeft.'d left' : $daysLeft.' दिन'; ?>)</strong>
                    <?php elseif ($deadlinePassed): ?>
                    <strong class="cr-meta-deadline-strong">(<?php echo isEnglish() ? 'Expired' : 'म्याद सकियो'; ?>)</strong>
                    <?php endif; ?>
                </span>
                <?php endif; ?>
            </div>

            <!-- Deadline progress bar -->
            <?php if (!empty($job['deadline'])): ?>
            <div class="cr-deadline-bar">
                <div class="cr-deadline-fill <?php echo $progressClass; ?>" style="width:<?php echo $deadlinePassed ? 0 : $progressPct; ?>%;"></div>
            </div>
            <?php endif; ?>

            <!-- Description -->
            <?php $desc = getLangField($job, 'description'); if (!empty($desc)): ?>
            <div class="cr-desc"><?php echo htmlspecialchars($desc); ?></div>
            <?php endif; ?>

            <!-- Actions -->
            <div class="cr-actions">
                <a href="career-detail.php?id=<?php echo $job['id']; ?>" class="cr-btn-detail">
                    <i class="fas fa-info-circle"></i>
                    <?php echo isEnglish() ? 'Details' : 'विवरण'; ?>
                </a>
                <?php if (!$deadlinePassed && ($job['allow_online_apply'] ?? 1)): ?>
                <a href="career-detail.php?id=<?php echo $job['id']; ?>#apply-form" class="cr-btn-apply">
                    <i class="fas fa-paper-plane"></i>
                    <span><?php echo isEnglish() ? 'Apply Now' : 'अहिले आवेदन'; ?></span>
                </a>
                <?php endif; ?>
                <?php if (!empty($job['attachment'])): ?>
                <a href="<?php echo htmlspecialchars($job['attachment']); ?>" class="cr-btn-dl" download
                   title="<?php echo isEnglish() ? 'Download Notice' : 'सूचना डाउनलोड'; ?>">
                    <i class="fas fa-download"></i>
                    <?php echo isEnglish() ? 'Notice' : 'सूचना'; ?>
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    </div>

    <!-- No results -->
    <div id="crNoResults" class="cr-no-results">
        <div class="cr-empty">
            <div class="cr-empty-icon"><i class="fas fa-search"></i></div>
            <h5><?php echo isEnglish() ? 'No Matching Positions Found' : 'कुनै पद फेला परेन'; ?></h5>
            <p class="cr-muted small"><?php echo isEnglish() ? 'Try different keywords.' : 'अर्को शब्दले खोज्नुहोस्।'; ?></p>
            <button class="btn btn-outline-secondary btn-sm mt-2" onclick="crReset()">
                <i class="fas fa-redo me-1"></i><?php echo isEnglish() ? 'Reset' : 'रिसेट'; ?>
            </button>
        </div>
    </div>

    <?php else: ?>
    <!-- No vacancies at all -->
    <div class="cr-novacancy" data-aos="fade-up">
        <div class="cr-novacancy-icon"><i class="fas fa-briefcase"></i></div>
        <h4 class="mb-2"><?php echo isEnglish() ? 'No Current Openings' : 'हाल कुनै रिक्त पद छैन'; ?></h4>
        <p class="cr-muted mb-3">
            <?php echo isEnglish()
                ? 'No job openings at the moment. Please check back later or send your CV to our email.'
                : 'हाल कुनै पद रिक्त छैन। कृपया पछि फेरि जाँच गर्नुहोस् वा हाम्रो इमेलमा CV पठाउनुहोस्।'; ?>
        </p>
        <a href="mailto:<?php echo getSetting('email','info@sahakari.org.np'); ?>?subject=CV Submission"
           class="btn cr-btn-primary">
            <i class="fas fa-envelope me-2"></i><?php echo isEnglish() ? 'Send Your CV' : 'CV इमेल गर्नुहोस्'; ?>
        </a>
    </div>
    <?php endif; ?>

</div><!-- /.col main -->

<!-- ── Sidebar ── -->
<div class="col-lg-4 cr-sidebar">

    <button class="cr-sidebar-toggle" onclick="this.nextElementSibling.classList.toggle('open')">
        <i class="fas fa-info-circle"></i>
        <?php echo isEnglish() ? 'Career Resources & Info' : 'क्यारियर सहायता र जानकारी'; ?>
        <i class="fas fa-chevron-down ms-auto"></i>
    </button>

    <div class="cr-sidebar-content">

    <!-- Track Application -->
    <div class="cr-sb-card cr-track-card" data-aos="fade-up">
        <div class="cr-sb-head">
            <h4><i class="fas fa-search me-2"></i><?php echo isEnglish() ? 'Track Application' : 'आवेदन ट्र्याक गर्नुहोस्'; ?></h4>
            <p><?php echo isEnglish()
                ? 'Already applied? Check your status with Tracking ID or email.'
                : 'पहिले नै आवेदन दिनुभयो? Tracking ID वा इमेलले स्थिति हेर्नुहोस्।'; ?>
            </p>
        </div>
        <div class="cr-sb-body">
            <a href="application-tracker.php" class="cr-btn-track">
                <i class="fas fa-route"></i>
                <?php echo isEnglish() ? 'Track Now' : 'अहिले ट्र्याक'; ?>
            </a>
        </div>
    </div>

    <!-- Submit CV -->
    <div class="cr-sb-card cr-cv-card" data-aos="fade-up" data-aos-delay="80">
        <div class="cr-sb-body">
            <div class="cr-cv-icon"><i class="fas fa-file-alt"></i></div>
            <h4><?php echo isEnglish() ? 'Submit Your CV' : 'आफ्नो CV पठाउनुहोस्'; ?></h4>
            <p><?php echo isEnglish()
                ? 'Interested in joining us? Send your CV to our HR department even if no vacancy is posted.'
                : 'हामीसँग सामेल हुन इच्छुक? रिक्त पद नभए पनि HR विभागमा CV पठाउन सक्नुहुन्छ।'; ?>
            </p>
            <a href="mailto:<?php echo getSetting('email','info@sahakari.org.np'); ?>?subject=CV Submission - Job Application" class="cr-btn-cv">
                <i class="fas fa-paper-plane"></i>
                <?php echo isEnglish() ? 'Send CV' : 'CV पठाउनुहोस्'; ?>
            </a>
        </div>
    </div>

    <!-- Why Join Us -->
    <div class="cr-sb-card cr-why-card" data-aos="fade-up" data-aos-delay="140">
        <div class="cr-sb-body">
            <h4><i class="fas fa-star me-2 cr-ico-accent"></i><?php echo isEnglish() ? 'Why Join Us?' : 'हामीलाई किन रोज्ने?'; ?></h4>
            <div class="cr-benefits">
                <?php
                $benefits = [
                    ['fa-money-bill-wave', isEnglish() ? 'Competitive Salary'   : 'प्रतिस्पर्धी तलब'],
                    ['fa-chart-line',      isEnglish() ? 'Professional Growth'  : 'व्यावसायिक वृद्धि'],
                    ['fa-smile',           isEnglish() ? 'Friendly Environment' : 'मैत्रीपूर्ण वातावरण'],
                    ['fa-heartbeat',       isEnglish() ? 'Health Benefits'      : 'स्वास्थ्य सुविधाहरू'],
                    ['fa-gift',            isEnglish() ? 'Festival Bonus'       : 'चाडपर्व बोनस'],
                    ['fa-graduation-cap',  isEnglish() ? 'Training Support'     : 'तालिम सहयोग'],
                ];
                foreach ($benefits as $b): ?>
                <div class="cr-benefit-item">
                    <div class="cr-benefit-icon"><i class="fas <?php echo $b[0]; ?>"></i></div>
                    <span><?php echo $b[1]; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    </div><!-- /.cr-sidebar-content -->
</div><!-- /.sidebar -->
</div><!-- /.row -->
</div><!-- /.container -->
</section>

<script>
/* ─── Career v2 Filter Logic ─── */
var crActiveFilter = 'all';

function crFilter() {
    var q   = (document.getElementById('crSearch').value || '').toLowerCase().trim();
    var cards = document.querySelectorAll('#crGrid .cr-job-card');
    var visible = 0;

    cards.forEach(function(c) {
        var title = (c.getAttribute('data-title') || '');
        var dept  = (c.getAttribute('data-dept')  || '');
        var status= (c.getAttribute('data-status') || '');

        var matchSearch = !q || title.includes(q) || dept.includes(q);
        var matchFilter = crActiveFilter === 'all' || status === crActiveFilter;

        if (matchSearch && matchFilter) {
            c.style.display = ''; visible++;
        } else {
            c.style.display = 'none';
        }
    });

    var countEl = document.getElementById('crCountText');
    if (countEl) {
        countEl.textContent = visible + ' <?php echo isEnglish() ? "positions found" : "पदहरू भेटिए"; ?>';
    }
    var noRes = document.getElementById('crNoResults');
    if (noRes) noRes.style.display = visible === 0 ? 'block' : 'none';
}

function crChip(el) {
    document.querySelectorAll('.cr-chip').forEach(function(c) {
        c.classList.remove('active','green','grey');
    });
    el.classList.add('active');
    var f = el.getAttribute('data-filter');
    if (f === 'open')   el.classList.add('green');
    if (f === 'closed') el.classList.add('grey');
    crActiveFilter = f;
    crFilter();
}

function crReset() {
    document.getElementById('crSearch').value = '';
    crActiveFilter = 'all';
    crActiveDept   = '';
    document.querySelectorAll('.cr-chip').forEach(function(c, i) {
        c.classList.remove('active','green','grey');
        if (i === 0) c.classList.add('active');
    });
    document.querySelectorAll('.cr-dept-chip').forEach(function(c) {
        c.classList.remove('active');
    });
    crFilter();
}

/* Department chip filter */
var crActiveDept = '';
function crDeptChip(el) {
    var d = el.getAttribute('data-dept-filter') || '';
    if (crActiveDept === d) {
        crActiveDept = '';
        el.classList.remove('active');
    } else {
        document.querySelectorAll('.cr-dept-chip').forEach(function(c) { c.classList.remove('active'); });
        crActiveDept = d;
        el.classList.add('active');
    }
    crFilter();
}

/* Override crFilter to also handle dept */
var _crFilterOrig = crFilter;
crFilter = function() {
    var q      = (document.getElementById('crSearch').value || '').toLowerCase().trim();
    var cards  = document.querySelectorAll('#crGrid .cr-job-card');
    var visible = 0;
    cards.forEach(function(c) {
        var title  = (c.getAttribute('data-title') || '');
        var dept   = (c.getAttribute('data-dept')  || '').toLowerCase();
        var status = (c.getAttribute('data-status') || '');
        var matchSearch = !q || title.includes(q) || dept.includes(q);
        var matchFilter = crActiveFilter === 'all' || status === crActiveFilter;
        var matchDept   = !crActiveDept  || dept.includes(crActiveDept);
        if (matchSearch && matchFilter && matchDept) {
            c.style.display = ''; visible++;
        } else {
            c.style.display = 'none';
        }
    });
    var countEl = document.getElementById('crCountText');
    if (countEl) countEl.textContent = visible + ' <?php echo isEnglish() ? "positions found" : "पदहरू भेटिए"; ?>';
    var noRes = document.getElementById('crNoResults');
    if (noRes) noRes.style.display = visible === 0 ? 'block' : 'none';
};

/* Search on keyup */
document.getElementById('crSearch')?.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { this.value = ''; crFilter(); }
});
</script>

<?php require_once 'includes/footer.php'; ?>
