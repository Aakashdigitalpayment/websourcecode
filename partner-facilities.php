<?php
/**
 * Public: साझेदार सुविधाहरू — Partner Facilities
 * Cards + table, type filter, search, verify/vendor CTAs
 */
require_once 'includes/config.php';
require_once 'includes/ensure-tables.php';
require_once 'includes/partner-facilities-tables.php';
$pageTitle = isEnglish() ? 'Partner Facilities' : 'साझेदार सुविधाहरू';
require_once 'includes/header.php';
$L = getLangStrings();

$siteName = function_exists('getSetting')
    ? trim((string)(getSetting('site_name') ?: getSetting('cooperative_name') ?: ''))
    : '';
if ($siteName === '') {
    $siteName = isEnglish() ? 'Our Cooperative' : 'हाम्रो सहकारी';
}

$facilities = [];
$types = [];
try {
    $db = getDB();
    ensurePartnerFacilitiesTables($db);
    if (function_exists('ensureMemberPartnerServicesTable')) {
        ensureMemberPartnerServicesTable($db);
    }
    $facilities = getActivePartnerFacilities($db, 300);
    $types = array_values(array_unique(array_filter(array_map(
        static fn($f) => trim((string)($f['facility_type'] ?? '')),
        $facilities
    ))));
    sort($types, SORT_STRING);
} catch (Throwable $e) {
    $facilities = [];
    $types = [];
}

$activeType = trim((string)($_GET['type'] ?? ''));
if ($activeType !== '' && !in_array($activeType, $types, true)) {
    $activeType = '';
}
$view = (($_GET['view'] ?? '') === 'table') ? 'table' : 'cards';

$filtered = $activeType
    ? array_values(array_filter($facilities, static fn($f) => ($f['facility_type'] ?? '') === $activeType))
    : $facilities;

$featured = array_values(array_filter($filtered, static fn($f) => !empty($f['is_featured'])));
?>

<section class="page-banner">
    <div class="container">
        <h1><?php echo isEnglish() ? 'Partner Facilities & Discounts' : 'साझेदार सुविधाहरू'; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item active"><?php echo isEnglish() ? 'Partner Facilities' : 'साझेदार सुविधाहरू'; ?></li>
            </ol>
        </nav>
    </div>
</section>

<section class="section-padding">
<div class="container">

    <div class="text-center mb-4 pf-intro">
        <div class="pf-hero-icon-wrap">
            <i class="fas fa-handshake pf-hero-icon"></i>
        </div>
        <h2 class="pf-hero-title">
            <?php echo isEnglish()
                ? 'Member benefits at partner organizations'
                : 'साझेदार संस्थामा सदस्यले पाउने सुविधाहरू'; ?>
        </h2>
        <p class="text-muted pf-intro-text">
            <?php echo isEnglish()
                ? 'As a member of ' . htmlspecialchars($siteName) . ', enjoy exclusive discounts and benefits at our partner organizations. Show your member card (or verify at the desk) to claim offers.'
                : htmlspecialchars($siteName) . ' को सदस्यको रूपमा हाम्रा साझेदार संस्थाहरूमा विशेष छुट तथा सुविधाहरू प्राप्त गर्नुहोस्। सदस्य कार्ड देखाएर (वा डेस्कमा verify गरेर) सुविधा लिनुहोस्।'; ?>
        </p>
        <div class="pf-cta-row">
            <a class="btn btn-success btn-sm" href="<?php echo SITE_URL; ?>verify.php">
                <i class="fas fa-id-card me-1"></i><?php echo isEnglish() ? 'Verify member card' : 'सदस्य कार्ड प्रमाणित'; ?>
            </a>
            <a class="btn btn-outline-success btn-sm" href="<?php echo SITE_URL; ?>member/login.php">
                <i class="fas fa-user me-1"></i><?php echo isEnglish() ? 'Member portal' : 'सदस्य पोर्टल'; ?>
            </a>
            <a class="btn btn-outline-secondary btn-sm" href="<?php echo SITE_URL; ?>vendor-enlistment.php">
                <i class="fas fa-store me-1"></i><?php echo isEnglish() ? 'Become a partner' : 'साझेदार बन्नुहोस्'; ?>
            </a>
        </div>
    </div>

    <div class="pf-how-row" aria-label="<?php echo isEnglish() ? 'How it works' : 'कसरी काम गर्छ'; ?>">
        <div class="pf-how-step">
            <span class="pf-how-num">1</span>
            <div>
                <strong><?php echo isEnglish() ? 'Choose a partner' : 'साझेदार छान्नुहोस्'; ?></strong>
                <p><?php echo isEnglish() ? 'Browse discounts by type or search below.' : 'तल प्रकार/खोजबाट छुट सूची हेर्नुहोस्।'; ?></p>
            </div>
        </div>
        <div class="pf-how-step">
            <span class="pf-how-num">2</span>
            <div>
                <strong><?php echo isEnglish() ? 'Show your member card' : 'सदस्य कार्ड देखाउनुहोस्'; ?></strong>
                <p><?php echo isEnglish() ? 'At the partner desk, present your cooperative ID card.' : 'साझेदार डेस्कमा सहकारीको सदस्य कार्ड देखाउनुहोस्।'; ?></p>
            </div>
        </div>
        <div class="pf-how-step">
            <span class="pf-how-num">3</span>
            <div>
                <strong><?php echo isEnglish() ? 'Desk verifies & logs' : 'डेस्क verify र लग'; ?></strong>
                <p><?php echo isEnglish() ? 'Staff confirms on verify page — history appears in your portal.' : 'कर्मचारी verify पृष्ठबाट पुष्टि गर्छ — इतिहास पोर्टलमा देखिन्छ।'; ?></p>
            </div>
        </div>
    </div>

    <?php if (empty($facilities)): ?>
    <div class="text-center py-5 pf-empty-block">
        <div class="pf-empty-icon"><i class="fas fa-handshake"></i></div>
        <h4 class="pf-empty-title"><?php echo isEnglish() ? 'Coming Soon' : 'छिट्टै आउँदैछ'; ?></h4>
        <p class="text-muted mb-3"><?php echo isEnglish()
            ? 'Partner facility details will be published soon. Want to partner with us?'
            : 'साझेदार सुविधाको विवरण छिट्टै प्रकाशित गरिनेछ। साझेदार बन्न चाहनुहुन्छ?'; ?></p>
        <a class="btn btn-success" href="<?php echo SITE_URL; ?>vendor-enlistment.php">
            <i class="fas fa-store me-1"></i><?php echo isEnglish() ? 'Apply as vendor/partner' : 'भेन्डर/साझेदार आवेदन'; ?>
        </a>
    </div>
    <?php else: ?>

    <?php if (!empty($featured) && $activeType === ''): ?>
    <div class="pf-featured-strip mb-3">
        <div class="pf-featured-strip-title"><i class="fas fa-star me-1"></i><?php echo isEnglish() ? 'Featured partners' : 'विशेष साझेदारहरू'; ?></div>
        <div class="pf-featured-chips">
            <?php foreach (array_slice($featured, 0, 8) as $ff):
                $fn = partnerFacilityDisplayName($ff);
                $fd = partnerDiscountDisplay($ff);
            ?>
            <span class="pf-featured-chip-item">
                <strong><?php echo htmlspecialchars($fn); ?></strong>
                <?php if ($fd !== ''): ?><em><?php echo htmlspecialchars($fd); ?></em><?php endif; ?>
            </span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($types)): ?>
    <div class="pf-filter-wrap" role="tablist" aria-label="<?php echo isEnglish() ? 'Facility types' : 'सुविधा प्रकार'; ?>">
        <a href="partner-facilities.php<?php echo $view === 'table' ? '?view=table' : ''; ?>"
           class="pf-filter-pill <?php echo !$activeType ? 'active' : ''; ?>">
            <i class="fas fa-th-large me-1"></i><?php echo isEnglish() ? 'All' : 'सबै'; ?>
            <span class="pf-pill-count"><?php echo count($facilities); ?></span>
        </a>
        <?php foreach ($types as $t):
            $cnt = count(array_filter($facilities, static fn($f) => ($f['facility_type'] ?? '') === $t));
            $href = '?type=' . urlencode($t) . ($view === 'table' ? '&view=table' : '');
        ?>
        <a href="<?php echo htmlspecialchars($href); ?>"
           class="pf-filter-pill <?php echo $activeType === $t ? 'active' : ''; ?>">
            <?php echo htmlspecialchars($t); ?>
            <span class="pf-pill-count"><?php echo $cnt; ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div class="pf-search-wrap">
            <i class="fas fa-search pf-search-icon" aria-hidden="true"></i>
            <input type="search" id="pfSearch"
                   placeholder="<?php echo isEnglish() ? 'Search partner, location, details…' : 'संस्था, स्थान, विवरण खोज्नुहोस्…'; ?>"
                   class="pf-search-input"
                   autocomplete="off"
                   oninput="pfSearchFn()">
        </div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <div class="pf-view-toggle pf-view-toggle-desktop" role="group" aria-label="View">
                <?php
                $baseQ = $activeType !== '' ? ('type=' . urlencode($activeType) . '&') : '';
                ?>
                <a class="pf-view-btn <?php echo $view === 'cards' ? 'active' : ''; ?>"
                   href="?<?php echo $baseQ; ?>view=cards"><?php echo isEnglish() ? 'Cards' : 'कार्ड'; ?></a>
                <a class="pf-view-btn <?php echo $view === 'table' ? 'active' : ''; ?>"
                   href="?<?php echo $baseQ; ?>view=table"><?php echo isEnglish() ? 'Table' : 'तालिका'; ?></a>
            </div>
            <div id="pfCount" class="text-muted pf-count">
                <?php echo count($filtered); ?> <?php echo isEnglish() ? 'partners' : 'साझेदार'; ?>
            </div>
        </div>
    </div>

    <?php if ($view === 'cards'): ?>
    <div class="pf-card-grid" id="pfCardGrid">
        <?php foreach ($filtered as $f):
            $name = partnerFacilityDisplayName($f);
            $desc = partnerFacilityDescription($f);
            $disc = partnerDiscountDisplay($f);
            $logo = partnerFacilityLogoUrl($f);
            $phone = trim((string)($f['contact_phone'] ?? ''));
            $email = trim((string)($f['contact_email'] ?? ''));
            $web = trim((string)($f['website_url'] ?? ''));
            $terms = trim((string)($f['terms_np'] ?? ''));
            $searchBlob = strtolower($name . ' ' . ($f['location'] ?? '') . ' ' . ($f['facility_type'] ?? '') . ' ' . $desc . ' ' . $disc);
        ?>
        <article class="pf-card <?php echo !empty($f['is_featured']) ? 'pf-card-featured' : ''; ?>"
                 data-search="<?php echo htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="pf-card-top">
                <div class="pf-card-logo">
                    <?php if ($logo !== ''): ?>
                        <img src="<?php echo htmlspecialchars($logo); ?>" alt="" loading="lazy">
                    <?php else: ?>
                        <i class="fas fa-store"></i>
                    <?php endif; ?>
                </div>
                <div class="pf-card-head-text">
                    <?php if (!empty($f['is_featured'])): ?>
                        <span class="pf-featured-chip"><?php echo isEnglish() ? 'Featured' : 'विशेष'; ?></span>
                    <?php endif; ?>
                    <h3 class="pf-card-title"><?php echo htmlspecialchars($name); ?></h3>
                    <div class="pf-location"><i class="fas fa-location-dot"></i><?php echo htmlspecialchars(($f['location'] ?? '') !== '' ? $f['location'] : '—'); ?></div>
                </div>
                <?php if ($disc !== ''): ?>
                <div class="pf-card-discount"><?php echo htmlspecialchars($disc); ?></div>
                <?php endif; ?>
            </div>
            <?php if (!empty($f['facility_type'])): ?>
                <span class="pf-type-badge"><?php echo htmlspecialchars((string)$f['facility_type']); ?></span>
            <?php endif; ?>
            <?php if ($desc !== ''): ?>
                <p class="pf-card-desc"><?php echo nl2br(htmlspecialchars(mb_substr($desc, 0, 220))); ?><?php echo mb_strlen($desc) > 220 ? '…' : ''; ?></p>
            <?php endif; ?>
            <?php if ($terms !== ''): ?>
                <p class="pf-card-terms"><i class="fas fa-info-circle me-1"></i><?php echo htmlspecialchars(mb_substr($terms, 0, 140)); ?><?php echo mb_strlen($terms) > 140 ? '…' : ''; ?></p>
            <?php endif; ?>
            <div class="pf-card-actions">
                <?php if ($phone !== ''): ?>
                    <a href="tel:<?php echo htmlspecialchars(preg_replace('/\s+/', '', $phone)); ?>" class="pf-action-link"><i class="fas fa-phone"></i><?php echo htmlspecialchars($phone); ?></a>
                <?php endif; ?>
                <?php if ($email !== ''): ?>
                    <a href="mailto:<?php echo htmlspecialchars($email); ?>" class="pf-action-link"><i class="fas fa-envelope"></i><?php echo htmlspecialchars($email); ?></a>
                <?php endif; ?>
                <?php if ($web !== ''): ?>
                    <a href="<?php echo htmlspecialchars($web); ?>" class="pf-action-link" target="_blank" rel="noopener noreferrer"><i class="fas fa-globe"></i><?php echo isEnglish() ? 'Website' : 'वेबसाइट'; ?></a>
                <?php endif; ?>
                <?php
                $deskCode = trim((string)($f['partner_code'] ?? ''));
                $verifyHref = SITE_URL . 'verify.php' . ($deskCode !== '' ? ('?partner=' . rawurlencode($deskCode)) : '');
                ?>
                <a href="<?php echo htmlspecialchars($verifyHref); ?>" class="pf-action-link pf-action-verify"><i class="fas fa-shield-halved"></i><?php echo isEnglish() ? 'Desk verify' : 'डेस्क verify'; ?></a>
            </div>
        </article>
        <?php endforeach; ?>
        <div id="pfNoResultCards" class="pf-no-result pf-no-result-cards" style="display:none;">
            <i class="fas fa-search pf-no-result-icon"></i>
            <?php echo isEnglish() ? 'No partners matched your search.' : 'खोजसँग मिल्ने साझेदार भेटिएन।'; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="pf-table-wrap">
        <table class="pf-table" id="pfTable">
            <thead>
                <tr>
                    <th class="pf-th-sn"><?php echo isEnglish() ? '#' : 'क्र.स.'; ?></th>
                    <th><?php echo isEnglish() ? 'Partner' : 'साझेदार संस्था'; ?></th>
                    <th><?php echo isEnglish() ? 'Location' : 'स्थान'; ?></th>
                    <th><?php echo isEnglish() ? 'Type' : 'प्रकार'; ?></th>
                    <th class="pf-th-center"><?php echo isEnglish() ? 'Discount' : 'छुट'; ?></th>
                    <th><?php echo isEnglish() ? 'Contact' : 'सम्पर्क'; ?></th>
                    <th><?php echo isEnglish() ? 'Details' : 'विवरण'; ?></th>
                </tr>
            </thead>
            <tbody id="pfTbody">
                <?php $sn = 1; foreach ($filtered as $f):
                    $name = partnerFacilityDisplayName($f);
                    $desc = partnerFacilityDescription($f);
                    $disc = partnerDiscountDisplay($f);
                    $phone = trim((string)($f['contact_phone'] ?? ''));
                ?>
                <tr>
                    <td class="pf-td-sn"><?php echo $sn++; ?></td>
                    <td>
                        <div class="pf-org-name"><?php echo htmlspecialchars($name); ?></div>
                        <?php if (!empty($f['is_featured'])): ?><span class="pf-featured-chip"><?php echo isEnglish() ? 'Featured' : 'विशेष'; ?></span><?php endif; ?>
                    </td>
                    <td>
                        <span class="pf-location">
                            <i class="fas fa-location-dot"></i>
                            <?php echo htmlspecialchars(($f['location'] ?? '') !== '' ? $f['location'] : '—'); ?>
                        </span>
                    </td>
                    <td>
                        <?php if (!empty($f['facility_type'])): ?>
                        <span class="pf-type-badge"><?php echo htmlspecialchars((string)$f['facility_type']); ?></span>
                        <?php else: echo '<span class="pf-muted-dash">—</span>'; endif; ?>
                    </td>
                    <td class="pf-th-center">
                        <?php if ($disc !== ''): ?>
                        <span class="pf-discount-badge"><?php echo htmlspecialchars($disc); ?></span>
                        <?php else: ?>
                        <span class="pf-muted-dash-sm">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="pf-td-contact">
                        <?php if ($phone !== ''): ?>
                            <a href="tel:<?php echo htmlspecialchars(preg_replace('/\s+/', '', $phone)); ?>"><?php echo htmlspecialchars($phone); ?></a>
                        <?php else: ?>
                            <span class="pf-muted-dash">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="pf-td-desc">
                        <?php echo $desc !== '' ? nl2br(htmlspecialchars(mb_substr($desc, 0, 160))) : '—'; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr id="pfNoResult" style="display:none;">
                    <td colspan="7" class="pf-no-result">
                        <i class="fas fa-search pf-no-result-icon"></i>
                        <?php echo isEnglish() ? 'No partners matched your search.' : 'खोजसँग मिल्ने साझेदार भेटिएन।'; ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="pf-note">
        <i class="fas fa-circle-info"></i>
        <div>
            <?php echo isEnglish()
                ? 'Present your ' . htmlspecialchars($siteName) . ' member card at the partner desk. Partners can also confirm membership on the '
                : htmlspecialchars($siteName) . ' को सदस्य कार्ड साझेदार संस्थामा देखाउनुहोस्। साझेदारले सदस्यता '; ?>
            <a href="<?php echo SITE_URL; ?>verify.php"><?php echo isEnglish() ? 'verification page' : 'प्रमाणीकरण पृष्ठ'; ?></a><?php echo isEnglish() ? ' and log the service for your portal history.' : ' बाट पुष्टि गरी सेवा लग गर्न सक्छन् — इतिहास सदस्य पोर्टलमा देखिन्छ।'; ?>
        </div>
    </div>

    <?php endif; ?>

</div>
</section>

<script>
(function () {
    var countLabel = <?php echo json_encode(isEnglish() ? ' partners' : ' साझेदार'); ?>;
    window.pfSearchFn = function () {
        var q = (document.getElementById('pfSearch').value || '').toLowerCase().trim();
        var vis = 0;
        var cards = document.querySelectorAll('#pfCardGrid .pf-card');
        if (cards.length) {
            cards.forEach(function (c) {
                var blob = (c.getAttribute('data-search') || c.textContent || '').toLowerCase();
                var show = !q || blob.indexOf(q) !== -1;
                c.style.display = show ? '' : 'none';
                if (show) vis++;
            });
            var nr = document.getElementById('pfNoResultCards');
            if (nr) nr.style.display = vis ? 'none' : '';
        } else {
            var rows = document.querySelectorAll('#pfTbody tr');
            rows.forEach(function (r) {
                if (r.id === 'pfNoResult') return;
                var show = !q || (r.textContent || '').toLowerCase().indexOf(q) !== -1;
                r.style.display = show ? '' : 'none';
                if (show) vis++;
            });
            var noRes = document.getElementById('pfNoResult');
            if (noRes) noRes.style.display = vis ? 'none' : '';
        }
        var el = document.getElementById('pfCount');
        if (el) el.textContent = vis + countLabel;
    };
})();
</script>

<?php require_once 'includes/footer.php'; ?>
