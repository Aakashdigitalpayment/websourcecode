<?php
/**
 * Public: दृष्टि र लक्ष्य — dedicated page (About dropdown).
 */
require_once __DIR__ . '/includes/config.php';

$visionTitleNp = getSetting('vision_content_title_np', 'हाम्रो दृष्टिकोण');
$visionTitleEn = getSetting('vision_content_title_en', 'Our Vision');
$missionTitleNp = getSetting('mission_content_title_np', 'हाम्रो लक्ष्य');
$missionTitleEn = getSetting('mission_content_title_en', 'Our Mission');

$pageTitle = isEnglish() ? 'Vision & Mission' : 'दृष्टि र लक्ष्य';
$pageDescription = isEnglish()
    ? 'Our cooperative vision and mission.'
    : 'हाम्रो सहकारीको दृष्टि र लक्ष्य।';
require_once __DIR__ . '/includes/header.php';
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

<section class="vision-section-v2 section-padding bg-light" id="vision">
    <div class="container">
        <div class="section-header text-center mb-5" data-aos="fade-up">
            <div class="section-badge-wrap">
                <span class="section-badge">
                    <i class="lucide-icon" aria-hidden="true" data-lucide="eye"></i>
                    <?php echo isEnglish() ? 'Our Purpose' : 'हाम्रो उद्देश्य'; ?>
                </span>
            </div>
            <h2><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></h2>
            <div class="section-divider"></div>
        </div>
        <div class="row g-4">
            <div class="col-md-6" data-aos="fade-up" data-aos-delay="100">
                <div class="vision-card-v2 vision">
                    <div class="vision-card-glow"></div>
                    <div class="vision-icon-v2">
                        <i class="lucide-icon" aria-hidden="true" data-lucide="eye"></i>
                    </div>
                    <div class="vision-card-content coop-prose">
                        <h4><?php echo htmlspecialchars(isEnglish() ? $visionTitleEn : $visionTitleNp, ENT_QUOTES, 'UTF-8'); ?></h4>
                        <?php
                        $visionContent = isEnglish() ? getSetting('vision_content_en', '') : getSetting('vision_content_np', '');
                        if ($visionContent):
                            echo coop_render_cms_prose($visionContent);
                        else:
                        ?>
                        <p><?php echo isEnglish() ? 'To be the most trusted and preferred cooperative in our community.' : 'समुदायमा सबैभन्दा विश्वसनीय र रुचाइएको सहकारी संस्था बन्नु।'; ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="vision-card-decoration"></div>
                </div>
            </div>
            <div class="col-md-6" data-aos="fade-up" data-aos-delay="200" id="mission">
                <div class="vision-card-v2 mission">
                    <div class="vision-card-glow"></div>
                    <div class="vision-icon-v2">
                        <i class="lucide-icon" data-lucide="target" aria-hidden="true"></i>
                    </div>
                    <div class="vision-card-content coop-prose">
                        <h4><?php echo htmlspecialchars(isEnglish() ? $missionTitleEn : $missionTitleNp, ENT_QUOTES, 'UTF-8'); ?></h4>
                        <?php
                        $missionContent = isEnglish() ? getSetting('mission_content_en', '') : getSetting('mission_content_np', '');
                        if ($missionContent):
                            echo coop_render_cms_prose($missionContent);
                        else:
                        ?>
                        <p><?php echo isEnglish() ? 'To provide quality financial services while promoting the spirit of cooperation and helping members achieve their financial goals.' : 'सहकारिताको भावनालाई प्रवर्द्धन गर्दै सदस्यहरूलाई उनीहरूको वित्तीय लक्ष्य हासिल गर्न मद्दत गर्ने गुणस्तरीय वित्तीय सेवा प्रदान गर्नु।'; ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="vision-card-decoration"></div>
                </div>
            </div>
        </div>
        <div class="text-center mt-4">
            <a href="<?php echo htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8'); ?>about.php" class="btn btn-outline-primary">
                <i class="lucide-icon me-1" data-lucide="arrow-left" aria-hidden="true"></i><?php echo isEnglish() ? 'Back to About' : 'हाम्रो बारेमा फर्कनुहोस्'; ?>
            </a>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
