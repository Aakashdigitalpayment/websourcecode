<?php
require_once __DIR__ . '/_bootstrap.php'; // bootstrap → config auto-loaded
$pageTitle = isEnglish() ? 'Service Center Network' : 'सेवा केन्द्र नेटवर्क';
require_once 'includes/header.php';
$L = getLangStrings();

// Get service centers from database
try {
    $db = getDB();
    $centers = $db->query("SELECT * FROM service_centers WHERE is_active = 1 ORDER BY province, name")->fetchAll();
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
                <span class="section-badge"><i class="fas fa-building"></i> <?php echo isEnglish() ? 'Branches' : 'शाखाहरू'; ?></span>
            </div>
            <h2><?php echo isEnglish() ? 'Our Branch & Service Centers' : 'हाम्रा शाखा तथा सेवा केन्द्रहरू'; ?></h2>
            <div class="section-divider"></div>
            <p><?php echo isEnglish() ? 'Find our service centers across Nepal' : 'नेपालभरि हाम्रा सेवा केन्द्रहरू खोज्नुहोस्'; ?></p>
        </div>

        <?php if (!empty($centers)): ?>
            <!-- Dynamic centers from database -->
            <div class="row">
                <?php foreach ($centers as $center): ?>
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="service-center-card">
                        <div class="center-icon">
                            <i class="fas fa-building"></i>
                        </div>
                        <h4><?php echo getLangField($center, 'name'); ?></h4>
                        <ul class="center-info">
                            <li><i class="fas fa-map-marker-alt"></i> <?php echo $center['address']; ?></li>
                            <li><i class="fas fa-phone"></i> <?php echo $center['phone']; ?></li>
                            <?php if ($center['email']): ?>
                            <li><i class="fas fa-envelope"></i> <?php echo $center['email']; ?></li>
                            <?php endif; ?>
                            <li><i class="fas fa-clock"></i> <?php echo $center['opening_hours'] ?? '10:00 AM - 5:00 PM'; ?></li>
                        </ul>
                        <?php if ($center['map_url']): ?>
                        <a href="<?php echo $center['map_url']; ?>" class="btn btn-outline-primary btn-sm" target="_blank">
                            <i class="fas fa-map"></i> <?php echo isEnglish() ? 'View on Map' : 'नक्सामा हेर्नुहोस्'; ?>
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
                        <h4><?php echo isEnglish() ? 'Main Branch' : 'मुख्य शाखा'; ?></h4>
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
                        <h4><?php echo isEnglish() ? 'Lalitpur Branch' : 'ललितपुर शाखा'; ?></h4>
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
                        <h4><?php echo isEnglish() ? 'Bhaktapur Branch' : 'भक्तपुर शाखा'; ?></h4>
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
                        <h4><?php echo isEnglish() ? 'Pokhara Branch' : 'पोखरा शाखा'; ?></h4>
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
                        <h4><?php echo isEnglish() ? 'Chitwan Branch' : 'चितवन शाखा'; ?></h4>
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
                        <h4><?php echo isEnglish() ? 'Butwal Branch' : 'बुटवल शाखा'; ?></h4>
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
