<?php
require_once 'includes/config.php';
require_once __DIR__ . '/includes/team-chart-helpers.php';
require_once __DIR__ . '/includes/leadership-message-helpers.php';
$pageTitle = isEnglish() ? 'About Us' : 'हाम्रो बारेमा';
/* Prefer Admin SEO meta_description; only fall back to about_short when SEO meta is empty */
$__aboutMeta = trim((string) getSetting(isEnglish() ? 'meta_description_en' : 'meta_description', ''));
if ($__aboutMeta === '') {
    $__aboutMeta = trim((string) getSetting('meta_description', ''));
}
if ($__aboutMeta === '') {
    $aboutShort = trim((string) getSetting('about_short', ''));
    if ($aboutShort !== '' && function_exists('seo_meta_description_from_html')) {
        $pageDescription = seo_meta_description_from_html($aboutShort);
    } elseif ($aboutShort !== '') {
        $pageDescription = function_exists('mb_substr')
            ? mb_substr(strip_tags($aboutShort), 0, 158)
            : substr(strip_tags($aboutShort), 0, 158);
    }
}
$extraHead = (isset($extraHead) ? (string) $extraHead : '')
    . (function_exists('coopThemeLinkHtml')
        ? coopThemeLinkHtml('assets/css/about-success-stories.css')
        : '');
require_once 'includes/header.php';
?>

<?php
// Get about page content
$db = null;
$page = null;
$boardMembers = [];
try {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM pages WHERE slug = 'about' AND is_active = 1 ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $page = $stmt->fetch();

    // Get team members (board)
    $boardMembers = $db->query("SELECT * FROM team_members WHERE category = 'board' AND is_active = 1 ORDER BY display_order LIMIT 20")->fetchAll();
} catch (Exception $e) {
    if (!$page) {
        $page = null;
    }
    if (!is_array($boardMembers)) {
        $boardMembers = [];
    }
}

$lead = coop_load_leadership_messages($db instanceof PDO ? $db : null);
$hasChairMsg = trim((string)($lead['chairman_message'] ?? '')) !== '';
$hasCeoMsg = trim((string)($lead['ceo_message'] ?? '')) !== '';
?>
<script>
(function () {
  var h = (location.hash || '').toLowerCase();
  if (h === '#success-stories' || h === '#success') location.replace(<?php echo json_encode(rtrim(SITE_URL, '/') . '/success-stories.php'); ?>);
  else if (h === '#chairman' || h === '#chairman-message') location.replace(<?php echo json_encode(rtrim(SITE_URL, '/') . '/chairman-message.php'); ?>);
  else if (h === '#ceo' || h === '#ceo-message') location.replace(<?php echo json_encode(rtrim(SITE_URL, '/') . '/ceo-message.php'); ?>);
  else if (h === '#vision' || h === '#mission' || h === '#vision-mission') location.replace(<?php echo json_encode(rtrim(SITE_URL, '/') . '/vision-mission.php'); ?>);
  else if (h === '#why-choose' || h === '#why-us') location.replace(<?php echo json_encode(rtrim(SITE_URL, '/') . '/why-choose.php'); ?>);
})();
</script>
<?php
// Get about page image from settings (admin controlled) — missing file = no broken image tag
$aboutImageSetting = trim((string) getSetting('about_page_image', ''));
$aboutImageDefault = 'assets/images/about-image.jpg';
$aboutResolved = '';
foreach ([$aboutImageSetting, $aboutImageDefault] as $_abPath) {
    if ($_abPath === '') {
        continue;
    }
    $rel = ltrim($_abPath, '/');
    if (is_readable(ROOT_PATH . $rel)) {
        $aboutResolved = $rel;
        break;
    }
}
$hasAboutImage = $aboutResolved !== '';
$aboutIntroSetting = trim((string)getSetting('about_intro_image', ''));
$aboutVisual = '';
if ($aboutIntroSetting !== '' && is_readable(ROOT_PATH . ltrim($aboutIntroSetting, '/'))) {
    $aboutVisual = ltrim($aboutIntroSetting, '/');
}
if ($aboutVisual === '') {
    $aboutVisual = $aboutResolved;
}
if ($aboutVisual === '') {
    $historyFallback = trim((string)getSetting('history_photo', ''));
    if ($historyFallback !== '' && is_readable(ROOT_PATH . ltrim($historyFallback, '/'))) {
        $aboutVisual = ltrim($historyFallback, '/');
    }
}
$hasAboutVisual = $aboutVisual !== '';

// Static section titles (admin editable via pages static sections)
$valuesTitleNp = getSetting('values_content_title_np', 'हाम्रो मूल मान्यताहरू');
$valuesTitleEn = getSetting('values_content_title_en', 'Our Core Values');
?>

<!-- Page Banner -->
<section class="page-banner page-banner-modern">
    <div class="container">
        <div class="banner-content-modern">
            <h1 class="page-title-modern"><?php echo htmlspecialchars(is_array($page) ? ($page['title_np'] ?? 'हाम्रो बारेमा') : 'हाम्रो बारेमा', ENT_QUOTES, 'UTF-8'); ?></h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb breadcrumb-modern">
                    <li class="breadcrumb-item"><a href="<?php echo htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8'); ?>" class="breadcrumb-link-modern"><?php echo $L['home']; ?></a></li>
                    <li class="breadcrumb-item active"><?php echo $L['about'] ?? 'हाम्रो बारेमा'; ?></li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<!-- About Content -->
<section class="about-section section-padding" id="about">
    <div class="container">
        <div class="row align-items-start justify-content-center g-4">
            <div class="<?php echo $hasAboutVisual ? 'col-lg-7' : 'col-lg-10 col-xl-9'; ?> mb-2" data-aos="fade-right">
                <div class="about-content-box">
                    <div style="margin-bottom:4px;">
                        <span class="section-tag"><i class="lucide-icon" data-lucide="building" aria-hidden="true"></i> <?php echo isEnglish() ? 'About Us' : 'हाम्रो बारेमा'; ?></span>
                    </div>
                    <h2><?php echo isEnglish() ? 'Our Introduction' : 'हाम्रो परिचय'; ?></h2>
                    <div class="about-divider"></div>
                    <?php
                    /* मुख्य परिचय: Admin → गतिशील पृष्ठ → slug <code>about</code> मात्र (about_content_* हटाइयो) */
                    $pageArr = is_array($page) ? $page : [];
                    $pageBodyNp = trim((string) ($pageArr['content_np'] ?? ''));
                    $pageBodyEn = trim((string) ($pageArr['content'] ?? ''));
                    $pageHtml = isEnglish()
                        ? ($pageBodyEn !== '' ? $pageBodyEn : $pageBodyNp)
                        : ($pageBodyNp !== '' ? $pageBodyNp : $pageBodyEn);

                    if ($pageHtml !== ''):
                        echo '<div class="intro-text coop-prose">' . coop_render_cms_prose($pageHtml) . '</div>';
                    else:
                    ?>
                        <div class="intro-text">
                            <p><?php echo isEnglish() ? 'We are a leading community-based financial institution dedicated to serving our members with various financial services and promoting the spirit of cooperation.' : 'हामी समुदायमा आधारित एक अग्रणी वित्तीय संस्था हौं जसले आफ्ना सदस्यहरूलाई विभिन्न वित्तीय सेवाहरू प्रदान गर्दै सहकारिताको भावनालाई प्रवर्द्धन गर्दै आइरहेको छ।'; ?></p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($hasAboutVisual): ?>
            <div class="col-lg-5 mb-2" data-aos="fade-left">
                <div class="about-image-box about-image-box-side">
                    <div class="about-side-badge">
                        <i class="lucide-icon me-1" data-lucide="sprout" aria-hidden="true"></i><?php echo isEnglish() ? 'Journey of Trust' : 'विश्वासको यात्रा'; ?>
                    </div>
                    <img src="<?php echo e(safe_versioned_media_src($aboutVisual)); ?>"
                         alt="<?php echo isEnglish() ? 'About Us' : 'हाम्रो बारेमा'; ?>"
                         class="img-fluid rounded-4"
                         loading="lazy"
                         decoding="async">
                    <div class="about-year-badge">
                        <span class="year"><?php echo getSetting('established_year', '२०७५'); ?></span>
                        <span class="text"><?php echo isEnglish() ? 'Est.' : 'स्थापना'; ?></span>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</section>


<!-- History Section - Eye Catching Design -->
<section class="history-section-v2 section-padding" id="history">
    <div class="container">
        <div class="row align-items-start g-4">
            <div class="col-lg-5 mb-2" data-aos="fade-right">
                <?php
                /*
                 * Issue #14 FIX:
                 * - Static bank icon हटाइयो
                 * - Admin ले photo upload गर्न मिल्छ (admin/about-settings.php)
                 * - Photo भएमा photo देखिन्छ, नभए icon-only box देखिन्छ
                 */
                $historyPhoto = getSetting('history_photo', '');
                $hasHistoryPhoto = !empty($historyPhoto) && file_exists(ROOT_PATH . $historyPhoto);
                ?>

                <?php if ($hasHistoryPhoto): ?>
                <!-- History photo — admin ले upload गरेको photo -->
                <div class="history-image-box">
                    <img src="<?php echo e(safe_versioned_media_src($historyPhoto)); ?>"
                         alt="<?php echo isEnglish() ? 'Our History' : 'हाम्रो इतिहास'; ?>"
                         class="img-fluid rounded shadow history-photo-cover"
                         loading="lazy"
                         decoding="async">
                    <!-- Established year badge -->
                    <div class="history-year-badge">
                        <?php echo getSetting('established_year', '२०७५'); ?>
                    </div>
                    <div class="history-badge">
                        <i class="lucide-icon" data-lucide="history" aria-hidden="true"></i>
                    </div>
                </div>

                <?php else: ?>
                <!-- Photo छैन भने modern decorative box देखाउनुहोस् — icon नहटाइएकोले icon-only -->
                <div class="history-image-box history-icon-only">
                    <div class="history-badge">
                        <i class="lucide-icon" data-lucide="history" aria-hidden="true"></i>
                    </div>
                    <div class="history-year-badge">
                        <?php echo getSetting('established_year', '२०७५'); ?>
                    </div>
                    <!-- Static bank icon हटाइयो — empty decorative ring मात्र -->
                    <div class="history-icon-center">
                        <!-- Admin ले about-settings.php बाट photo upload गर्न सक्छ -->
                        <div class="history-icon-ring"></div>
                        <div class="history-empty-photo">
                            <i class="lucide-icon lucide-2x mb-2 d-block history-empty-photo-icon" data-lucide="camera" aria-hidden="true"></i>
                            <small class="history-empty-photo-note"><?php echo isEnglish() ? 'Photo not available - please upload a photo.' : 'फोटो उपलब्ध छैन — कृपया फोटो थप्नुहोस्'; ?></small>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="col-lg-7" data-aos="fade-left">
                <div class="history-content-v2">
                    <div style="margin-bottom:4px;">
                        <span class="section-tag"><i class="lucide-icon" data-lucide="history" aria-hidden="true"></i> <?php echo isEnglish() ? 'Our Journey' : 'हाम्रो यात्रा'; ?></span>
                    </div>
                    <h2><?php echo isEnglish() ? 'Our History' : 'हाम्रो इतिहास'; ?></h2>
                    <div class="history-divider"></div>
                    <div class="history-text coop-prose">
                        <?php
                        $historyContent = isEnglish() ? getSetting('history_content_en', '') : getSetting('history_content_np', '');
                        if ($historyContent):
                            echo coop_render_cms_prose($historyContent);
                        else:
                        ?>
                        <p><?php echo isEnglish() ? 'Our cooperative has a rich history of serving the community. Established with the vision of financial inclusion, we have grown to become one of the most trusted financial institutions in our area.' : 'हाम्रो सहकारीको समुदायको सेवामा समृद्ध इतिहास छ। वित्तीय समावेशीताको दृष्टिकोणका साथ स्थापित, हामी हाम्रो क्षेत्रमा सबैभन्दा विश्वसनीय वित्तीय संस्थाहरू मध्ये एक बन्न विकसित भएका छौं।'; ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Vision & Mission — teaser; full content on vision-mission.php -->
<section class="vision-section-v2 section-padding bg-light" id="vision-teaser">
    <div class="container">
        <div class="section-header text-center mb-4" data-aos="fade-up">
            <div class="section-badge-wrap">
                <span class="section-badge">
                    <i class="lucide-icon" aria-hidden="true" data-lucide="eye"></i>
                    <?php echo isEnglish() ? 'Our Purpose' : 'हाम्रो उद्देश्य'; ?>
                </span>
            </div>
            <h2><?php echo htmlspecialchars(isEnglish() ? $visionMissionMenuEn : $visionMissionMenuNp, ENT_QUOTES, 'UTF-8'); ?></h2>
            <div class="section-divider"></div>
            <p class="mb-3"><?php echo isEnglish()
                ? 'Read our full vision and mission on the dedicated page.'
                : 'पूर्ण दृष्टि र लक्ष्य छुट्टै पृष्ठमा पढ्नुहोस्।'; ?></p>
            <a href="<?php echo htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8'); ?>vision-mission.php" class="btn btn-primary">
                <i class="lucide-icon me-1" data-lucide="eye" aria-hidden="true"></i><?php echo isEnglish() ? 'View Vision & Mission' : 'दृष्टि र लक्ष्य हेर्नुहोस्'; ?>
            </a>
        </div>
    </div>
</section>


<!-- Leadership / related pages (dedicated pages — like Institutional Profile) -->
<section class="section-padding bg-light" id="about-related">
    <div class="container">
        <div class="section-header text-center mb-4" data-aos="fade-up">
            <div class="section-badge-wrap">
                <span class="section-badge"><i class="lucide-icon" data-lucide="link" aria-hidden="true"></i> <?php echo isEnglish() ? 'Explore' : 'थप जान्नुहोस्'; ?></span>
            </div>
            <h2><?php echo isEnglish() ? 'More about us' : 'हाम्रो बारे थप'; ?></h2>
            <div class="section-divider"></div>
            <p><?php echo isEnglish()
                ? 'Leadership messages, member stories, vision, and institutional profile — each on its own page.'
                : 'नेतृत्व सन्देश, सदस्य कथा, दृष्टि र संस्थागत प्रोफाइल — प्रत्येक छुट्टै पृष्ठमा।'; ?></p>
        </div>
        <div class="row g-3 justify-content-center">
            <?php if ($hasChairMsg): ?>
            <div class="col-md-6 col-lg-3" data-aos="fade-up">
                <a href="<?php echo htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8'); ?>chairman-message.php" class="value-card text-decoration-none d-block h-100">
                    <div class="value-icon"><i class="lucide-icon" data-lucide="user-round" aria-hidden="true"></i></div>
                    <h5><?php echo htmlspecialchars(isEnglish() ? $chairmanMenuLabelEn : $chairmanMenuLabelNp, ENT_QUOTES, 'UTF-8'); ?></h5>
                </a>
            </div>
            <?php endif; ?>
            <?php if ($hasCeoMsg): ?>
            <div class="col-md-6 col-lg-3" data-aos="fade-up" data-aos-delay="60">
                <a href="<?php echo htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8'); ?>ceo-message.php" class="value-card text-decoration-none d-block h-100">
                    <div class="value-icon"><i class="lucide-icon" data-lucide="briefcase" aria-hidden="true"></i></div>
                    <h5><?php echo htmlspecialchars(isEnglish() ? $ceoMenuLabelEn : $ceoMenuLabelNp, ENT_QUOTES, 'UTF-8'); ?></h5>
                </a>
            </div>
            <?php endif; ?>
            <div class="col-md-6 col-lg-3" data-aos="fade-up" data-aos-delay="90">
                <a href="<?php echo htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8'); ?>vision-mission.php" class="value-card text-decoration-none d-block h-100">
                    <div class="value-icon"><i class="lucide-icon" data-lucide="eye" aria-hidden="true"></i></div>
                    <h5><?php echo htmlspecialchars(isEnglish() ? $visionMissionMenuEn : $visionMissionMenuNp, ENT_QUOTES, 'UTF-8'); ?></h5>
                </a>
            </div>
            <div class="col-md-6 col-lg-3" data-aos="fade-up" data-aos-delay="120">
                <a href="<?php echo htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8'); ?>institutional-profile.php" class="value-card text-decoration-none d-block h-100">
                    <div class="value-icon"><i class="lucide-icon" data-lucide="building-2" aria-hidden="true"></i></div>
                    <h5><?php echo isEnglish() ? 'Institutional Profile' : 'संस्थागत प्रोफाइल'; ?></h5>
                </a>
            </div>
            <div class="col-md-6 col-lg-3" data-aos="fade-up" data-aos-delay="150">
                <a href="<?php echo htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8'); ?>why-choose.php" class="value-card text-decoration-none d-block h-100">
                    <div class="value-icon"><i class="lucide-icon" data-lucide="circle-check" aria-hidden="true"></i></div>
                    <h5><?php echo isEnglish() ? 'Why Choose Us' : 'किन हामीलाई छान्ने?'; ?></h5>
                </a>
            </div>
            <div class="col-md-6 col-lg-3" data-aos="fade-up" data-aos-delay="180">
                <a href="<?php echo htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8'); ?>success-stories.php" class="value-card text-decoration-none d-block h-100">
                    <div class="value-icon"><i class="lucide-icon" data-lucide="book-open" aria-hidden="true"></i></div>
                    <h5><?php echo isEnglish() ? 'Member Success Stories' : 'सदस्यको सफलताको कथा'; ?></h5>
                </a>
            </div>
        </div>
    </div>
</section>

<!-- Core Values Section - Consolidated -->
<section class="values-section section-padding" id="values">
    <div class="container">
        <div class="section-header text-center mb-4" data-aos="fade-up">
            <div class="section-badge-wrap">
                <span class="section-badge"><i class="lucide-icon" data-lucide="heart" aria-hidden="true"></i> <?php echo isEnglish() ? 'Values' : 'मूल्यहरू'; ?></span>
            </div>
            <h2><?php echo htmlspecialchars(isEnglish() ? $valuesTitleEn : $valuesTitleNp, ENT_QUOTES, 'UTF-8'); ?></h2>
        </div>
        <div class="row justify-content-center">
            <div class="col-lg-3 col-md-6 mb-4" data-aos="fade-up" data-aos-delay="0">
                <div class="value-card">
                    <div class="value-icon">
                        <i class="lucide-icon" aria-hidden="true" data-lucide="handshake"></i>
                    </div>
                    <h5><?php echo isEnglish() ? 'Integrity' : 'इमानदारिता'; ?></h5>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-4" data-aos="fade-up" data-aos-delay="100">
                <div class="value-card">
                    <div class="value-icon">
                        <i class="lucide-icon" aria-hidden="true" data-lucide="eye"></i>
                    </div>
                    <h5><?php echo isEnglish() ? 'Transparency' : 'पारदर्शिता'; ?></h5>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-4" data-aos="fade-up" data-aos-delay="200">
                <div class="value-card">
                    <div class="value-icon">
                        <i class="lucide-icon" aria-hidden="true" data-lucide="users"></i>
                    </div>
                    <h5><?php echo isEnglish() ? 'Cooperation' : 'सहयोग'; ?></h5>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-4" data-aos="fade-up" data-aos-delay="300">
                <div class="value-card">
                    <div class="value-icon">
                        <i class="lucide-icon" aria-hidden="true" data-lucide="star"></i>
                    </div>
                    <h5><?php echo isEnglish() ? 'Excellence' : 'उत्कृष्टता'; ?></h5>
                </div>
            </div>
        </div>
    </div>
</section>


<!-- Board Members - Same design as team.php -->
<?php if (!empty($boardMembers)): ?>
<section class="team-section section-padding bg-light" id="board">
    <div class="container">
        <div class="section-header text-center mb-4" data-aos="fade-up">
            <div class="section-badge-wrap">
                <span class="section-badge"><i class="lucide-icon" data-lucide="users-round" aria-hidden="true"></i> <?php echo isEnglish() ? 'Board' : 'समिति'; ?></span>
            </div>
            <h2><?php echo isEnglish() ? 'Board of Directors' : 'सञ्चालक समिति'; ?></h2>
            <div class="section-divider"></div>
            <p><?php echo isEnglish() ? 'Leadership team guiding our cooperative' : 'हाम्रो संस्थाको नेतृत्व गर्ने समिति'; ?></p>
        </div>

        <div class="team-org-chart-wrap">
            <?php echo team_render_org_chart($boardMembers, ['english' => isEnglish()]); ?>
        </div>

        <div class="text-center mt-4" data-aos="fade-up">
            <a href="team.php" class="btn btn-outline-primary btn-lg">
                <i class="lucide-icon" aria-hidden="true" data-lucide="users"></i> <?php echo isEnglish() ? 'View All Team Members' : 'सबै सदस्यहरू हेर्नुहोस्'; ?>
            </a>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Statistics -->
<section class="stats-section" id="stats">
    <div class="container">
        <div class="row">
            <div class="col-lg-3 col-md-6 mb-4" data-aos="fade-up" data-aos-delay="0">
                <div class="stat-box">
                    <div class="stat-icon" aria-hidden="true"><i class="lucide-icon" data-lucide="users" aria-hidden="true"></i></div>
                    <div class="stat-number"><?php echo getSetting('total_members', '५०००'); ?>+</div>
                    <div class="stat-label"><?php echo isEnglish() ? 'Members' : 'सदस्यहरू'; ?></div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-4" data-aos="fade-up" data-aos-delay="100">
                <div class="stat-box">
                    <div class="stat-icon" aria-hidden="true"><i class="lucide-icon" data-lucide="award" aria-hidden="true"></i></div>
                    <div class="stat-number"><?php echo getSetting('years_experience', '२०'); ?>+</div>
                    <div class="stat-label"><?php echo isEnglish() ? 'Years Experience' : 'वर्षको अनुभव'; ?></div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-4" data-aos="fade-up" data-aos-delay="200">
                <div class="stat-box">
                    <div class="stat-icon" aria-hidden="true"><i class="lucide-icon" data-lucide="handshake" aria-hidden="true"></i></div>
                    <div class="stat-number"><?php echo getSetting('total_services', '१०'); ?>+</div>
                    <div class="stat-label"><?php echo isEnglish() ? 'Services' : 'सेवाहरू'; ?></div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-4" data-aos="fade-up" data-aos-delay="300">
                <div class="stat-box">
                    <div class="stat-icon" aria-hidden="true"><i class="lucide-icon" data-lucide="smile" aria-hidden="true"></i></div>
                    <div class="stat-number"><?php echo getSetting('satisfaction_rate', '९९'); ?>%</div>
                    <div class="stat-label"><?php echo isEnglish() ? 'Satisfied Customers' : 'सन्तुष्ट ग्राहक'; ?></div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once 'includes/footer.php'; ?>
