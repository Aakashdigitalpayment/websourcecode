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

$scRoleMain = isEnglish() ? 'Head Office' : 'प्रधान कार्यालय';
$scRoleCenter = isEnglish() ? 'Service Center' : 'सेवा केन्द्र';
$scMapLabel = isEnglish() ? 'View on Map' : 'नक्सामा हेर्नुहोस्';
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
            <div class="row justify-content-center">
                <?php foreach ($centers as $index => $center):
                    $isMain = !empty($center['is_main_branch']);
                    $displayName = (string)getLangField($center, 'name');
                    $nameEn = trim((string)($center['name'] ?? ''));
                    $nameNp = trim((string)($center['name_np'] ?? ''));
                    $subtitle = '';
                    if (!isEnglish() && $nameEn !== '' && $nameEn !== $displayName) {
                        $subtitle = $nameEn;
                    } elseif (isEnglish() && $nameNp !== '' && $nameNp !== $displayName) {
                        $subtitle = $nameNp;
                    }
                    $phone = trim((string)($center['phone'] ?? ''));
                    $email = trim((string)($center['email'] ?? ''));
                    $address = trim((string)($center['address'] ?? ''));
                    $hours = trim((string)($center['opening_hours'] ?? '10:00 AM - 5:00 PM'));
                    $mapUrl = trim((string)($center['map_url'] ?? ''));
                    $phoneHref = preg_replace('/[^\d+]/', '', $phone) ?: $phone;
                ?>
                <div class="col-lg-4 col-md-6 mb-4" data-aos="fade-up" data-aos-delay="<?php echo ($index % 6) * 50; ?>">
                    <div class="service-center-card<?php echo $isMain ? ' main-branch' : ''; ?>">
                        <div class="center-icon-ring">
                            <div class="center-icon">
                                <i class="fas <?php echo $isMain ? 'fa-landmark' : 'fa-building'; ?>" aria-hidden="true"></i>
                            </div>
                        </div>
                        <div class="center-body">
                            <h4><?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></h4>
                            <?php if ($subtitle !== ''): ?>
                            <p class="center-name-sub"><?php echo htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8'); ?></p>
                            <?php endif; ?>
                            <span class="center-role-badge"><?php echo $isMain ? $scRoleMain : $scRoleCenter; ?></span>
                            <ul class="center-info">
                                <?php if ($address !== ''): ?>
                                <li><i class="fas fa-map-marker-alt" aria-hidden="true"></i> <?php echo htmlspecialchars($address, ENT_QUOTES, 'UTF-8'); ?></li>
                                <?php endif; ?>
                                <?php if ($phone !== ''): ?>
                                <li><i class="fas fa-phone" aria-hidden="true"></i> <?php echo htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'); ?></li>
                                <?php endif; ?>
                                <?php if ($email !== ''): ?>
                                <li><i class="fas fa-envelope" aria-hidden="true"></i> <?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?></li>
                                <?php endif; ?>
                                <?php if ($hours !== ''): ?>
                                <li><i class="fas fa-clock" aria-hidden="true"></i> <?php echo htmlspecialchars($hours, ENT_QUOTES, 'UTF-8'); ?></li>
                                <?php endif; ?>
                            </ul>
                            <?php if ($phone !== '' || $email !== '' || $mapUrl !== ''): ?>
                            <div class="center-contact">
                                <?php if ($phone !== ''): ?>
                                <a href="tel:<?php echo htmlspecialchars($phoneHref, ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($phone, ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-phone" aria-hidden="true"></i></a>
                                <?php endif; ?>
                                <?php if ($email !== ''): ?>
                                <a href="mailto:<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-envelope" aria-hidden="true"></i></a>
                                <?php endif; ?>
                                <?php if ($mapUrl !== ''): ?>
                                <a href="<?php echo htmlspecialchars($mapUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo htmlspecialchars($scMapLabel, ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($scMapLabel, ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-map-marker-alt" aria-hidden="true"></i></a>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <!-- Sample service centers when database is empty -->
            <?php
            $sampleCenters = [
                [
                    'main' => true,
                    'icon' => 'fa-landmark',
                    'name' => isEnglish() ? 'Main Service Center' : 'मुख्य सेवा कार्यालय',
                    'address' => isEnglish() ? 'Kathmandu, Nepal' : 'काठमाडौं, नेपाल',
                    'phone' => (string)getSetting('phone', ''),
                    'email' => (string)getSetting('email', ''),
                    'hours' => '10:00 AM - 5:00 PM',
                ],
                [
                    'main' => false,
                    'icon' => 'fa-building',
                    'name' => isEnglish() ? 'Lalitpur Service Center' : 'ललितपुर सेवा कार्यालय',
                    'address' => isEnglish() ? 'Pulchowk, Lalitpur' : 'पुल्चोक, ललितपुर',
                    'phone' => '9827157000',
                    'email' => '',
                    'hours' => '10:00 AM - 5:00 PM',
                ],
                [
                    'main' => false,
                    'icon' => 'fa-building',
                    'name' => isEnglish() ? 'Bhaktapur Service Center' : 'भक्तपुर सेवा कार्यालय',
                    'address' => isEnglish() ? 'Dudhpati, Bhaktapur' : 'दुधपाटी, भक्तपुर',
                    'phone' => '9827157000',
                    'email' => '',
                    'hours' => '10:00 AM - 5:00 PM',
                ],
                [
                    'main' => false,
                    'icon' => 'fa-building',
                    'name' => isEnglish() ? 'Pokhara Service Center' : 'पोखरा सेवा कार्यालय',
                    'address' => isEnglish() ? 'Lakeside, Pokhara' : 'लेकसाइड, पोखरा',
                    'phone' => '061-523456',
                    'email' => '',
                    'hours' => '10:00 AM - 5:00 PM',
                ],
                [
                    'main' => false,
                    'icon' => 'fa-building',
                    'name' => isEnglish() ? 'Chitwan Service Center' : 'चितवन सेवा कार्यालय',
                    'address' => isEnglish() ? 'Narayangarh, Chitwan' : 'नारायणगढ, चितवन',
                    'phone' => '056-520123',
                    'email' => '',
                    'hours' => '10:00 AM - 5:00 PM',
                ],
                [
                    'main' => false,
                    'icon' => 'fa-building',
                    'name' => isEnglish() ? 'Butwal Service Center' : 'बुटवल सेवा कार्यालय',
                    'address' => isEnglish() ? 'Traffic Chowk, Butwal' : 'ट्राफिक चोक, बुटवल',
                    'phone' => '071-540123',
                    'email' => '',
                    'hours' => '10:00 AM - 5:00 PM',
                ],
            ];
            ?>
            <div class="row justify-content-center">
                <?php foreach ($sampleCenters as $index => $sample):
                    $phoneHref = preg_replace('/[^\d+]/', '', $sample['phone']) ?: $sample['phone'];
                ?>
                <div class="col-lg-4 col-md-6 mb-4" data-aos="fade-up" data-aos-delay="<?php echo ($index % 6) * 50; ?>">
                    <div class="service-center-card<?php echo !empty($sample['main']) ? ' main-branch' : ''; ?>">
                        <div class="center-icon-ring">
                            <div class="center-icon">
                                <i class="fas <?php echo htmlspecialchars($sample['icon'], ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"></i>
                            </div>
                        </div>
                        <div class="center-body">
                            <h4><?php echo htmlspecialchars($sample['name'], ENT_QUOTES, 'UTF-8'); ?></h4>
                            <span class="center-role-badge"><?php echo !empty($sample['main']) ? $scRoleMain : $scRoleCenter; ?></span>
                            <ul class="center-info">
                                <li><i class="fas fa-map-marker-alt" aria-hidden="true"></i> <?php echo htmlspecialchars($sample['address'], ENT_QUOTES, 'UTF-8'); ?></li>
                                <?php if ($sample['phone'] !== ''): ?>
                                <li><i class="fas fa-phone" aria-hidden="true"></i> <?php echo htmlspecialchars($sample['phone'], ENT_QUOTES, 'UTF-8'); ?></li>
                                <?php endif; ?>
                                <?php if ($sample['email'] !== ''): ?>
                                <li><i class="fas fa-envelope" aria-hidden="true"></i> <?php echo htmlspecialchars($sample['email'], ENT_QUOTES, 'UTF-8'); ?></li>
                                <?php endif; ?>
                                <li><i class="fas fa-clock" aria-hidden="true"></i> <?php echo htmlspecialchars($sample['hours'], ENT_QUOTES, 'UTF-8'); ?></li>
                            </ul>
                            <div class="center-contact">
                                <?php if ($sample['phone'] !== ''): ?>
                                <a href="tel:<?php echo htmlspecialchars((string)$phoneHref, ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($sample['phone'], ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($sample['phone'], ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-phone" aria-hidden="true"></i></a>
                                <?php endif; ?>
                                <?php if ($sample['email'] !== ''): ?>
                                <a href="mailto:<?php echo htmlspecialchars($sample['email'], ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars($sample['email'], ENT_QUOTES, 'UTF-8'); ?>" aria-label="<?php echo htmlspecialchars($sample['email'], ENT_QUOTES, 'UTF-8'); ?>"><i class="fas fa-envelope" aria-hidden="true"></i></a>
                                <?php endif; ?>
                                <span class="center-contact-disabled" title="<?php echo htmlspecialchars($scMapLabel, ENT_QUOTES, 'UTF-8'); ?>" aria-hidden="true"><i class="fas fa-map-marker-alt"></i></span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
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
