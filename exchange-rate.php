<?php
/**
 * विदेशी विनिमय दर — Foreign Exchange Rate
 * NRB (नेपाल राष्ट्र बैंक) को Official Public API बाट Real-time data
 * Data 6 घण्टामा auto-refresh हुन्छ — cache/nrb_forex_DATE.json मा save हुन्छ।
 */
require_once __DIR__ . '/_bootstrap.php'; // bootstrap → config auto-loaded
require_once 'includes/nrb-forex-fetch.php';

$pageTitle = isEnglish() ? 'Foreign Exchange Rate' : 'विदेशी विनिमय दर';
require_once 'includes/header.php';
$L = getLangStrings();

/* ── NRB API बाट data fetch ── */
$forexData   = nrbFetchForex();
$rates       = $forexData['rates'] ?? [];
$publishedOn = $forexData['published_on'] ?? date('Y-m-d');
$source      = $forexData['source']       ?? 'unknown';
$fetchedAt   = $forexData['fetched_at']   ?? '';
$isLive      = ($source === 'nrb_live');
$isCached    = ($source === 'nrb_cached');
?>

<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><?php echo $L['exchange_rate']; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item active"><?php echo $L['exchange_rate']; ?></li>
            </ol>
        </nav>
    </div>
</section>

<section class="section-padding">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="tool-card">
                    <div class="tool-header">
                        <div class="tool-icon">
                            <i class="fas fa-exchange-alt"></i>
                        </div>
                        <h3><?php echo isEnglish() ? 'Foreign Exchange Rate' : 'विदेशी विनिमय दर'; ?></h3>
                        <p><?php echo isEnglish() ? 'Nepal Rastra Bank — Official Daily Exchange Rates' : 'नेपाल राष्ट्र बैंक — आधिकारिक दैनिक विनिमय दर'; ?></p>
                    </div>

                    <!-- Source / status badge -->
                    <div class="text-center mb-3">
                        <?php if ($isLive): ?>
                        <span class="badge bg-success px-3 py-2">
                            <i class="fas fa-circle-dot me-1" class="icon-pulse"></i>
                            <?php echo isEnglish() ? 'Live — NRB Official Data' : 'Live — NRB आधिकारिक तथ्याङ्क'; ?>
                        </span>
                        <?php elseif ($isCached): ?>
                        <span class="badge bg-info text-dark px-3 py-2">
                            <i class="fas fa-database me-1"></i>
                            <?php echo isEnglish() ? 'Cached — NRB Data' : 'Cached — NRB तथ्याङ्क'; ?>
                        </span>
                        <?php else: ?>
                        <span class="badge bg-warning text-dark px-3 py-2">
                            <i class="fas fa-exclamation-circle me-1"></i>
                            <?php echo isEnglish() ? 'Using fallback rates (NRB unreachable)' : 'Fallback दरहरू (NRB उपलब्ध छैन)'; ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <!-- Rates table -->
                    <div class="exchange-rate-widget">
                        <div class="table-responsive">
                            <table class="table table-hover exchange-table align-middle mb-0 exr-table">
                                <thead class="exr-thead">
                                    <tr>
                                        <th class="ps-3"><?php echo isEnglish() ? 'Currency' : 'मुद्रा'; ?></th>
                                        <th class="text-center" width="70"><?php echo isEnglish() ? 'Unit' : 'एकाइ'; ?></th>
                                        <th class="text-end" width="130"><?php echo isEnglish() ? 'Buying (NPR)' : 'खरिद (रु.)'; ?></th>
                                        <th class="text-end pe-3" width="130"><?php echo isEnglish() ? 'Selling (NPR)' : 'बिक्री (रु.)'; ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($rates)): ?>
                                    <tr><td colspan="4" class="text-center py-4 text-muted">
                                        <i class="fas fa-wifi-slash fa-2x mb-2 d-block"></i>
                                        <?php echo isEnglish() ? 'Unable to load exchange rates.' : 'विनिमय दर लोड गर्न सकिएन।'; ?>
                                    </td></tr>
                                    <?php endif; ?>
                                    <?php foreach ($rates as $r): ?>
                                    <tr>
                                        <td class="ps-3">
                                            <div class="d-flex align-items-center gap-2">
                                                <img src="https://flagcdn.com/w24/<?php echo htmlspecialchars($r['flag']); ?>.png"
                                                     alt="<?php echo htmlspecialchars($r['iso']); ?>"
                                                     class="exr-flag"
                                                     onerror="this.style.display='none'">
                                                <div>
                                                    <span class="fw-semibold text-success"><?php echo htmlspecialchars($r['iso']); ?></span>
                                                    <span class="text-muted ms-1 exr-currency-name"><?php echo htmlspecialchars($r['name']); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-light text-dark border"><?php echo $r['unit']; ?></span>
                                        </td>
                                        <td class="text-end">
                                            <span class="fw-semibold exr-buy">रु. <?php echo $r['buy']; ?></span>
                                        </td>
                                        <td class="text-end pe-3">
                                            <span class="fw-semibold text-danger">रु. <?php echo $r['sell']; ?></span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Update info -->
                        <div class="rate-note mt-4 p-3 exr-rate-note">
                            <div class="d-flex align-items-start gap-2">
                                <i class="fas fa-info-circle text-success mt-1"></i>
                                <div>
                                    <p class="mb-1 fw-semibold text-success">
                                        <?php echo isEnglish() ? 'Published Date: ' : 'प्रकाशन मिति: '; ?>
                                        <span><?php echo htmlspecialchars($publishedOn); ?></span>
                                        <?php if ($fetchedAt): ?>
                                        &nbsp;|&nbsp;
                                        <?php echo isEnglish() ? 'Fetched: ' : 'Fetched: '; ?>
                                        <span class="text-muted"><?php echo htmlspecialchars($fetchedAt); ?></span>
                                        <?php endif; ?>
                                    </p>
                                    <p class="mb-1 text-muted small">
                                        <i class="fas fa-university me-1"></i>
                                        <?php echo isEnglish()
                                            ? 'Source: Nepal Rastra Bank (nrb.org.np) Official Forex API — auto-refreshed every 6 hours.'
                                            : 'स्रोत: नेपाल राष्ट्र बैंक (nrb.org.np) को आधिकारिक Forex API — हरेक ६ घण्टामा स्वत: अद्यावधिक।'; ?>
                                    </p>
                                    <p class="mb-0 text-muted small">
                                        <?php echo isEnglish()
                                            ? 'Note: These are official NRB rates. Transaction rates may vary slightly.'
                                            : 'नोट: यी नेपाल राष्ट्र बैंकका आधिकारिक दरहरू हुन्। कारोबार दर थोरै भिन्न हुन सक्छ।'; ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>


<?php require_once 'includes/footer.php'; ?>
