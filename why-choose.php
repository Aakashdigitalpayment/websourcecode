<?php
/**
 * Public: किन हामीलाई छान्ने? — dedicated page (homepage section + About family).
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/why-choose-tables.php';

$pageTitle = isEnglish() ? 'Why Choose Us?' : 'किन हामीलाई छान्ने?';
$pageDescription = isEnglish()
    ? 'Reasons to choose our cooperative.'
    : 'हाम्रो संस्था छान्नुको कारणहरू।';
require_once __DIR__ . '/includes/header.php';

$whyFeatures = [];
try {
    $db = function_exists('getDB') ? getDB() : null;
    if ($db instanceof PDO) {
        ensureWhyChooseFeaturesTable($db);
        $whyFeatures = $db->query(
            'SELECT * FROM why_choose_features WHERE is_active=1 ORDER BY sort_order, id LIMIT 48'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $whyFeatures = [];
}
if ($whyFeatures === []) {
    $whyFeatures = [
        ['icon' => 'fas fa-shield-alt', 'title_np' => 'सुरक्षित बचत', 'title_en' => 'Safe Savings', 'desc_np' => 'तपाईंको बचत हामीसँग पूर्ण रूपमा सुरक्षित छ।', 'desc_en' => 'Your savings are fully secure with us.'],
        ['icon' => 'fas fa-percentage', 'title_np' => 'आकर्षक ब्याज', 'title_en' => 'Attractive Interest', 'desc_np' => 'बजारमा प्रतिस्पर्धी ब्याज दरहरू।', 'desc_en' => 'Competitive interest rates in the market.'],
        ['icon' => 'fas fa-clock', 'title_np' => 'छिटो सेवा', 'title_en' => 'Quick Service', 'desc_np' => 'द्रुत र प्रभावकारी ग्राहक सेवा।', 'desc_en' => 'Fast and effective customer service.'],
        ['icon' => 'fas fa-users', 'title_np' => 'समुदायमा आधारित', 'title_en' => 'Community Based', 'desc_np' => 'समुदायको विकासमा समर्पित।', 'desc_en' => 'Dedicated to community development.'],
    ];
}
$L = function_exists('getLangStrings') ? getLangStrings() : [];
?>

<section class="page-banner page-banner-modern">
    <div class="container">
        <div class="banner-content-modern">
            <h1 class="page-title-modern"><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb breadcrumb-modern">
                    <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8'); ?>" class="breadcrumb-link-modern"><?php echo $L['home'] ?? (isEnglish() ? 'Home' : 'गृहपृष्ठ'); ?></a></li>
                    <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8'); ?>about.php" class="breadcrumb-link-modern"><?php echo $L['about'] ?? (isEnglish() ? 'About' : 'हाम्रो बारेमा'); ?></a></li>
                    <li class="breadcrumb-item active"><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<section class="why-us-section section-padding" id="why-choose">
    <div class="container">
        <div class="section-header section-header-unified text-center" data-aos="fade-up">
            <div class="section-badge-wrap">
                <span class="section-badge"><i class="lucide-icon" aria-hidden="true" data-lucide="circle-check"></i> <?php echo isEnglish() ? 'Why Us' : 'किन हामी'; ?></span>
            </div>
            <h2><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></h2>
            <div class="section-divider"></div>
            <p><?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        <div class="row">
            <?php foreach ($whyFeatures as $wi => $wf): ?>
            <div class="col-lg-3 col-md-6 mb-4" data-aos="fade-up" data-aos-delay="<?php echo (int) $wi * 100; ?>">
                <div class="feature-box">
                    <div class="feature-icon">
                        <?php echo function_exists('coop_nav_icon_html') ? coop_nav_icon_html((string) ($wf['icon'] ?? ''), 'fas fa-circle') : ''; ?>
                    </div>
                    <h5><?php echo htmlspecialchars(isEnglish() ? ($wf['title_en'] ?: $wf['title_np']) : $wf['title_np'], ENT_QUOTES, 'UTF-8'); ?></h5>
                    <p><?php echo htmlspecialchars(isEnglish() ? ($wf['desc_en'] ?: $wf['desc_np']) : ($wf['desc_np'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="text-center mt-2">
            <a href="<?php echo htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8'); ?>about.php" class="btn btn-outline-primary">
                <i class="lucide-icon me-1" data-lucide="arrow-left" aria-hidden="true"></i><?php echo isEnglish() ? 'Back to About' : 'हाम्रो बारेमा फर्कनुहोस्'; ?>
            </a>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
