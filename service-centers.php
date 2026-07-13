<?php
require_once __DIR__ . '/_bootstrap.php'; // bootstrap → config auto-loaded
$pageTitle = isEnglish() ? 'Service Center Network' : 'सेवा केन्द्र नेटवर्क';
require_once 'includes/header.php';
$L = getLangStrings();

// Get service centers from database (main branch first, then display_order)
try {
    require_once __DIR__ . '/includes/service-centers-helpers.php';
    $centers = fetchActiveServiceCenters();
} catch (Throwable $e) {
    $centers = [];
}
?>

<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><?php echo isEnglish() ? 'Service Center Network' : 'सेवा केन्द्र नेटवर्क'; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item active"><?php echo isEnglish() ? 'Service Centers' : 'सेवा केन्द्रहरू'; ?></li>
            </ol>
        </nav>
    </div>
</section>

<!-- Service Centers Section -->
<section class="section-padding">
    <div class="container">
        <div class="section-header text-center mb-5" data-aos="fade-up">
            <div class="section-badge-wrap">
                <span class="section-badge"><i class="fas fa-building"></i> <?php echo isEnglish() ? 'Service Centers' : 'सेवा कार्यालयहरू'; ?></span>
            </div>
            <h2><?php echo isEnglish() ? 'Our Service Centers' : 'हाम्रा सेवा कार्यालयहरू'; ?></h2>
            <div class="section-divider"></div>
            <p><?php echo isEnglish() ? 'Find our service centers across Nepal' : 'नेपालभरि हाम्रा सेवा कार्यालयहरू खोज्नुहोस्'; ?></p>
        </div>

        <?php if (!empty($centers)): ?>
            <!-- Dynamic centers from database -->
            <div class="row">
                <?php foreach ($centers as $center): ?>
                <div class="col-lg-4 col-md-6 mb-3">
                    <div class="service-center-card<?php echo !empty($center['is_main_branch']) ? ' main-branch' : ''; ?>">
                        <?php if (!empty($center['is_main_branch'])): ?>
                        <div class="branch-badge"><?php echo isEnglish() ? 'Head Office' : 'प्रधान कार्यालय'; ?></div>
                        <?php endif; ?>
                        <div class="center-icon">
                            <i class="fas fa-building" aria-hidden="true"></i>
                        </div>
                        <h4><?php echo htmlspecialchars((string)getLangField($center, 'name'), ENT_QUOTES, 'UTF-8'); ?></h4>
                        <ul class="center-info">
                            <li><i class="fas fa-map-marker-alt" aria-hidden="true"></i> <?php echo htmlspecialchars((string)$center['address'], ENT_QUOTES, 'UTF-8'); ?></li>
                            <li><i class="fas fa-phone" aria-hidden="true"></i> <?php echo htmlspecialchars((string)$center['phone'], ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php if (!empty($center['email'])): ?>
                            <li><i class="fas fa-envelope" aria-hidden="true"></i> <?php echo htmlspecialchars((string)$center['email'], ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endif; ?>
                            <li><i class="fas fa-clock" aria-hidden="true"></i> <?php echo htmlspecialchars((string)($center['opening_hours'] ?? '10:00 AM - 5:00 PM'), ENT_QUOTES, 'UTF-8'); ?></li>
                        </ul>
                        <?php if (!empty($center['map_url'])): ?>
                        <a href="<?php echo htmlspecialchars((string)$center['map_url'], ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-primary btn-sm" target="_blank" rel="noopener noreferrer">
                            <i class="fas fa-map" aria-hidden="true"></i> <?php echo isEnglish() ? 'View on Map' : 'नक्सामा हेर्नुहोस्'; ?>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <!-- Sample service centers when database is empty -->
            <div class="row">
                <!-- Main Branch -->
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="service-center-card main-branch">
                        <div class="branch-badge"><?php echo isEnglish() ? 'Head Office' : 'प्रधान कार्यालय'; ?></div>
                        <div class="center-icon">
                            <i class="fas fa-landmark"></i>
                        </div>
                        <h4><?php echo isEnglish() ? 'Main Service Center' : 'मुख्य सेवा कार्यालय'; ?></h4>
                        <ul class="center-info">
                            <li><i class="fas fa-map-marker-alt"></i> <?php echo isEnglish() ? 'Kathmandu, Nepal' : 'काठमाडौं, नेपाल'; ?></li>
                            <li><i class="fas fa-phone"></i> <?php echo getSetting('phone', ''); ?></li>
                            <li><i class="fas fa-envelope"></i> <?php echo getSetting('email', ''); ?></li>
                            <li><i class="fas fa-clock"></i> 10:00 AM - 5:00 PM</li>
                        </ul>
                        <span class="btn btn-primary btn-sm disabled" style="opacity:.4;cursor:default;pointer-events:none;">
                            <i class="fas fa-map"></i> <?php echo isEnglish() ? 'View on Map' : 'नक्सामा हेर्नुहोस्'; ?>
                        </span>
                    </div>
                </div>

                <!-- Branch 2 -->
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="service-center-card">
                        <div class="center-icon">
                            <i class="fas fa-building"></i>
                        </div>
                        <h4><?php echo isEnglish() ? 'Lalitpur Service Center' : 'ललितपुर सेवा कार्यालय'; ?></h4>
                        <ul class="center-info">
                            <li><i class="fas fa-map-marker-alt"></i> <?php echo isEnglish() ? 'Pulchowk, Lalitpur' : 'पुल्चोक, ललितपुर'; ?></li>
                            <li><i class="fas fa-phone"></i> 9827157000</li>
                            <li><i class="fas fa-clock"></i> 10:00 AM - 5:00 PM</li>
                        </ul>
                        <span class="btn btn-outline-primary btn-sm disabled" style="opacity:.4;cursor:default;pointer-events:none;">
                            <i class="fas fa-map"></i> <?php echo isEnglish() ? 'View on Map' : 'नक्सामा हेर्नुहोस्'; ?>
                        </span>
                    </div>
                </div>

                <!-- Branch 3 -->
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="service-center-card">
                        <div class="center-icon">
                            <i class="fas fa-building"></i>
                        </div>
                        <h4><?php echo isEnglish() ? 'Bhaktapur Service Center' : 'भक्तपुर सेवा कार्यालय'; ?></h4>
                        <ul class="center-info">
                            <li><i class="fas fa-map-marker-alt"></i> <?php echo isEnglish() ? 'Dudhpati, Bhaktapur' : 'दुधपाटी, भक्तपुर'; ?></li>
                            <li><i class="fas fa-phone"></i> 9827157000</li>
                            <li><i class="fas fa-clock"></i> 10:00 AM - 5:00 PM</li>
                        </ul>
                        <span class="btn btn-outline-primary btn-sm disabled" style="opacity:.4;cursor:default;pointer-events:none;">
                            <i class="fas fa-map"></i> <?php echo isEnglish() ? 'View on Map' : 'नक्सामा हेर्नुहोस्'; ?>
                        </span>
                    </div>
                </div>

                <!-- Branch 4 -->
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="service-center-card">
                        <div class="center-icon">
                            <i class="fas fa-building"></i>
                        </div>
                        <h4><?php echo isEnglish() ? 'Pokhara Service Center' : 'पोखरा सेवा कार्यालय'; ?></h4>
                        <ul class="center-info">
                            <li><i class="fas fa-map-marker-alt"></i> <?php echo isEnglish() ? 'Lakeside, Pokhara' : 'लेकसाइड, पोखरा'; ?></li>
                            <li><i class="fas fa-phone"></i> 061-523456</li>
                            <li><i class="fas fa-clock"></i> 10:00 AM - 5:00 PM</li>
                        </ul>
                        <span class="btn btn-outline-primary btn-sm disabled" style="opacity:.4;cursor:default;pointer-events:none;">
                            <i class="fas fa-map"></i> <?php echo isEnglish() ? 'View on Map' : 'नक्सामा हेर्नुहोस्'; ?>
                        </span>
                    </div>
                </div>

                <!-- Branch 5 -->
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="service-center-card">
                        <div class="center-icon">
                            <i class="fas fa-building"></i>
                        </div>
                        <h4><?php echo isEnglish() ? 'Chitwan Service Center' : 'चितवन सेवा कार्यालय'; ?></h4>
                        <ul class="center-info">
                            <li><i class="fas fa-map-marker-alt"></i> <?php echo isEnglish() ? 'Narayangarh, Chitwan' : 'नारायणगढ, चितवन'; ?></li>
                            <li><i class="fas fa-phone"></i> 056-520123</li>
                            <li><i class="fas fa-clock"></i> 10:00 AM - 5:00 PM</li>
                        </ul>
                        <span class="btn btn-outline-primary btn-sm disabled" style="opacity:.4;cursor:default;pointer-events:none;">
                            <i class="fas fa-map"></i> <?php echo isEnglish() ? 'View on Map' : 'नक्सामा हेर्नुहोस्'; ?>
                        </span>
                    </div>
                </div>

                <!-- Branch 6 -->
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="service-center-card">
                        <div class="center-icon">
                            <i class="fas fa-building"></i>
                        </div>
                        <h4><?php echo isEnglish() ? 'Butwal Service Center' : 'बुटवल सेवा कार्यालय'; ?></h4>
                        <ul class="center-info">
                            <li><i class="fas fa-map-marker-alt"></i> <?php echo isEnglish() ? 'Traffic Chowk, Butwal' : 'ट्राफिक चोक, बुटवल'; ?></li>
                            <li><i class="fas fa-phone"></i> 071-540123</li>
                            <li><i class="fas fa-clock"></i> 10:00 AM - 5:00 PM</li>
                        </ul>
                        <span class="btn btn-outline-primary btn-sm disabled" style="opacity:.4;cursor:default;pointer-events:none;">
                            <i class="fas fa-map"></i> <?php echo isEnglish() ? 'View on Map' : 'नक्सामा हेर्नुहोस्'; ?>
                        </span>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Contact CTA -->
        <div class="text-center mt-5">
            <p class="mb-3">
                <?php echo isEnglish() ? 'Can\'t find a branch near you? Contact us for assistance.' : 'तपाईंको नजिक शाखा फेला पार्न सक्नुहुन्न? सहयोगको लागि हामीलाई सम्पर्क गर्नुहोस्।'; ?>
            </p>
            <a href="contact.php" class="btn btn-primary">
                <i class="fas fa-phone"></i> <?php echo $L['contact_us']; ?>
            </a>
        </div>
    </div>
</section>


<?php require_once 'includes/footer.php'; ?>
