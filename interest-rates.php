<?php
$pageTitle = 'ब्याज दर';
require_once 'includes/header.php';

// Get interest rates - with table existence check
$savingRates = [];
$loanRates = [];
try {
    $db = getDB();

    // Check if table exists
    $tableCheck = $db->query("SHOW TABLES LIKE 'interest_rates'");
    if ($tableCheck && $tableCheck->fetch() !== false) {
        $savingRates = $db->query("SELECT * FROM interest_rates WHERE category = 'saving' AND is_active = 1 ORDER BY display_order, id LIMIT 10")->fetchAll() ?: [];
        $loanRates = $db->query("SELECT * FROM interest_rates WHERE category = 'loan' AND is_active = 1 ORDER BY display_order, id LIMIT 10")->fetchAll() ?: [];
    }
} catch (Exception $e) {
    $savingRates = $loanRates = [];
}
?>

<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1>ब्याज दरहरू</h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item active">ब्याज दर</li>
            </ol>
        </nav>
    </div>
</section>

<!-- Interest Rates Section -->
<section class="rates-section section-padding">
    <div class="container">
        <div class="section-header text-center mb-5" data-aos="fade-up">
            <div class="section-badge-wrap">
                <span class="section-badge"><i class="fas fa-percentage"></i> <?php echo isEnglish() ? 'Interest Rates' : 'ब्याज दर'; ?></span>
            </div>
            <h2><?php echo isEnglish() ? 'Our Current Interest Rates' : 'हाम्रो हालको ब्याज दरहरू'; ?></h2>
            <div class="section-divider"></div>
            <p><?php echo isEnglish() ? 'Competitive rates for savings and loans' : 'बचत र ऋणको लागि प्रतिस्पर्धी दरहरू'; ?></p>
        </div>

        <div class="row">
            <!-- Saving Rates -->
            <div class="col-lg-6 mb-4">
                <div class="rates-card-modern saving-rates">
                    <div class="rates-header-modern">
                        <div class="rates-icon saving">
                            <i class="fas fa-piggy-bank"></i>
                        </div>
                        <div>
                            <h3>बचत ब्याज दर</h3>
                            <p>Saving Interest Rates</p>
                        </div>
                    </div>
                    <div class="rates-body-modern">
                        <?php if (!empty($savingRates)): ?>
                        <?php foreach ($savingRates as $rate): ?>
                        <div class="rate-item">
                            <div class="rate-info">
                                <strong><?php echo $rate['name_np'] ?: $rate['name']; ?></strong>
                                <?php if ($rate['description']): ?>
                                    <small><?php echo $rate['description']; ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="rate-value saving">
                                <?php echo number_format($rate['rate'], 2); ?><span>%</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <div class="rate-empty">
                            <i class="fas fa-info-circle"></i>
                            <p>डाटा उपलब्ध छैन</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Loan Rates -->
            <div class="col-lg-6 mb-4">
                <div class="rates-card-modern loan-rates">
                    <div class="rates-header-modern">
                        <div class="rates-icon loan">
                            <i class="fas fa-hand-holding-usd"></i>
                        </div>
                        <div>
                            <h3>ऋण ब्याज दर</h3>
                            <p>Loan Interest Rates</p>
                        </div>
                    </div>
                    <div class="rates-body-modern">
                        <?php if (!empty($loanRates)): ?>
                        <?php foreach ($loanRates as $rate): ?>
                        <div class="rate-item">
                            <div class="rate-info">
                                <strong><?php echo $rate['name_np'] ?: $rate['name']; ?></strong>
                                <?php if ($rate['description']): ?>
                                    <small><?php echo $rate['description']; ?></small>
                                <?php endif; ?>
                            </div>
                            <div class="rate-value loan">
                                <?php echo number_format($rate['rate'], 2); ?><span>%</span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <div class="rate-empty">
                            <i class="fas fa-info-circle"></i>
                            <p>डाटा उपलब्ध छैन</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Note -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="rates-note">
                    <h5><i class="fas fa-info-circle"></i> महत्त्वपूर्ण जानकारी</h5>
                    <ul>
                        <li>माथिका ब्याज दरहरू वार्षिक आधारमा छन्।</li>
                        <li>ब्याज दरहरू समय समयमा परिवर्तन हुन सक्छन्।</li>
                        <li>थप जानकारीको लागि कृपया हाम्रो कार्यालयमा सम्पर्क गर्नुहोस्।</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CTA Section -->
<section class="cta-section">
    <div class="container">
        <div class="cta-content">
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <h2>खाता खोल्न चाहनुहुन्छ?</h2>
                    <p>हाम्रो कार्यालयमा आउनुहोस् वा सम्पर्क गर्नुहोस्।</p>
                </div>
                <div class="col-lg-4 text-lg-end">
                    <a href="contact.php" class="btn ir-cta-btn btn-lg">सम्पर्क गर्नुहोस्</a>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once 'includes/footer.php'; ?>
