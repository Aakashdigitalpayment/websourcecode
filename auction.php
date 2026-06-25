<?php
require_once 'includes/config.php';
require_once 'includes/ensure-tables.php';
$pageTitle = isEnglish() ? 'Auction Notices' : 'लिलामी सूचना';
require_once 'includes/header.php';
$L = getLangStrings();

// Get auctions
try {
    $db = getDB();
    $auctions = $db->query("SELECT * FROM auction_notices WHERE is_active = 1 ORDER BY auction_date DESC LIMIT 10")->fetchAll();
} catch (Exception $e) {
    $auctions = [];
}

// Compute status counts for hero stats and filter bar
$statusCounts = ['all' => 0, 'ongoing' => 0, 'upcoming' => 0, 'completed' => 0, 'cancelled' => 0];
foreach ($auctions as $_a) {
    $statusCounts['all']++;
    $_s = $_a['status'] ?? 'upcoming';
    if (isset($statusCounts[$_s])) $statusCounts[$_s]++;
}
unset($_a, $_s);

// Handle bid submission
$bidSuccess = false;
$bidError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_bid'])) {
    if (!verifyCSRFToken()) {
        $bidError = isEnglish() ? 'Security check failed.' : 'सुरक्षा जाँच असफल।';
    } elseif (!checkRateLimit('auction_bid', 5, 60)) {
        $bidError = isEnglish() ? 'Too many requests.' : 'धेरै अनुरोधहरू।';
    } elseif (!empty($_POST['bid_form_token']) && isset($_SESSION['last_bid_form_token']) && $_SESSION['last_bid_form_token'] === $_POST['bid_form_token']) {
        $bidError = isEnglish() ? 'This bid was already submitted. Please refresh before submitting again.' : 'यो बोलपत्र पहिले नै पेश भइसकेको छ। फेरि पेश गर्न पेज refresh गर्नुहोस्।';
    } else {
        $auction_id = intval($_POST['auction_id'] ?? 0);
        $bidder_name = clean_text($_POST['bidder_name'] ?? '', 200);
        $bidder_phone = preg_replace('/[^0-9]/', '', clean_text($_POST['bidder_phone'] ?? '', 20));
        $bidder_email = strtolower(clean_text($_POST['bidder_email'] ?? '', 254));
        $bidder_address = clean_text($_POST['bidder_address'] ?? '', 500);
        $bid_amount = floatval($_POST['bid_amount'] ?? 0);
        $message = clean_text($_POST['message'] ?? '', 2000);

        if (empty($bidder_name) || empty($bidder_phone) || $bid_amount <= 0) {
            $bidError = isEnglish() ? 'Please fill all required fields.' : 'कृपया सबै आवश्यक फिल्डहरू भर्नुहोस्।';
        } else {
            try {
                $db = getDB();
                $stmt = $db->prepare("INSERT INTO auction_bids (auction_id, bidder_name, bidder_phone, bidder_email, bidder_address, bid_amount, message) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$auction_id, $bidder_name, $bidder_phone, $bidder_email, $bidder_address, $bid_amount, $message]);
                $_SESSION['last_bid_form_token'] = $_POST['bid_form_token'] ?? '';
                $bidSuccess = true;
            } catch (Exception $e) {
                $bidError = isEnglish() ? 'Failed to submit bid.' : 'बोलपत्र पेश गर्न सकिएन।';
            }
        }
    }
}
?>

<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><?php echo $pageTitle; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item active"><?php echo $pageTitle; ?></li>
            </ol>
        </nav>
    </div>
</section>

<!-- ===== Auction Section ===== -->
<section class="auc2-hero-section" style="background:linear-gradient(135deg,var(--primary-color),var(--primary-dark,var(--primary-color)));padding:3rem 0 2rem;">
    <div class="container">
        <div class="auc2-hero-txt" style="text-align:center;color:#fff;">
            <h1 class="auc2-h" style="color:#fff;font-size:clamp(1.6rem,4vw,2.4rem);font-weight:800;margin-bottom:.6rem;"><?php echo $pageTitle; ?></h1>
            <p class="auc2-sub" style="color:rgba(255,255,255,.9);font-size:1.05rem;margin-bottom:1.4rem;"><?php echo isEnglish() ? 'Browse active property auctions &mdash; view details, photos, and submit your bid.' : 'सक्रिय लिलामी सम्पत्तिहरू हेर्नुहोस् &mdash; विवरण, तस्बिर हेर्नुहोस् र बोलपत्र पेश गर्नुहोस्।'; ?></p>
        </div>
            <?php if (!empty($auctions)): ?>
            <div class="auc2-hero-stats">
                <div class="auc2-hstat">
                    <div class="auc2-hstat-num"><?php echo $statusCounts['all']; ?></div>
                    <div class="auc2-hstat-lbl"><?php echo isEnglish() ? 'Total' : 'जम्मा'; ?></div>
                </div>
                <?php if ($statusCounts['ongoing'] > 0): ?>
                <div class="auc2-hstat">
                    <div class="auc2-hstat-num green"><?php echo $statusCounts['ongoing']; ?></div>
                    <div class="auc2-hstat-lbl"><?php echo isEnglish() ? 'Live' : 'जारी'; ?></div>
                </div>
                <?php endif; ?>
                <?php if ($statusCounts['upcoming'] > 0): ?>
                <div class="auc2-hstat">
                    <div class="auc2-hstat-num yellow"><?php echo $statusCounts['upcoming']; ?></div>
                    <div class="auc2-hstat-lbl"><?php echo isEnglish() ? 'Upcoming' : 'आगामी'; ?></div>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>


<!-- Filter Bar -->
<?php if (!empty($auctions)): ?>
<div class="auc2-filterbar">
    <div class="container">
        <!-- Search box -->
        <div class="auc2-search-wrap">
            <span class="auc2-search-icon"><i class="fas fa-search"></i></span>
            <input type="search" id="aucSearchInput" class="auc2-search-input"
                   placeholder="<?php echo isEnglish() ? 'Search by title, location, property type...' : 'शीर्षक, स्थान, सम्पत्ति प्रकारले खोज्नुहोस्...'; ?>"
                   oninput="aucApplyFilters()" autocomplete="off">
            <button class="auc2-search-clear" id="aucSearchClear" onclick="aucClearSearch()" title="<?php echo isEnglish()?'Clear':'खाली गर्नुहोस्'; ?>" style="display:none;">
                <i class="fas fa-times"></i>
            </button>
        </div>
        <!-- Status chips -->
        <span class="auc2-fbar-label"><i class="fas fa-filter"></i> <?php echo isEnglish() ? 'Filter:' : 'छान्नुहोस्:'; ?></span>
        <div class="auc2-fchips">
            <button class="auc2-fchip active" data-auc-filter="all" onclick="aucFilter(this,'all')">
                <i class="fas fa-th-large"></i> <?php echo isEnglish() ? 'All' : 'सबै'; ?>
                <span class="auc2-fcount"><?php echo $statusCounts['all']; ?></span>
            </button>
            <?php if ($statusCounts['ongoing'] > 0): ?>
            <button class="auc2-fchip" data-auc-filter="ongoing" onclick="aucFilter(this,'ongoing')">
                <i class="fas fa-circle" style="font-size:.55em;color:#198754;"></i> <?php echo isEnglish() ? 'Ongoing' : 'जारी'; ?>
                <span class="auc2-fcount"><?php echo $statusCounts['ongoing']; ?></span>
            </button>
            <?php endif; ?>
            <?php if ($statusCounts['upcoming'] > 0): ?>
            <button class="auc2-fchip" data-auc-filter="upcoming" onclick="aucFilter(this,'upcoming')">
                <i class="fas fa-clock"></i> <?php echo isEnglish() ? 'Upcoming' : 'आगामी'; ?>
                <span class="auc2-fcount"><?php echo $statusCounts['upcoming']; ?></span>
            </button>
            <?php endif; ?>
            <?php if ($statusCounts['completed'] > 0): ?>
            <button class="auc2-fchip" data-auc-filter="completed" onclick="aucFilter(this,'completed')">
                <i class="fas fa-check-circle"></i> <?php echo isEnglish() ? 'Completed' : 'सम्पन्न'; ?>
                <span class="auc2-fcount"><?php echo $statusCounts['completed']; ?></span>
            </button>
            <?php endif; ?>
            <?php if ($statusCounts['cancelled'] > 0): ?>
            <button class="auc2-fchip" data-auc-filter="cancelled" onclick="aucFilter(this,'cancelled')">
                <i class="fas fa-ban"></i> <?php echo isEnglish() ? 'Cancelled' : 'रद्द'; ?>
                <span class="auc2-fcount"><?php echo $statusCounts['cancelled']; ?></span>
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<section class="section-padding">
<div class="container">

    <?php if ($bidSuccess): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="fas fa-check-circle me-2"></i>
        <?php echo isEnglish() ? 'Your bid has been submitted successfully!' : 'तपाईंको बोलपत्र सफलतापूर्वक पेश भयो!'; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($bidError): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo $bidError; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if (empty($auctions)): ?>
    <div class="text-center py-5">
        <i class="fas fa-gavel fa-4x text-muted mb-3 d-block"></i>
        <h4 class="text-muted"><?php echo isEnglish() ? 'No Auction Notices Available' : 'कुनै लिलामी सूचना उपलब्ध छैन'; ?></h4>
        <p class="text-muted"><?php echo isEnglish() ? 'Please check back later.' : 'कृपया पछि पुनः हेर्नुहोस्।'; ?></p>
    </div>
    <?php else: ?>

    <?php
    $statusLabels = [
        'upcoming'  => isEnglish() ? 'Upcoming'  : 'आगामी',
        'ongoing'   => isEnglish() ? 'Ongoing'   : 'जारी',
        'completed' => isEnglish() ? 'Completed' : 'सम्पन्न',
        'cancelled' => isEnglish() ? 'Cancelled' : 'रद्द',
    ];
    foreach ($auctions as $aIdx => $auction):
        /* ── Images ── */
        $auctionImages = [];
        if (!empty($auction['image']))  $auctionImages[] = $auction['image'];
        if (!empty($auction['images'])) {
            $extra = json_decode($auction['images'], true);
            if (is_array($extra)) $auctionImages = array_merge($auctionImages, $extra);
        }

        /* ── Area display ── */
        $aAreaParts = [];
        if (!empty($auction['area_bigha'])  && $auction['area_bigha']  > 0) $aAreaParts[] = number_format((float)$auction['area_bigha'],2,'.','').' '.(isEnglish()?'Bigha':'बिगाहा');
        if (!empty($auction['area_ropani']) && $auction['area_ropani'] > 0) $aAreaParts[] = (int)$auction['area_ropani'].' '.(isEnglish()?'Ropani':'रोपनी');
        if (!empty($auction['area_aana'])   && $auction['area_aana']   > 0) $aAreaParts[] = (int)$auction['area_aana'].' '.(isEnglish()?'Aana':'आना');
        if (!empty($auction['area_paisa'])  && $auction['area_paisa']  > 0) $aAreaParts[] = (int)$auction['area_paisa'].' '.(isEnglish()?'Paisa':'पैसा');
        $aAreaDisplay = !empty($aAreaParts) ? implode(' ', $aAreaParts) : htmlspecialchars($auction['area'] ?? '');

        $aId    = $auction['id'];
        $status = $auction['status'] ?? 'upcoming';
        $hasBid = ($status === 'upcoming' || $status === 'ongoing');
        $hasDoc = !empty($auction['document']);
        $hasMap = !empty($auction['google_map_embed']) || !empty($auction['google_map_link']);
        $hasPhotos = count($auctionImages) > 1;

        /* Auction date countdown */
        $auctionTs   = strtotime($auction['auction_date']);
        $nowTs       = time();
        $diffSec     = $auctionTs - $nowTs;
        $showCountdown = ($status === 'upcoming' && $diffSec > 0 && $diffSec < 30*24*3600);
    ?>

    <?php
        $_searchText = implode(' ', array_filter([
            $auction['title']    ?? '',
            $auction['title_en'] ?? '',
            $auction['title_np'] ?? '',
            $auction['location'] ?? '',
            $auction['property_type'] ?? '',
        ]));
    ?>
    <div class="auc2-wrap" id="auction-<?php echo $aId; ?>"
         data-auc-status="<?php echo htmlspecialchars($status); ?>"
         data-auc-text="<?php echo htmlspecialchars(mb_strtolower($_searchText), ENT_QUOTES, 'UTF-8'); ?>">
    <div class="auc2-card">

        <!-- ── Hero ── -->
        <div class="auc2-hero">

            <!-- Gallery pane -->
            <div class="auc2-gallery-pane">
                <?php if (!empty($auctionImages)): ?>
                <img
                    src="<?php echo SITE_URL . $auctionImages[0]; ?>"
                    class="auc2-main-img"
                    id="auc2-main-<?php echo $aId; ?>"
                    alt="<?php echo htmlspecialchars(getLangField($auction,'title')); ?>"
                    loading="lazy"
                    onclick="auc2Lightbox(this.src)">

                <?php if (count($auctionImages) > 1): ?>
                <div class="auc2-thumbs" id="auc2-thumbs-<?php echo $aId; ?>">
                    <?php foreach ($auctionImages as $tIdx => $tImg): ?>
                    <img src="<?php echo SITE_URL . $tImg; ?>"
                         class="<?php echo $tIdx===0?'active':''; ?>"
                         loading="lazy"
                         alt="Photo <?php echo $tIdx+1; ?>"
                         onclick="auc2SetMain(<?php echo $aId; ?>, this, '<?php echo SITE_URL.$tImg; ?>')">
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <div class="auc2-no-img">
                    <i class="fas fa-image fa-3x mb-2"></i>
                    <span style="font-size:.85rem;"><?php echo isEnglish()?'No Photos':'तस्बिर उपलब्ध छैन'; ?></span>
                </div>
                <?php endif; ?>
            </div>

            <!-- Summary pane -->
            <div class="auc2-summary-pane">

                <div class="auc2-status-row">
                    <span class="auc2-badge-status s-<?php echo htmlspecialchars($status); ?>">
                        <?php if ($status==='ongoing'): ?><i class="fas fa-circle fa-xs me-1"></i><?php endif; ?>
                        <?php echo $statusLabels[$status] ?? $status; ?>
                    </span>
                    <span class="auc2-serial text-muted">
                        <i class="fas fa-hashtag fa-xs"></i> <?php echo isEnglish()?'Auction No.':'लिलामी नं.'; ?> <?php echo str_pad($aIdx+1, 3, '0', STR_PAD_LEFT); ?>
                    </span>
                </div>

                <h2 class="auc2-title"><?php echo htmlspecialchars(getLangField($auction,'title')); ?></h2>

                <!-- Info grid -->
                <div class="auc2-info-grid">
                    <?php if (!empty($auction['minimum_price'])): ?>
                    <div class="auc2-info-item price-item" style="grid-column:1/-1">
                        <div class="auc2-info-label"><i class="fas fa-tag"></i> <?php echo isEnglish()?'Minimum Price':'न्यूनतम मूल्य'; ?></div>
                        <div class="auc2-price-value">रु. <?php echo number_format((float)$auction['minimum_price']); ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($auction['auction_date'])): ?>
                    <div class="auc2-info-item highlight">
                        <div class="auc2-info-label"><i class="fas fa-calendar-alt"></i> <?php echo isEnglish()?'Auction Date':'लिलामी मिति'; ?></div>
                        <div class="auc2-info-value"><?php echo date('Y-m-d', strtotime($auction['auction_date'])); ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($auction['auction_time'])): ?>
                    <div class="auc2-info-item">
                        <div class="auc2-info-label"><i class="fas fa-clock"></i> <?php echo isEnglish()?'Time':'समय'; ?></div>
                        <div class="auc2-info-value"><?php echo htmlspecialchars($auction['auction_time']); ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($auction['property_type'])): ?>
                    <div class="auc2-info-item">
                        <div class="auc2-info-label"><i class="fas fa-home"></i> <?php echo isEnglish()?'Type':'प्रकार'; ?></div>
                        <div class="auc2-info-value"><?php echo htmlspecialchars($auction['property_type']); ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($aAreaDisplay)): ?>
                    <div class="auc2-info-item">
                        <div class="auc2-info-label"><i class="fas fa-ruler-combined"></i> <?php echo isEnglish()?'Area':'क्षेत्रफल'; ?></div>
                        <div class="auc2-info-value"><?php echo $aAreaDisplay; ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($auction['location'])): ?>
                    <div class="auc2-info-item" style="grid-column:1/-1">
                        <div class="auc2-info-label"><i class="fas fa-map-marker-alt"></i> <?php echo isEnglish()?'Location':'स्थान'; ?></div>
                        <div class="auc2-info-value">
                            <?php echo htmlspecialchars($auction['location']); ?>
                            <?php if (!empty($auction['google_map_link'])): ?>
                            <a href="<?php echo htmlspecialchars($auction['google_map_link']); ?>"
                               target="_blank" rel="noopener"
                               class="badge bg-danger text-decoration-none ms-2" style="font-size:.68rem;vertical-align:middle;">
                                <i class="fas fa-map me-1"></i><?php echo isEnglish()?'Map':'नक्सा'; ?>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($showCountdown): ?>
                <!-- Countdown -->
                <div class="mb-2" style="font-size:.78rem;color:#6c757d;margin-bottom:.3rem;">
                    <i class="fas fa-hourglass-half me-1 text-warning"></i>
                    <?php echo isEnglish()?'Time remaining:':'बाँकी समय:'; ?>
                </div>
                <div class="auc2-countdown"
                     data-auc2-countdown="<?php echo $auctionTs; ?>"
                     id="auc2-cd-<?php echo $aId; ?>">
                    <div class="auc2-cd-box"><div class="auc2-cd-num" id="auc2-cd-d-<?php echo $aId; ?>">--</div><div class="auc2-cd-lbl"><?php echo isEnglish()?'Days':'दिन'; ?></div></div>
                    <div class="auc2-cd-box"><div class="auc2-cd-num" id="auc2-cd-h-<?php echo $aId; ?>">--</div><div class="auc2-cd-lbl"><?php echo isEnglish()?'Hrs':'घन्टा'; ?></div></div>
                    <div class="auc2-cd-box"><div class="auc2-cd-num" id="auc2-cd-m-<?php echo $aId; ?>">--</div><div class="auc2-cd-lbl"><?php echo isEnglish()?'Min':'मिन'; ?></div></div>
                    <div class="auc2-cd-box"><div class="auc2-cd-num" id="auc2-cd-s-<?php echo $aId; ?>">--</div><div class="auc2-cd-lbl"><?php echo isEnglish()?'Sec':'सेक'; ?></div></div>
                </div>
                <?php endif; ?>

                <!-- CTA -->
                <div class="auc2-cta">
                    <?php if ($hasBid): ?>
                    <button class="auc2-bid-btn" data-bs-toggle="modal" data-bs-target="#bidModal<?php echo $aId; ?>">
                        <i class="fas fa-gavel"></i>
                        <span><?php echo isEnglish() ? 'Place Bid / Inquiry' : 'बोलपत्र / जिज्ञासा राख्नुहोस्'; ?></span>
                    </button>
                    <?php else: ?>
                    <div class="alert alert-secondary mb-0 py-2 text-center" style="border-radius:10px;font-size:.9rem;">
                        <i class="fas fa-lock me-2"></i>
                        <?php echo $status==='completed'
                            ? (isEnglish()?'This auction has been completed.':'यो लिलामी सम्पन्न भइसकेको छ।')
                            : (isEnglish()?'This auction was cancelled.':'यो लिलामी रद्द भएको छ।'); ?>
                    </div>
                    <?php endif; ?>
                </div>

            </div><!-- /.auc2-summary-pane -->
        </div><!-- /.auc2-hero -->

        <!-- ── Tabs ── -->
        <div class="auc2-tabs-wrap">
            <div class="auc2-nav" role="tablist">
                <button class="auc2-tab-btn active"
                        data-auc2-tab="overview-<?php echo $aId; ?>"
                        onclick="auc2Tab(this,'overview-<?php echo $aId; ?>')">
                    <i class="fas fa-info-circle"></i> <?php echo isEnglish()?'Overview':'सारांश'; ?>
                </button>
                <?php if ($hasPhotos): ?>
                <button class="auc2-tab-btn"
                        data-auc2-tab="photos-<?php echo $aId; ?>"
                        onclick="auc2Tab(this,'photos-<?php echo $aId; ?>')">
                    <i class="fas fa-images"></i> <?php echo isEnglish()?'Photos':'तस्बिरहरू'; ?>
                    <span class="badge bg-secondary" style="font-size:.65rem;"><?php echo count($auctionImages); ?></span>
                </button>
                <?php endif; ?>
                <?php if ($hasDoc): ?>
                <button class="auc2-tab-btn"
                        data-auc2-tab="docs-<?php echo $aId; ?>"
                        onclick="auc2Tab(this,'docs-<?php echo $aId; ?>')">
                    <i class="fas fa-file-alt"></i> <?php echo isEnglish()?'Document':'कागजपत्र'; ?>
                </button>
                <?php endif; ?>
                <?php if ($hasMap): ?>
                <button class="auc2-tab-btn"
                        data-auc2-tab="map-<?php echo $aId; ?>"
                        onclick="auc2Tab(this,'map-<?php echo $aId; ?>')">
                    <i class="fas fa-map-marked-alt"></i> <?php echo isEnglish()?'Map':'नक्सा'; ?>
                </button>
                <?php endif; ?>
            </div>

            <!-- Overview tab -->
            <div class="auc2-tab-pane active" id="overview-<?php echo $aId; ?>">
                <?php $desc = getLangField($auction,'description'); ?>
                <?php if (!empty($desc)): ?>
                <div class="auc2-desc-block">
                    <?php echo nl2br(htmlspecialchars($desc)); ?>
                </div>
                <?php endif; ?>

                <?php if (!empty($auction['contact_person']) || !empty($auction['contact_phone'])): ?>
                <div class="auc2-contact-row">
                    <span style="font-size:.8rem;font-weight:700;color:#6c757d;text-transform:uppercase;letter-spacing:.4px;margin-right:.5rem;">
                        <i class="fas fa-headset me-1"></i><?php echo isEnglish()?'Contact':'सम्पर्क'; ?>:
                    </span>
                    <?php if (!empty($auction['contact_person'])): ?>
                    <span class="auc2-contact-item"><i class="fas fa-user"></i><?php echo htmlspecialchars($auction['contact_person']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($auction['contact_phone'])): ?>
                    <span class="auc2-contact-item">
                        <i class="fas fa-phone"></i>
                        <a href="tel:<?php echo htmlspecialchars($auction['contact_phone']); ?>">
                            <?php echo htmlspecialchars($auction['contact_phone']); ?>
                        </a>
                    </span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if (empty($desc) && empty($auction['contact_person'])): ?>
                <div class="auc2-empty-tab">
                    <i class="fas fa-info-circle"></i>
                    <?php echo isEnglish()?'No additional details available.':'थप विवरण उपलब्ध छैन।'; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Photos tab -->
            <?php if ($hasPhotos): ?>
            <div class="auc2-tab-pane" id="photos-<?php echo $aId; ?>">
                <div class="auc2-photo-grid">
                    <?php foreach ($auctionImages as $pImg): ?>
                    <img src="<?php echo SITE_URL.$pImg; ?>"
                         loading="lazy"
                         alt="<?php echo htmlspecialchars(getLangField($auction,'title')); ?>"
                         onclick="auc2Lightbox(this.src)">
                    <?php endforeach; ?>
                </div>
                <p class="text-muted mt-3 mb-0" style="font-size:.8rem;">
                    <i class="fas fa-hand-pointer me-1"></i><?php echo isEnglish()?'Click any photo to enlarge':'ठूलो हेर्न तस्बिरमा क्लिक गर्नुहोस्'; ?>
                </p>
            </div>
            <?php endif; ?>

            <!-- Documents tab -->
            <?php if ($hasDoc): ?>
            <div class="auc2-tab-pane" id="docs-<?php echo $aId; ?>">
                <div class="auc2-doc-list">
                    <div class="auc2-doc-item">
                        <div class="auc2-doc-icon"><i class="fas fa-file-pdf"></i></div>
                        <div class="auc2-doc-info">
                            <div class="auc2-doc-name"><?php echo isEnglish()?'Official Auction Notice':'आधिकारिक लिलामी सूचना'; ?></div>
                            <div class="auc2-doc-desc"><?php echo isEnglish()?'Click to download or view the official document.':'सरकारी कागजात डाउनलोड गर्न वा हेर्न क्लिक गर्नुहोस्।'; ?></div>
                        </div>
                        <a href="<?php echo SITE_URL.htmlspecialchars($auction['document']); ?>"
                           target="_blank" rel="noopener"
                           class="btn btn-danger btn-sm" style="white-space:nowrap;">
                            <i class="fas fa-download me-1"></i><?php echo isEnglish()?'Download':'डाउनलोड'; ?>
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Map tab -->
            <?php if ($hasMap): ?>
            <div class="auc2-tab-pane" id="map-<?php echo $aId; ?>">
                <?php if (!empty($auction['google_map_embed'])): ?>
                <div class="auc2-map-container">
                    <?php echo $auction['google_map_embed']; ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($auction['google_map_link'])): ?>
                <div class="auc2-map-link-wrap">
                    <a href="<?php echo htmlspecialchars($auction['google_map_link']); ?>"
                       target="_blank" rel="noopener"
                       class="btn btn-outline-danger btn-sm">
                        <i class="fas fa-external-link-alt me-1"></i>
                        <?php echo isEnglish()?'Open in Google Maps':'Google Maps मा खोल्नुहोस्'; ?>
                    </a>
                </div>
                <?php endif; ?>
                <?php if (empty($auction['google_map_embed']) && empty($auction['google_map_link'])): ?>
                <div class="auc2-empty-tab">
                    <i class="fas fa-map-marked-alt"></i>
                    <?php echo isEnglish()?'No map available.':'नक्सा उपलब्ध छैन।'; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div><!-- /.auc2-tabs-wrap -->
    </div><!-- /.auc2-card -->
    </div><!-- /.auc2-wrap -->

    <?php if ($hasBid): ?>
    <!-- Bid Modal -->
    <div class="modal fade" id="bidModal<?php echo $aId; ?>" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header auc2-bid-modal-head">
                    <h5 class="modal-title auc2-bid-modal-title">
                        <i class="fas fa-gavel me-2"></i>
                        <?php echo isEnglish() ? 'Submit Bid / Inquiry' : 'बोलपत्र / जिज्ञासा पेश'; ?>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" novalidate class="bid-modal-form auc2-bid-form needs-validation">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="bid_form_token" value="<?php echo bin2hex(random_bytes(12)); ?>">
                    <input type="hidden" name="auction_id" value="<?php echo $aId; ?>">
                    <input type="hidden" name="submit_bid" value="1">
                    <div class="modal-body">
                        <div class="auc2-bid-note py-2 px-3 mb-3 small">
                            <i class="fas fa-gavel me-1"></i>
                            <?php echo isEnglish() ? 'Minimum bid amount:' : 'न्यूनतम बोलपत्र रकम:'; ?>
                            <strong class="auc2-bid-min-amt"> रु. <?php echo number_format($auction['minimum_price']); ?></strong>
                        </div>
                        <div class="mb-3">
                            <label class="form-label auc2-bid-label"><?php echo isEnglish()?'Full Name':'पूरा नाम'; ?> <span class="auc2-req">*</span></label>
                            <input type="text" name="bidder_name" class="form-control auc2-bid-input"
                                   placeholder="<?php echo isEnglish()?'Enter your full name':'आफ्नो पूरा नाम लेख्नुहोस्'; ?>"
                                   required minlength="2" maxlength="120">
                        </div>
                        <div class="mb-3">
                            <label class="form-label auc2-bid-label"><?php echo isEnglish()?'Mobile Number':'मोबाइल नम्बर'; ?> <span class="auc2-req">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text auc2-bid-addon"><i class="fas fa-phone"></i></span>
                                <input type="tel" name="bidder_phone" class="form-control auc2-bid-input"
                                       placeholder="98XXXXXXXX" pattern="[9][0-9]{9}"
                                       maxlength="10" minlength="10" inputmode="numeric" required
                                       title="<?php echo isEnglish()?'10-digit Nepal mobile':'९ बाट शुरु हुने १० अंकको नम्बर'; ?>">
                            </div>
                            <div class="auc2-bid-help"><i class="fas fa-info-circle"></i> <?php echo isEnglish()?'10-digit Nepal mobile starting with 9':'९ बाट शुरु हुने १० अंकको नम्बर'; ?></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label auc2-bid-label"><?php echo isEnglish()?'Email':'इमेल'; ?></label>
                            <div class="input-group">
                                <span class="input-group-text auc2-bid-addon"><i class="fas fa-envelope"></i></span>
                                <input type="email" name="bidder_email" class="form-control auc2-bid-input" placeholder="name@email.com" maxlength="150">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label auc2-bid-label"><?php echo isEnglish()?'Address':'ठेगाना'; ?></label>
                            <input type="text" name="bidder_address" class="form-control auc2-bid-input"
                                   placeholder="<?php echo isEnglish()?'District, Municipality':'जिल्ला, गाउँपालिका/नगरपालिका'; ?>" maxlength="200">
                        </div>
                        <div class="mb-3">
                            <label class="form-label auc2-bid-label"><?php echo isEnglish()?'Bid Amount (Rs.)':'बोलपत्र रकम (रु.)'; ?> <span class="auc2-req">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text auc2-bid-addon">रु.</span>
                                <input type="number" name="bid_amount" class="form-control auc2-bid-input"
                                       min="<?php echo $auction['minimum_price']; ?>"
                                       step="1" inputmode="numeric"
                                       placeholder="<?php echo number_format($auction['minimum_price']); ?>" required>
                            </div>
                            <div class="auc2-bid-help-warn"><i class="fas fa-exclamation-circle"></i> <?php echo isEnglish()?'Minimum bid: Rs.':'न्यूनतम रकम: रु.'; ?> <?php echo number_format($auction['minimum_price']); ?></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label auc2-bid-label"><?php echo isEnglish()?'Message / Query':'सन्देश / जिज्ञासा'; ?></label>
                            <textarea name="message" class="form-control auc2-bid-textarea" rows="3" maxlength="500"
                                      placeholder="<?php echo isEnglish()?'Optional message...':'थप जानकारी वा जिज्ञासा...'; ?>"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer auc2-bid-footer">
                        <button type="button" class="btn auc2-bid-cancel" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i><?php echo isEnglish()?'Cancel':'रद्द'; ?>
                        </button>
                        <button type="submit" class="btn auc2-bid-submit bid-submit-btn">
                            <i class="fas fa-gavel me-1"></i><?php echo isEnglish()?'Submit Bid':'बोलपत्र पेश गर्नुहोस्'; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php endforeach; ?>

    <!-- No-filter results state -->
    <div class="auc2-no-filter" id="aucNoResults">
        <i class="fas fa-search fa-2x text-muted mb-3 d-block"></i>
        <h5 id="aucNoResultsMsg"><?php echo isEnglish() ? 'No auctions match your search.' : 'तपाईंको खोजमा कुनै लिलामी भेटिएन।'; ?></h5>
        <button class="btn btn-outline-secondary btn-sm mt-2" onclick="aucResetAll()">
            <i class="fas fa-redo me-1"></i><?php echo isEnglish() ? 'Clear Filters' : 'फिल्टर हटाउनुहोस्'; ?>
        </button>
    </div>

    <?php endif; /* end !empty($auctions) */ ?>

</div><!-- /.container -->
</section>

<style>
/* ─── Auction Search ─── */
.auc2-filterbar .container{flex-wrap:wrap;gap:.5rem;}
.auc2-search-wrap{position:relative;display:flex;align-items:center;width:100%;max-width:420px;margin-bottom:.25rem;}
.auc2-search-icon{position:absolute;left:.75rem;color:#888;pointer-events:none;font-size:.9rem;}
.auc2-search-input{width:100%;padding:.45rem 2.2rem .45rem 2.2rem;border:1.5px solid #d0d5dd;border-radius:2rem;font-size:.92rem;outline:none;transition:border-color .2s,box-shadow .2s;background:#fff;}
.auc2-search-input:focus{border-color:var(--primary-color,#2c6e49);box-shadow:0 0 0 3px rgba(44,110,73,.13);}
.auc2-search-input::-webkit-search-cancel-button{display:none;}
.auc2-search-clear{position:absolute;right:.55rem;background:none;border:none;color:#aaa;cursor:pointer;font-size:.85rem;padding:.2rem .3rem;line-height:1;}
.auc2-search-clear:hover{color:#555;}
.auc2-highlight{background:#fff3b0;border-radius:2px;padding:0 1px;}
@media(max-width:600px){.auc2-search-wrap{max-width:100%;}}
</style>
<script>
/* ─── Auction Search + Status Filter ─── */
var _aucActiveFilter = 'all';

function aucFilter(btn, filter) {
    _aucActiveFilter = filter;
    document.querySelectorAll('.auc2-fchip').forEach(function(c) {
        c.classList.remove('active','green','yellow','grey');
    });
    btn.classList.add('active');
    if (filter === 'ongoing')   btn.classList.add('green');
    if (filter === 'upcoming')  btn.classList.add('yellow');
    if (filter === 'completed' || filter === 'cancelled') btn.classList.add('grey');
    aucApplyFilters();
}

function aucApplyFilters() {
    var inp    = document.getElementById('aucSearchInput');
    var clrBtn = document.getElementById('aucSearchClear');
    var kw     = inp ? inp.value.trim().toLowerCase() : '';

    if (clrBtn) clrBtn.style.display = kw ? 'inline-block' : 'none';

    var wraps   = document.querySelectorAll('.auc2-wrap[data-auc-status]');
    var visible = 0;

    wraps.forEach(function(w) {
        var statusOk = (_aucActiveFilter === 'all' || w.getAttribute('data-auc-status') === _aucActiveFilter);
        var text     = (w.getAttribute('data-auc-text') || '').toLowerCase();
        var kwOk     = !kw || text.indexOf(kw) !== -1;

        if (statusOk && kwOk) {
            w.style.display = '';
            visible++;
            /* Highlight matching text in title */
            aucHighlight(w, kw);
        } else {
            w.style.display = 'none';
            aucHighlight(w, '');
        }
    });

    var noRes = document.getElementById('aucNoResults');
    if (noRes) noRes.style.display = visible === 0 ? 'block' : 'none';
}

function aucHighlight(wrap, kw) {
    var titleEl = wrap.querySelector('.auc2-title');
    if (!titleEl) return;
    var orig = titleEl.getAttribute('data-orig-text');
    if (!orig) {
        orig = titleEl.textContent;
        titleEl.setAttribute('data-orig-text', orig);
    }
    if (!kw) { titleEl.textContent = orig; return; }
    var escaped = kw.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    titleEl.innerHTML = orig.replace(
        new RegExp('(' + escaped + ')', 'gi'),
        '<mark class="auc2-highlight">$1</mark>'
    );
}

function aucClearSearch() {
    var inp = document.getElementById('aucSearchInput');
    if (inp) { inp.value = ''; inp.focus(); }
    aucApplyFilters();
}

function aucResetAll() {
    var inp = document.getElementById('aucSearchInput');
    if (inp) inp.value = '';
    _aucActiveFilter = 'all';
    var allBtn = document.querySelector('.auc2-fchip[data-auc-filter="all"]');
    if (allBtn) {
        document.querySelectorAll('.auc2-fchip').forEach(function(c){ c.classList.remove('active','green','yellow','grey'); });
        allBtn.classList.add('active');
    }
    aucApplyFilters();
}

function aucResetFilter() { aucResetAll(); }

/* Keyboard shortcut: "/" focuses search */
document.addEventListener('keydown', function(e) {
    if (e.key === '/' && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA') {
        var inp = document.getElementById('aucSearchInput');
        if (inp) { e.preventDefault(); inp.focus(); inp.select(); }
    }
});
</script>

<!-- Lightbox Overlay -->
<div id="auc2-lightbox" onclick="this.classList.remove('open')">
    <button id="auc2-lightbox-close" onclick="document.getElementById('auc2-lightbox').classList.remove('open')" aria-label="Close" title="Close">
        <i class="fas fa-times"></i>
    </button>
    <img id="auc2-lightbox-img" src="" alt="Photo">
</div>

<script>
/* ─── Auction v2 JS ─── */

/* Tab switcher */
function auc2Tab(btn, paneId) {
    var card = btn.closest('.auc2-card');
    card.querySelectorAll('.auc2-tab-btn').forEach(function(b){ b.classList.remove('active'); });
    card.querySelectorAll('.auc2-tab-pane').forEach(function(p){ p.classList.remove('active'); });
    btn.classList.add('active');
    var pane = document.getElementById(paneId);
    if (pane) pane.classList.add('active');
}

/* Thumbnail → main image switch */
function auc2SetMain(aId, thumb, src) {
    var mainImg = document.getElementById('auc2-main-' + aId);
    if (mainImg) mainImg.src = src;
    var strip = document.getElementById('auc2-thumbs-' + aId);
    if (strip) strip.querySelectorAll('img').forEach(function(t){ t.classList.remove('active'); });
    thumb.classList.add('active');
}

/* Lightbox */
function auc2Lightbox(src) {
    document.getElementById('auc2-lightbox-img').src = src;
    document.getElementById('auc2-lightbox').classList.add('open');
}
document.addEventListener('keydown', function(e){ if(e.key==='Escape') document.getElementById('auc2-lightbox').classList.remove('open'); });

/* Countdown timers */
function auc2UpdateCountdowns() {
    document.querySelectorAll('[data-auc2-countdown]').forEach(function(el) {
        var ts = parseInt(el.getAttribute('data-auc2-countdown'), 10) * 1000;
        var diff = ts - Date.now();
        var aId = el.id.replace('auc2-cd-', '');
        if (diff <= 0) {
            ['d','h','m','s'].forEach(function(u){
                var e = document.getElementById('auc2-cd-'+u+'-'+aId);
                if(e) e.textContent='00';
            });
            return;
        }
        var d = Math.floor(diff/86400000);
        var h = Math.floor((diff%86400000)/3600000);
        var m = Math.floor((diff%3600000)/60000);
        var s = Math.floor((diff%60000)/1000);
        function pad(n){ return String(n).padStart(2,'0'); }
        var de = document.getElementById('auc2-cd-d-'+aId);
        var he = document.getElementById('auc2-cd-h-'+aId);
        var me = document.getElementById('auc2-cd-m-'+aId);
        var se = document.getElementById('auc2-cd-s-'+aId);
        if(de) de.textContent=pad(d);
        if(he) he.textContent=pad(h);
        if(me) me.textContent=pad(m);
        if(se) se.textContent=pad(s);
    });
}
auc2UpdateCountdowns();
setInterval(auc2UpdateCountdowns, 1000);

/* Bid form validation */
document.addEventListener('DOMContentLoaded', function() {

    document.querySelectorAll('.bid-modal-form').forEach(function(form) {
        var phoneInput  = form.querySelector('input[name="bidder_phone"]');
        var emailInput  = form.querySelector('input[name="bidder_email"]');
        var bidAmtInput = form.querySelector('input[name="bid_amount"]');

        if (phoneInput) {
            phoneInput.addEventListener('input', function() {
                this.value = this.value.replace(/[^0-9]/g,'').slice(0,10);
            });
            phoneInput.addEventListener('blur', function() {
                var v = this.value.trim();
                this.classList.toggle('is-invalid', v.length>0 && (v.length!==10||v[0]!=='9'));
                if (v.length===10 && v[0]==='9') this.classList.add('is-valid');
            });
        }

        if (emailInput) {
            emailInput.addEventListener('blur', function() {
                var v = this.value.trim();
                if (v && !v.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
                    this.classList.add('is-invalid');
                } else {
                    this.classList.remove('is-invalid');
                    if (v) this.classList.add('is-valid');
                }
            });
        }

        form.addEventListener('submit', function(e) {
            var ok = true;
            if (phoneInput && !phoneInput.value.trim().match(/^[9][0-9]{9}$/)) {
                phoneInput.classList.add('is-invalid'); ok=false;
            }
            if (emailInput && emailInput.value.trim() && !emailInput.value.trim().match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
                emailInput.classList.add('is-invalid'); ok=false;
            }
            if (bidAmtInput) {
                var minV=parseFloat(bidAmtInput.min)||0, bidV=parseFloat(bidAmtInput.value)||0;
                if (bidV<minV) { bidAmtInput.classList.add('is-invalid'); ok=false; }
            }
            if (!ok) { e.preventDefault(); return false; }
            var btn=form.querySelector('.bid-submit-btn');
            if(btn){ btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin me-1"></i><?php echo isEnglish()?"Submitting...":"पेश गर्दै..."; ?>'; }
        });
    });

    document.querySelectorAll('.modal').forEach(function(modal) {
        modal.addEventListener('hidden.bs.modal', function() {
            var form=modal.querySelector('form');
            if(form){
                form.reset();
                form.querySelectorAll('.is-valid,.is-invalid').forEach(function(el){ el.classList.remove('is-valid','is-invalid'); });
                var btn=form.querySelector('.bid-submit-btn');
                if(btn){ btn.disabled=false; btn.innerHTML='<i class="fas fa-gavel me-1"></i><?php echo isEnglish()?"Submit Bid":"बोलपत्र पेश गर्नुहोस्"; ?>'; }
            }
        });
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
