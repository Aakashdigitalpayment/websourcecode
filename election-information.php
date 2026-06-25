<?php
require_once __DIR__ . '/_bootstrap.php'; // bootstrap → config auto-loaded
require_once 'includes/election-tables.php';

$pageTitle = lang('election_information');
require_once 'includes/header.php';

$L = getLangStrings();

/** YYYY-MM-DD देखाउनु — नेपालीमा अंक नेपाली (कार्यक्रम/समिति जस्तै BS स्ट्रिङ DB) */
$electionFmtYmd = static function (?string $ymd): string {
    $ymd = trim((string)$ymd);
    if ($ymd === '') {
        return '';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd) && function_exists('toNepaliNumeral') && !isEnglish()) {
        return htmlspecialchars(toNepaliNumeral($ymd), ENT_QUOTES, 'UTF-8');
    }
    return htmlspecialchars($ymd, ENT_QUOTES, 'UTF-8');
};

$cycle = null;
$milestones = [];
$archiveCycles = [];
$dbErr = false;

try {
    $db = getDB();
    ensureElectionTables($db);
    ensureElectionVotingTables($db);
    $reqId = (int)($_GET['cycle'] ?? 0);

    $archiveCycles = $db->query(
        "SELECT id, title_np, title_en, period_label, date_from, date_to, sort_order,
                vote_start_at, vote_end_at, voting_enabled, results_finalized
         FROM election_cycles WHERE is_published = 1
         ORDER BY sort_order ASC, id DESC"
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($reqId > 0) {
        $st = $db->prepare("SELECT * FROM election_cycles WHERE id = ? AND is_published = 1 LIMIT 1");
        $st->execute([$reqId]);
        $cycle = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$cycle && !empty($archiveCycles)) {
        $cycle = $archiveCycles[0];
    }

    if ($cycle) {
        $ms = $db->prepare(
            "SELECT * FROM election_milestones WHERE cycle_id = ? AND is_active = 1
             ORDER BY display_order ASC, event_date ASC, id ASC"
        );
        $ms->execute([(int)$cycle['id']]);
        $milestones = $ms->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $dbErr = true;
}

/* Public — चक्रको पद + उम्मेदवार list (मत संख्या देखाइँदैन) — समिति-wise grouped */
$pubPositions = [];
$pubCandidates = [];      /* candidate rows by position_id */
$samitiGroups = [];       /* committee_type_id => ['name' => , 'positions' => [...] ] */
if ($cycle && !$dbErr) {
    try {
        $ps = $db->prepare("SELECT p.*, ct.name_np AS ctype_np, ct.name AS ctype_en FROM election_positions p
                            LEFT JOIN committee_types ct ON ct.id=p.committee_type_id
                            WHERE p.cycle_id=? AND p.is_active=1 ORDER BY p.display_order, p.id");
        $ps->execute([(int)$cycle['id']]);
        $pubPositions = $ps->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!empty($pubPositions)) {
            $cs2 = $db->prepare("SELECT * FROM election_candidates WHERE cycle_id=? AND is_active=1 ORDER BY position_id, display_order, id");
            $cs2->execute([(int)$cycle['id']]);
            $allC = $cs2->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($allC as $c) $pubCandidates[(int)$c['position_id']][] = $c;

            foreach ($pubPositions as $pp) {
                $key = (int)($pp['committee_type_id'] ?? 0);
                if (!isset($samitiGroups[$key])) {
                    $samitiGroups[$key] = [
                        'name_np' => $pp['ctype_np'] ?? 'अन्य',
                        'name_en' => $pp['ctype_en'] ?? 'Other',
                        'positions' => [],
                    ];
                }
                $samitiGroups[$key]['positions'][] = $pp;
            }
        }
    } catch (Throwable $e) { /* ignore */ }
}

$ctitle = '';
if ($cycle) {
    $ctitle = isEnglish()
        ? (trim((string)($cycle['title_en'] ?? '')) ?: trim((string)($cycle['title_np'] ?? '')))
        : (trim((string)($cycle['title_np'] ?? '')) ?: trim((string)($cycle['title_en'] ?? '')));
}
$cintro = $cycle ? (isEnglish() ? (trim((string)($cycle['intro_en'] ?? '')) ?: trim((string)($cycle['intro_np'] ?? '')))
    : (trim((string)($cycle['intro_np'] ?? '')) ?: trim((string)($cycle['intro_en'] ?? '')))) : '';
?>

<section class="page-banner">
    <div class="container">
        <h1><?php echo htmlspecialchars($L['election_information'] ?? 'निर्वाचन जानकारी'); ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>notices.php"><?php echo $L['notices']; ?></a></li>
                <li class="breadcrumb-item active"><?php echo htmlspecialchars($L['election_information'] ?? ''); ?></li>
            </ol>
        </nav>
    </div>
</section>

<section class="notices-section section-padding">
    <div class="container">
        <?php if ($dbErr): ?>
            <div class="alert alert-warning"><?php echo isEnglish() ? 'Could not load data. Please try again later.' : 'डाटा लोड गर्न सकिएन।'; ?></div>
        <?php elseif (!$cycle): ?>
            <div class="empty-state text-center py-5">
                <i class="fas fa-check-to-slot fa-4x text-muted mb-3"></i>
                <h4><?php echo htmlspecialchars($L['election_no_data'] ?? ''); ?></h4>
                <p class="text-muted mb-4"><?php echo htmlspecialchars($L['election_intro'] ?? ''); ?></p>
                <a href="<?php echo SITE_URL; ?>notices.php" class="btn btn-outline-primary"><i class="fas fa-bullhorn me-1"></i><?php echo htmlspecialchars($L['election_view_notices'] ?? ''); ?></a>
            </div>
        <?php else: ?>
            <div class="section-header text-center mb-4" data-aos="fade-up">
                <div class="section-badge-wrap">
                    <span class="section-badge"><i class="fas fa-check-to-slot"></i> <?php echo htmlspecialchars($L['election_information'] ?? ''); ?></span>
                </div>
                <h2><?php echo htmlspecialchars($ctitle); ?></h2>
                <?php if (!empty($cycle['period_label'])): ?>
                    <p class="text-muted mb-0"><i class="fas fa-calendar-alt me-1"></i><?php echo htmlspecialchars((string)$cycle['period_label']); ?></p>
                <?php endif; ?>
                <?php
                $df = $cycle['date_from'] ?? '';
                $dt = $cycle['date_to'] ?? '';
                if ($df || $dt):
                    $rangeDisp = trim($electionFmtYmd($df) . ($df && $dt ? ' — ' : '') . $electionFmtYmd($dt));
                ?>
                    <p class="small text-muted"><?php echo $rangeDisp; ?></p>
                <?php endif; ?>
                <div class="section-divider"></div>
            </div>

            <?php if ($cintro !== ''): ?>
                <div class="row mb-5">
                    <div class="col-lg-10 mx-auto">
                        <div class="notice-detail-card">
                            <div class="notice-content"><?php echo nl2br(htmlspecialchars($cintro, ENT_QUOTES, 'UTF-8')); ?></div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="row g-4 mb-4">
                <div class="col-md-6 text-center text-md-start">
                    <a href="<?php echo SITE_URL; ?>committees.php" class="btn btn-success"><i class="fas fa-users-gear me-1"></i><?php echo htmlspecialchars($L['election_view_committees'] ?? ''); ?></a>
                </div>
                <div class="col-md-6 text-center text-md-end">
                    <a href="<?php echo SITE_URL; ?>notices.php" class="btn btn-outline-primary"><i class="fas fa-bullhorn me-1"></i><?php echo htmlspecialchars($L['election_view_notices'] ?? ''); ?></a>
                </div>
            </div>

            <?php if (!empty($pubPositions)): ?>
                <h3 class="h5 mb-3 text-center" style="color:var(--primary-color);font-weight:700;">
                    <i class="fas fa-user-tie me-2"></i>उम्मेदवारहरू
                </h3>
                <?php
                $vOpenPub = isElectionVotingOpen($cycle);
                if ($vOpenPub):
                ?>
                <div class="alert alert-success text-center">
                    <i class="fas fa-check-circle me-1"></i> मतदान सक्रिय छ।
                    <a href="<?php echo SITE_URL; ?>member/election-vote.php?cycle=<?php echo (int)$cycle['id']; ?>" class="btn btn-sm btn-success ms-2">सदस्य पोर्टलमा गएर मत दिनुहोस्</a>
                </div>
                <?php elseif (!empty($cycle['vote_start_at'])): ?>
                <div class="alert alert-info text-center small">
                    <i class="fas fa-clock me-1"></i> मतदान समय: <?php echo htmlspecialchars((string)$cycle['vote_start_at']); ?> देखि <?php echo htmlspecialchars((string)$cycle['vote_end_at']); ?> सम्म (नेपाल समय)
                </div>
                <?php endif; ?>
                <?php
                $samitiKeys = array_keys($samitiGroups);
                $firstKey = $samitiKeys[0] ?? 0;
                ?>
                <?php if (count($samitiGroups) > 1): ?>
                <ul class="nav nav-pills justify-content-center mb-4 election-samiti-tabs" role="tablist">
                    <?php foreach ($samitiGroups as $sk => $grp): ?>
                        <li class="nav-item">
                            <button class="nav-link <?php echo $sk===$firstKey ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#sgrp-<?php echo (int)$sk; ?>" type="button">
                                <i class="fas fa-users-gear me-1"></i><?php echo htmlspecialchars(isEnglish() ? ($grp['name_en'] ?: $grp['name_np']) : $grp['name_np']); ?>
                            </button>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <div class="tab-content">
                <?php foreach ($samitiGroups as $sk => $grp): ?>
                    <div class="tab-pane fade <?php echo $sk===$firstKey ? 'show active' : ''; ?>" id="sgrp-<?php echo (int)$sk; ?>">
                        <?php if (count($samitiGroups) === 1): ?>
                            <h4 class="h6 text-center mb-3"><i class="fas fa-users-gear me-1"></i><?php echo htmlspecialchars(isEnglish() ? ($grp['name_en'] ?: $grp['name_np']) : $grp['name_np']); ?></h4>
                        <?php endif; ?>
                        <?php foreach ($grp['positions'] as $pp): $list = $pubCandidates[(int)$pp['id']] ?? []; ?>
                            <div class="mb-4 election-pos-block">
                                <h4 class="h6 mb-3 election-pos-title">
                                    <i class="fas fa-briefcase me-1"></i><?php echo htmlspecialchars($pp['title_np']); ?>
                                    <small class="text-muted">(सिट: <?php echo (int)$pp['seats']; ?>)</small>
                                </h4>
                                <div class="row justify-content-center">
                                <?php foreach ($list as $idx => $cd): ?>
                                    <div class="col-lg-3 col-md-4 col-sm-6 mb-4" data-aos="fade-up" data-aos-delay="<?php echo ($idx % 4) * 50; ?>">
                                        <div class="team-card-circular <?php echo $idx === 0 ? 'featured' : ''; ?>">
                                            <div class="team-photo-circular">
                                                <?php if (!empty($cd['photo'])): ?>
                                                    <img src="<?php echo SITE_URL . htmlspecialchars(ltrim((string)$cd['photo'], '/')); ?>" loading="lazy" alt="<?php echo htmlspecialchars($cd['name']); ?>">
                                                <?php else: ?>
                                                    <div class="team-placeholder-circular"><i class="fas fa-user"></i></div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="team-info-circular">
                                                <h5><?php echo htmlspecialchars($cd['name']); ?>
                                                    <?php if (!empty($cd['symbol_no'])): ?><span class="badge bg-secondary ms-1">#<?php echo htmlspecialchars($cd['symbol_no']); ?></span><?php endif; ?>
                                                </h5>
                                                <?php if (!empty($cd['name_en'])): ?><p class="team-name-en"><?php echo htmlspecialchars($cd['name_en']); ?></p><?php endif; ?>
                                                <span class="team-position-badge"><?php echo htmlspecialchars($pp['title_np']); ?></span>
                                                <?php if (!empty($cd['address'])): ?><div class="small text-muted mt-1"><i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($cd['address']); ?></div><?php endif; ?>
                                                <?php if (!empty($cd['phone']) || !empty($cd['email'])): ?>
                                                <div class="team-contact-circular">
                                                    <?php if (!empty($cd['phone'])): ?><a href="tel:<?php echo htmlspecialchars($cd['phone']); ?>" title="<?php echo htmlspecialchars($cd['phone']); ?>"><i class="fas fa-phone"></i></a><?php endif; ?>
                                                    <?php if (!empty($cd['email'])): ?><a href="mailto:<?php echo htmlspecialchars($cd['email']); ?>" title="<?php echo htmlspecialchars($cd['email']); ?>"><i class="fas fa-envelope"></i></a><?php endif; ?>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (empty($list)): ?>
                                    <div class="col-12"><div class="text-muted small text-center py-3">यस पदमा अझै उम्मेदवार छैन।</div></div>
                                <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($milestones)): ?>
                <h3 class="h5 mb-3 text-center" style="color:var(--primary-color);font-weight:700;">
                    <i class="fas fa-list-ol me-2"></i><?php echo htmlspecialchars($L['election_timeline'] ?? ''); ?>
                </h3>
                <div class="row">
                    <div class="col-lg-10 mx-auto">
                        <div class="election-timeline">
                            <?php foreach ($milestones as $m): ?>
                                <?php
                                $mt = isEnglish()
                                    ? (trim((string)($m['title_en'] ?? '')) ?: trim((string)($m['title_np'] ?? '')))
                                    : (trim((string)($m['title_np'] ?? '')) ?: trim((string)($m['title_en'] ?? '')));
                                $md = isEnglish()
                                    ? (trim((string)($m['detail_en'] ?? '')) ?: trim((string)($m['detail_np'] ?? '')))
                                    : (trim((string)($m['detail_np'] ?? '')) ?: trim((string)($m['detail_en'] ?? '')));
                                $ed = $m['event_date'] ?? '';
                                ?>
                                <div class="election-tl-item mb-4 pb-4 border-bottom">
                                    <div class="d-flex flex-wrap align-items-baseline gap-2 mb-2">
                                        <?php if ($ed): ?>
                                            <span class="badge bg-primary rounded-pill"><i class="fas fa-calendar-day me-1"></i><?php echo $electionFmtYmd($ed); ?></span>
                                        <?php endif; ?>
                                        <h4 class="h6 mb-0 flex-grow-1" style="font-weight:700;"><?php echo htmlspecialchars($mt); ?></h4>
                                    </div>
                                    <?php if ($md !== ''): ?>
                                        <div class="text-muted small mb-2"><?php echo nl2br(htmlspecialchars($md, ENT_QUOTES, 'UTF-8')); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($m['attachment'])): ?>
                                        <?php
                                        $__att = (string)$m['attachment'];
                                        $__attUrl = (strpos($__att, 'http://') === 0 || strpos($__att, 'https://') === 0)
                                            ? $__att
                                            : (SITE_URL . ltrim($__att, '/'));
                                        ?>
                                        <a href="<?php echo htmlspecialchars($__attUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-secondary" target="_blank" rel="noopener">
                                            <i class="fas fa-file-arrow-down me-1"></i><?php echo htmlspecialchars($L['download'] ?? 'डाउनलोड'); ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php
            $others = array_filter($archiveCycles, static fn($c) => (int)$c['id'] !== (int)$cycle['id']);
            if (!empty($others)):
            ?>
                <div class="mt-5 pt-4 border-top">
                    <h3 class="h6 mb-3" style="color:var(--primary-color);font-weight:700;">
                        <i class="fas fa-archive me-2"></i><?php echo htmlspecialchars($L['election_archive'] ?? ''); ?>
                    </h3>
                    <ul class="list-unstyled row g-2">
                        <?php foreach ($others as $oc): ?>
                            <li class="col-md-6">
                                <a href="<?php echo SITE_URL; ?>election-information.php?cycle=<?php echo (int)$oc['id']; ?>" class="d-block p-3 border rounded text-decoration-none text-dark h-100 election-arch-link">
                                    <div class="election-arch-head">
                                        <strong class="election-arch-title">
                                            <?php echo htmlspecialchars(isEnglish()
                                                ? (trim((string)($oc['title_en'] ?? '')) ?: trim((string)($oc['title_np'] ?? '')))
                                                : (trim((string)($oc['title_np'] ?? '')) ?: trim((string)($oc['title_en'] ?? '')))); ?>
                                        </strong>
                                        <?php if (!empty($oc['period_label'])): ?>
                                            <span class="election-arch-tenure">
                                                <i class="fas fa-calendar-alt me-1"></i><?php echo isEnglish() ? 'Tenure: ' : 'कार्यकाल: '; ?><?php echo htmlspecialchars((string)$oc['period_label']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="d-block small text-muted mt-1">
                                        <i class="fas fa-user-tie me-1"></i><?php echo isEnglish() ? 'View candidate details' : 'उम्मेदवार विवरण हेर्नुहोस्'; ?>
                                    </span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>


<?php require_once 'includes/footer.php'; ?>
