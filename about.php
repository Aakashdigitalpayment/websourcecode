<?php
require_once 'includes/config.php';
$pageTitle = isEnglish() ? 'About Us' : 'हाम्रो बारेमा';
require_once 'includes/header.php';
?>

<?php
// Get about page content
try {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM pages WHERE slug = 'about' AND is_active = 1 ORDER BY id DESC LIMIT 1");
    $stmt->execute();
    $page = $stmt->fetch();

    // Get team members (board)
    $boardMembers = $db->query("SELECT * FROM team_members WHERE category = 'board' AND is_active = 1 ORDER BY display_order LIMIT 20")->fetchAll();
} catch (Exception $e) {
    $page = null;
    $boardMembers = [];
}

// Get about page image from settings (admin controlled) — missing file = no broken <img>
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

// Static section titles (admin editable via pages-v2 static sections)
$visionTitleNp = getSetting('vision_content_title_np', 'हाम्रो दृष्टिकोण');
$visionTitleEn = getSetting('vision_content_title_en', 'Our Vision');
$missionTitleNp = getSetting('mission_content_title_np', 'हाम्रो लक्ष्य');
$missionTitleEn = getSetting('mission_content_title_en', 'Our Mission');
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
                    <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>" class="breadcrumb-link-modern"><?php echo $L['home']; ?></a></li>
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
                        <span class="section-tag"><i class="fas fa-building"></i> <?php echo isEnglish() ? 'About Us' : 'हाम्रो बारेमा'; ?></span>
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
                        echo '<div class="intro-text coop-prose">' . $pageHtml . '</div>';
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
                        <i class="fas fa-seedling me-1"></i><?php echo isEnglish() ? 'Journey of Trust' : 'विश्वासको यात्रा'; ?>
                    </div>
                    <img src="<?php echo SITE_URL . htmlspecialchars($aboutVisual, ENT_QUOTES, 'UTF-8'); ?>?v=<?php echo (int) filemtime(ROOT_PATH . $aboutVisual); ?>"
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
                    <img src="<?php echo SITE_URL . $historyPhoto; ?>"
                         alt="<?php echo isEnglish() ? 'Our History' : 'हाम्रो इतिहास'; ?>"
                         class="img-fluid rounded shadow history-photo-cover">
                    <!-- Established year badge -->
                    <div class="history-year-badge">
                        <?php echo getSetting('established_year', '२०७५'); ?>
                    </div>
                    <div class="history-badge">
                        <i class="fas fa-history"></i>
                    </div>
                </div>

                <?php else: ?>
                <!-- Photo छैन भने modern decorative box देखाउनुहोस् — icon नहटाइएकोले icon-only -->
                <div class="history-image-box history-icon-only">
                    <div class="history-badge">
                        <i class="fas fa-history"></i>
                    </div>
                    <div class="history-year-badge">
                        <?php echo getSetting('established_year', '२०७५'); ?>
                    </div>
                    <!-- Static bank icon हटाइयो — empty decorative ring मात्र -->
                    <div class="history-icon-center">
                        <!-- Admin ले about-settings.php बाट photo upload गर्न सक्छ -->
                        <div class="history-icon-ring"></div>
                        <div class="history-empty-photo">
                            <i class="fas fa-camera fa-2x mb-2 d-block history-empty-photo-icon"></i>
                            <small class="history-empty-photo-note"><?php echo isEnglish() ? 'Photo not available - please upload a photo.' : 'फोटो उपलब्ध छैन — कृपया फोटो थप्नुहोस्'; ?></small>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="col-lg-7" data-aos="fade-left">
                <div class="history-content-v2">
                    <div style="margin-bottom:4px;">
                        <span class="section-tag"><i class="fas fa-history"></i> <?php echo isEnglish() ? 'Our Journey' : 'हाम्रो यात्रा'; ?></span>
                    </div>
                    <h2><?php echo isEnglish() ? 'Our History' : 'हाम्रो इतिहास'; ?></h2>
                    <div class="history-divider"></div>
                    <div class="history-text coop-prose">
                        <?php
                        $historyContent = isEnglish() ? getSetting('history_content_en', '') : getSetting('history_content_np', '');
                        if ($historyContent):
                            echo $historyContent;
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

<!-- Vision & Mission Section - Eye Catching Design -->
<section class="vision-section-v2 section-padding bg-light" id="vision">
    <div class="container">
        <div class="section-header text-center mb-5" data-aos="fade-up">
            <div class="section-badge-wrap">
                <span class="section-badge">
                    <i class="lucide-icon" aria-hidden="true" data-lucide="eye"></i>
                    <?php echo isEnglish() ? 'Our Purpose' : 'हाम्रो उद्देश्य'; ?>
                </span>
            </div>
            <h2><?php echo isEnglish() ? 'Vision & Mission' : 'दृष्टि र लक्ष्य'; ?></h2>
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
                            echo '<p>' . $visionContent . '</p>';
                        else:
                        ?>
                        <p><?php echo isEnglish() ? 'To be the most trusted and preferred cooperative in our community.' : 'समुदायमा सबैभन्दा विश्वसनीय र रुचाइएको सहकारी संस्था बन्नु।'; ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="vision-card-decoration"></div>
                </div>
            </div>
            <div class="col-md-6" data-aos="fade-up" data-aos-delay="200">
                <div class="vision-card-v2 mission">
                    <div class="vision-card-glow"></div>
                    <div class="vision-icon-v2">
                        <i class="fas fa-bullseye"></i>
                    </div>
                    <div class="vision-card-content coop-prose">
                        <h4><?php echo htmlspecialchars(isEnglish() ? $missionTitleEn : $missionTitleNp, ENT_QUOTES, 'UTF-8'); ?></h4>
                        <?php
                        $missionContent = isEnglish() ? getSetting('mission_content_en', '') : getSetting('mission_content_np', '');
                        if ($missionContent):
                            echo '<p>' . $missionContent . '</p>';
                        else:
                        ?>
                        <p><?php echo isEnglish() ? 'To provide quality financial services while promoting the spirit of cooperation and helping members achieve their financial goals.' : 'सहकारिताको भावनालाई प्रवर्द्धन गर्दै सदस्यहरूलाई उनीहरूको वित्तीय लक्ष्य हासिल गर्न मद्दत गर्ने गुणस्तरीय वित्तीय सेवा प्रदान गर्नु।'; ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="vision-card-decoration"></div>
                </div>
            </div>
        </div>
    </div>
</section>


<!-- Leadership Messages Section -->
<?php
// Get from settings first
$chairmanMessageSetting = getSetting('chairman_message_np', '');
$chairmanNameSetting = getSetting('chairman_name', '');
$chairmanPhotoSetting = getSetting('chairman_photo', '');
$ceoMessageSetting = getSetting('ceo_message_np', '');
$ceoNameSetting = getSetting('ceo_name', '');
$ceoPhotoSetting = getSetting('ceo_photo', '');
$ceoDesignationNp = trim((string)getSetting('ceo_designation_np', 'प्रमुख कार्यकारी अधिकृत'));
$ceoDesignationEn = trim((string)getSetting('ceo_designation_en', 'Chief Executive Officer'));

// Fallback: Get chairman/CEO from team_members table based on is_chairman/is_ceo flags
try {
    // Chairman
    if (empty($chairmanNameSetting) || empty($chairmanMessageSetting)) {
        $chairStmt = $db->prepare("SELECT * FROM team_members WHERE is_chairman = 1 AND is_active = 1 LIMIT 1");
        $chairStmt->execute();
        $chairFromTeam = $chairStmt->fetch();
        if ($chairFromTeam) {
            if (empty($chairmanNameSetting)) {
                $chairmanNameSetting = $chairFromTeam['name_np'] ?: $chairFromTeam['name'];
            }
            if (empty($chairmanPhotoSetting)) {
                $chairmanPhotoSetting = $chairFromTeam['photo'];
            }
            // If no message in settings, use position as fallback message
            if (empty($chairmanMessageSetting)) {
                $chairmanMessageSetting = $chairFromTeam['position_np'] ?: $chairFromTeam['position'];
            }
        }
    }
    
    // CEO
    if (empty($ceoNameSetting) || empty($ceoMessageSetting)) {
        $ceoStmt = $db->prepare("SELECT * FROM team_members WHERE is_ceo = 1 AND is_active = 1 LIMIT 1");
        $ceoStmt->execute();
        $ceoFromTeam = $ceoStmt->fetch();
        if ($ceoFromTeam) {
            if (empty($ceoNameSetting)) {
                $ceoNameSetting = $ceoFromTeam['name_np'] ?: $ceoFromTeam['name'];
            }
            if (empty($ceoPhotoSetting)) {
                $ceoPhotoSetting = $ceoFromTeam['photo'];
            }
            // If no message in settings, use position as fallback message
            if (empty($ceoMessageSetting)) {
                $ceoMessageSetting = $ceoFromTeam['position_np'] ?: $ceoFromTeam['position'];
            }
        }
    }
} catch (Throwable $e) { /* silent fallback */ }

// Use settings or team member data
$chairmanName = $chairmanNameSetting ?: 'अध्यक्ष';
$chairmanPhoto = $chairmanPhotoSetting;
$chairmanMessage = $chairmanMessageSetting;
$ceoName = $ceoNameSetting ?: 'प्रमुख कार्यकारी अधिकृत';
$ceoPhoto = $ceoPhotoSetting;
$ceoMessage = $ceoMessageSetting;
?>
<?php if ($chairmanMessage || $ceoMessage): ?>
<section class="leadership-messages-about section-padding bg-light" id="chairman">
    <div class="container">
        <div class="section-header text-center mb-4" data-aos="fade-up">
            <div class="section-badge-wrap">
                <span class="section-badge"><i class="fas fa-quote-left"></i> <?php echo isEnglish() ? 'Leadership' : 'नेतृत्व'; ?></span>
            </div>
            <h2><?php echo isEnglish() ? 'Messages from Leadership' : 'नेतृत्वबाट सन्देश'; ?></h2>
            <div class="section-divider"></div>
            <p><?php echo isEnglish() ? ('Words from our Chairman and ' . $ceoDesignationEn) : ('हाम्रो अध्यक्ष र ' . $ceoDesignationNp . 'का शब्दहरू'); ?></p>
        </div>

        <?php if ($chairmanMessage): ?>
        <div class="leadership-message-full mb-5" id="chairman-message">
            <div class="row align-items-center">
                <div class="col-lg-3 col-md-4 text-center mb-4 mb-md-0">
                    <div class="leader-photo-large">
                        <?php if ($chairmanPhoto): ?>
                        <img src="<?php echo SITE_URL . $chairmanPhoto; ?>?v=<?php echo time(); ?>" alt="<?php echo $chairmanName; ?>">
                        <?php else: ?>
                        <div class="photo-placeholder-large">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <?php endif; ?>
                    </div>
                    <h4 class="mt-3"><?php echo $chairmanName; ?></h4>
                    <span class="leader-position"><?php echo isEnglish() ? 'Chairman' : 'अध्यक्ष'; ?></span>
                </div>
                <div class="col-lg-9 col-md-8">
                    <div class="message-content-full">
                        <i class="fas fa-quote-left quote-icon-large"></i>
                        <div class="message-text-full coop-prose">
                            <?php echo $chairmanMessage; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($ceoMessage): ?>
        <div class="leadership-message-full" id="ceo-message">
            <div class="row align-items-center flex-md-row-reverse">
                <div class="col-lg-3 col-md-4 text-center mb-4 mb-md-0">
                    <div class="leader-photo-large">
                        <?php if ($ceoPhoto): ?>
                        <img src="<?php echo SITE_URL . $ceoPhoto; ?>?v=<?php echo time(); ?>" alt="<?php echo $ceoName; ?>">
                        <?php else: ?>
                        <div class="photo-placeholder-large">
                            <i class="fas fa-user-tie"></i>
                        </div>
                        <?php endif; ?>
                    </div>
                    <h4 class="mt-3"><?php echo $ceoName; ?></h4>
                    <span class="leader-position"><?php echo isEnglish() ? $ceoDesignationEn : $ceoDesignationNp; ?></span>
                </div>
                <div class="col-lg-9 col-md-8">
                    <div class="message-content-full">
                        <i class="fas fa-quote-left quote-icon-large"></i>
                        <div class="message-text-full coop-prose">
                            <?php echo $ceoMessage; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<!-- Core Values Section - Consolidated -->
<section class="values-section section-padding" id="values">
    <div class="container">
        <div class="section-header text-center mb-4" data-aos="fade-up">
            <div class="section-badge-wrap">
                <span class="section-badge"><i class="fas fa-heart"></i> <?php echo isEnglish() ? 'Values' : 'मूल्यहरू'; ?></span>
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
                <span class="section-badge"><i class="fas fa-users-cog"></i> <?php echo isEnglish() ? 'Board' : 'समिति'; ?></span>
            </div>
            <h2><?php echo isEnglish() ? 'Board of Directors' : 'सञ्चालक समिति'; ?></h2>
            <div class="section-divider"></div>
            <p><?php echo isEnglish() ? 'Leadership team guiding our cooperative' : 'हाम्रो संस्थाको नेतृत्व गर्ने समिति'; ?></p>
        </div>

        <div class="row justify-content-center">
            <?php foreach ($boardMembers as $index => $member): ?>
            <div class="col-lg-3 col-md-4 col-sm-6 mb-4" data-aos="fade-up" data-aos-delay="<?php echo $index * 50; ?>">
                <div class="team-card-circular <?php echo $index === 0 ? 'featured' : ''; ?>">
                    <div class="team-photo-circular">
                        <?php if ($member['photo']): ?>
                            <img src="<?php echo $member['photo']; ?>" loading="lazy"  alt="<?php echo $member['name']; ?>">
                        <?php else: ?>
                            <div class="team-placeholder-circular"><i class="lucide-icon" aria-hidden="true" data-lucide="user"></i></div>
                        <?php endif; ?>
                    </div>
                    <div class="team-info-circular">
                        <h5><?php echo $member['name']; ?></h5>
                        <?php if (!empty($member['name_en'])): ?>
                        <p class="team-name-en"><?php echo $member['name_en']; ?></p>
                        <?php endif; ?>
                        <span class="team-position-badge"><?php echo $member['position_np'] ?: $member['position']; ?></span>
                        <?php if (!empty($member['phone']) || !empty($member['email'])): ?>
                        <div class="team-contact-circular">
                            <?php if (!empty($member['phone'])): ?>
                                <a href="tel:<?php echo $member['phone']; ?>" title="<?php echo $member['phone']; ?>"><i class="fas fa-phone"></i></a>
                            <?php endif; ?>
                            <?php if (!empty($member['email'])): ?>
                                <a href="mailto:<?php echo $member['email']; ?>" title="<?php echo $member['email']; ?>"><i class="fas fa-envelope"></i></a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
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
                    <div class="stat-icon" aria-hidden="true"><i class="fas fa-users"></i></div>
                    <div class="stat-number"><?php echo getSetting('total_members', '५०००'); ?>+</div>
                    <div class="stat-label"><?php echo isEnglish() ? 'Members' : 'सदस्यहरू'; ?></div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-4" data-aos="fade-up" data-aos-delay="100">
                <div class="stat-box">
                    <div class="stat-icon" aria-hidden="true"><i class="fas fa-award"></i></div>
                    <div class="stat-number"><?php echo getSetting('years_experience', '२०'); ?>+</div>
                    <div class="stat-label"><?php echo isEnglish() ? 'Years Experience' : 'वर्षको अनुभव'; ?></div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-4" data-aos="fade-up" data-aos-delay="200">
                <div class="stat-box">
                    <div class="stat-icon" aria-hidden="true"><i class="fas fa-handshake"></i></div>
                    <div class="stat-number"><?php echo getSetting('total_services', '१०'); ?>+</div>
                    <div class="stat-label"><?php echo isEnglish() ? 'Services' : 'सेवाहरू'; ?></div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-4" data-aos="fade-up" data-aos-delay="300">
                <div class="stat-box">
                    <div class="stat-icon" aria-hidden="true"><i class="fas fa-smile"></i></div>
                    <div class="stat-number"><?php echo getSetting('satisfaction_rate', '९९'); ?>%</div>
                    <div class="stat-label"><?php echo isEnglish() ? 'Satisfied Customers' : 'सन्तुष्ट ग्राहक'; ?></div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once 'includes/footer.php'; ?>
