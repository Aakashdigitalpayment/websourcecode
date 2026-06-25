<?php
/**
 * Public: साझेदार सुविधाहरू — Partner Facilities
 * Table view with filter by सुविधा प्रकार
 */
require_once 'includes/config.php';
require_once 'includes/ensure-tables.php';
require_once 'includes/partner-facilities-tables.php';
$pageTitle = isEnglish() ? 'Partner Facilities' : 'साझेदार सुविधाहरू';
require_once 'includes/header.php';
$L = getLangStrings();

/* ── Load facilities ── */
$facilities = [];
$types      = [];
try {
    $db = getDB();
    ensurePartnerFacilitiesTables($db);

    $facilities = $db->query("SELECT * FROM partner_facilities WHERE is_active=1 ORDER BY display_order ASC, partner_name ASC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);
    $types      = array_unique(array_filter(array_column($facilities, 'facility_type')));
    sort($types);
} catch (\Throwable $e) { $facilities = []; $types = []; }

$activeType = trim($_GET['type'] ?? '');
$filtered = $activeType
    ? array_filter($facilities, fn($f) => $f['facility_type'] === $activeType)
    : $facilities;
?>

<!-- Page Banner -->
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

    <!-- Intro -->
    <div class="text-center mb-5">
        <div class="pf-hero-icon-wrap">
            <i class="fas fa-handshake pf-hero-icon"></i>
        </div>
        <h2 class="pf-hero-title">
            <?php echo isEnglish() ? 'Member Benefits at Partner Organizations' : 'साझेदार संस्थामा सदस्यले पाउने सुविधाहरू'; ?>
        </h2>
        <p class="text-muted" style="max-width:600px;margin:0 auto;">
            <?php echo isEnglish()
                ? 'As a member of Aakash Cooperative, enjoy exclusive discounts and benefits at our partner organizations.'
                : 'आकाश सहकारीको सदस्यको रूपमा हाम्रा साझेदार संस्थाहरूमा विशेष छुट तथा सुविधाहरू प्राप्त गर्नुहोस्।'; ?>
        </p>
    </div>

    <?php if (empty($facilities)): ?>
    <div class="text-center py-5">
        <div class="pf-empty-icon"><i class="fas fa-handshake"></i></div>
        <h4 class="pf-empty-title"><?php echo isEnglish() ? 'Coming Soon' : 'छिट्टै आउँदैछ'; ?></h4>
        <p class="text-muted"><?php echo isEnglish() ? 'Partner facility details will be published soon.' : 'साझेदार सुविधाको विवरण छिट्टै प्रकाशित गरिनेछ।'; ?></p>
    </div>
    <?php else: ?>

    <!-- Type Filter Pills -->
    <?php if (!empty($types)): ?>
    <div class="pf-filter-wrap">
        <a href="partner-facilities.php" class="pf-filter-pill <?php echo !$activeType ? 'active' : ''; ?>">
            <i class="fas fa-th-large me-1"></i><?php echo isEnglish() ? 'All' : 'सबै'; ?>
            <span class="pf-pill-count"><?php echo count($facilities); ?></span>
        </a>
        <?php foreach ($types as $t):
            $cnt = count(array_filter($facilities, fn($f) => $f['facility_type'] === $t));
        ?>
        <a href="?type=<?php echo urlencode($t); ?>"
           class="pf-filter-pill <?php echo $activeType===$t ? 'active' : ''; ?>">
            <?php echo htmlspecialchars($t); ?>
            <span class="pf-pill-count"><?php echo $cnt; ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Search bar -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div class="pf-search-wrap">
            <i class="fas fa-search pf-search-icon"></i>
            <input type="text" id="pfSearch" placeholder="<?php echo isEnglish() ? 'Search...' : 'संस्था, स्थान, विवरण खोज्नुहोस्...'; ?>"
                   class="pf-search-input"
                   oninput="pfSearchFn()">
        </div>
        <div id="pfCount" class="text-muted pf-count">
            <?php echo count($filtered); ?> <?php echo isEnglish() ? 'records' : 'रेकर्ड'; ?>
        </div>
    </div>

    <!-- Table -->
    <div class="pf-table-wrap">
        <table class="pf-table" id="pfTable">
            <thead>
                <tr>
                    <th class="pf-th-sn">क्र.स.</th>
                    <th><?php echo isEnglish() ? 'Partner Organization' : 'साझेदार संस्था'; ?></th>
                    <th><?php echo isEnglish() ? 'Location' : 'स्थान'; ?></th>
                    <th><?php echo isEnglish() ? 'Facility Type' : 'सुविधा प्रकार'; ?></th>
                    <th class="pf-th-center"><?php echo isEnglish() ? 'Discount' : 'छुट (%)'; ?></th>
                    <th><?php echo isEnglish() ? 'Details' : 'विवरण'; ?></th>
                </tr>
            </thead>
            <tbody id="pfTbody">
                <?php $sn = 1; foreach ($filtered as $f): ?>
                <tr>
                    <td class="pf-td-sn"><?php echo $sn++; ?></td>
                    <td>
                        <div class="pf-org-name"><?php echo htmlspecialchars($f['partner_name']); ?></div>
                    </td>
                    <td>
                        <span class="pf-location">
                            <i class="fas fa-location-dot"></i>
                            <?php echo htmlspecialchars($f['location'] ?: '—'); ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($f['facility_type']): ?>
                        <span class="pf-type-badge"><?php echo htmlspecialchars($f['facility_type']); ?></span>
                        <?php else: echo '<span class="pf-muted-dash">—</span>'; endif; ?>
                    </td>
                    <td class="pf-th-center">
                        <?php if ($f['discount_percent'] > 0): ?>
                        <span class="pf-discount-badge">
                            <?php echo number_format($f['discount_percent'], 0); ?>% <small>छुट</small>
                        </span>
                        <?php else: ?>
                        <span class="pf-muted-dash-sm">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="pf-td-desc">
                        <?php echo nl2br(htmlspecialchars($f['description'] ?? '—')); ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($filtered)): ?>
                <tr id="pfNoResult">
                    <td colspan="6" class="pf-no-result">
                        <i class="fas fa-search pf-no-result-icon"></i>
                        कुनै रेकर्ड फेला परेन।
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Info note -->
    <div class="pf-note">
        <i class="fas fa-circle-info"></i>
        <?php echo isEnglish()
            ? 'To avail these discounts, please present your Aakash Cooperative member card at the partner organization.'
            : 'यी सुविधाहरू प्राप्त गर्न साझेदार संस्थामा आकाश सहकारीको सदस्य कार्ड देखाउनुहोस्।'; ?>
    </div>

    <?php endif; ?>

</div>
</section>


<script>
function pfSearchFn() {
    const q   = document.getElementById('pfSearch').value.toLowerCase();
    const rows = document.querySelectorAll('#pfTbody tr');
    let vis = 0;
    rows.forEach(r => {
        if (!r.id) {
            const show = !q || r.textContent.toLowerCase().includes(q);
            r.style.display = show ? '' : 'none';
            if (show) vis++;
        }
    });
    const noRes = document.getElementById('pfNoResult');
    if (noRes) noRes.style.display = vis ? 'none' : '';
    document.getElementById('pfCount').textContent = vis + ' रेकर्ड';
}
</script>

<?php require_once 'includes/footer.php'; ?>
