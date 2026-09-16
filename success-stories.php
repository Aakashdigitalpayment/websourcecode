<?php
/**
 * Public: सदस्य सफलताका कथा — dedicated page (About dropdown).
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/member-success-stories-tables.php';

$pageTitle = isEnglish() ? 'Member Success Stories' : 'सदस्यको सफलताको कथा';
$pageDescription = isEnglish()
    ? 'How members improved their livelihood after joining — and how our cooperative helped.'
    : 'सदस्य बनेपछि जीवन र आर्जनमा कसरी सुधार भयो — र संस्थाले कसरी सहयोग गर्‍यो।';
$extraHead = (isset($extraHead) ? (string) $extraHead : '')
    . (function_exists('coopThemeLinkHtml')
        ? coopThemeLinkHtml('assets/css/about-success-stories.css')
        : '');
require_once __DIR__ . '/includes/header.php';

$successStories = [];
try {
    $successStories = fetchActiveMemberSuccessStories(function_exists('getDB') ? getDB() : null, 48);
} catch (Throwable $e) {
    $successStories = [];
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

<section class="mss-section section-padding" id="success-stories">
    <div class="container">
        <div class="section-header text-center mb-4" data-aos="fade-up">
            <div class="section-badge-wrap">
                <span class="section-badge"><i class="lucide-icon" data-lucide="book-open" aria-hidden="true"></i> <?php echo isEnglish() ? 'Success Stories' : 'सफलताका कथा'; ?></span>
            </div>
            <h2><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></h2>
            <div class="section-divider"></div>
            <p><?php echo htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>

        <?php if (empty($successStories)): ?>
        <div class="text-center text-muted py-5" data-aos="fade-up">
            <i class="lucide-icon mb-2 d-block opacity-25" data-lucide="book-open" aria-hidden="true" style="font-size:2.5rem;"></i>
            <p class="mb-0"><?php echo isEnglish() ? 'Member success stories will appear here soon.' : 'सदस्यका सफलताका कथाहरू चाँडै यहाँ देखिनेछन्।'; ?></p>
        </div>
        <?php else: ?>
        <div class="mss-grid">
            <?php foreach ($successStories as $i => $story):
                $en = isEnglish();
                $name = $en
                    ? (trim((string)($story['member_name_en'] ?? '')) !== '' ? $story['member_name_en'] : $story['member_name'])
                    : (trim((string)($story['member_name'] ?? '')) !== '' ? $story['member_name'] : ($story['member_name_en'] ?? ''));
                $headline = $en
                    ? (trim((string)($story['headline_en'] ?? '')) !== '' ? $story['headline_en'] : $story['headline_np'])
                    : (trim((string)($story['headline_np'] ?? '')) !== '' ? $story['headline_np'] : ($story['headline_en'] ?? ''));
                $body = $en
                    ? (trim((string)($story['story_en'] ?? '')) !== '' ? $story['story_en'] : $story['story_np'])
                    : (trim((string)($story['story_np'] ?? '')) !== '' ? $story['story_np'] : ($story['story_en'] ?? ''));
                $help = $en
                    ? (trim((string)($story['institution_help_en'] ?? '')) !== '' ? $story['institution_help_en'] : ($story['institution_help_np'] ?? ''))
                    : (trim((string)($story['institution_help_np'] ?? '')) !== '' ? $story['institution_help_np'] : ($story['institution_help_en'] ?? ''));
                $loc = $en
                    ? (trim((string)($story['location_en'] ?? '')) !== '' ? $story['location_en'] : ($story['location'] ?? ''))
                    : (trim((string)($story['location'] ?? '')) !== '' ? $story['location'] : ($story['location_en'] ?? ''));
                $prof = $en
                    ? (trim((string)($story['profession_en'] ?? '')) !== '' ? $story['profession_en'] : ($story['profession'] ?? ''))
                    : (trim((string)($story['profession'] ?? '')) !== '' ? $story['profession'] : ($story['profession_en'] ?? ''));
                $since = trim((string)($story['member_since'] ?? ''));
                $photo = trim((string)($story['photo'] ?? ''));
                $photoOk = $photo !== '' && is_readable(ROOT_PATH . ltrim($photo, '/'));
                $photoSrc = $photoOk
                    ? (function_exists('safe_versioned_media_src') ? safe_versioned_media_src($photo) : (function_exists('safe_media_src') ? safe_media_src($photo) : $photo))
                    : '';
            ?>
            <article class="mss-card" data-aos="fade-up" data-aos-delay="<?php echo (int) min(300, $i * 80); ?>">
                <div class="mss-card-top">
                    <?php if ($photoSrc !== ''): ?>
                    <img class="mss-photo" src="<?php echo htmlspecialchars($photoSrc, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8'); ?>" loading="lazy" decoding="async" width="72" height="72">
                    <?php else: ?>
                    <div class="mss-photo mss-photo-ph" aria-hidden="true"><i class="lucide-icon" data-lucide="user" aria-hidden="true"></i></div>
                    <?php endif; ?>
                    <div class="mss-meta">
                        <h3><?php echo htmlspecialchars((string) $name, ENT_QUOTES, 'UTF-8'); ?></h3>
                        <?php if (trim((string) $headline) !== ''): ?>
                        <p class="mss-headline"><?php echo htmlspecialchars((string) $headline, ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($since !== '' || trim((string) $loc) !== '' || trim((string) $prof) !== ''): ?>
                <div class="mss-chips">
                    <?php if ($since !== ''): ?>
                    <span class="mss-chip"><i class="lucide-icon" data-lucide="calendar" aria-hidden="true"></i><?php echo htmlspecialchars($since, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                    <?php if (trim((string) $loc) !== ''): ?>
                    <span class="mss-chip"><i class="lucide-icon" data-lucide="map-pin" aria-hidden="true"></i><?php echo htmlspecialchars((string) $loc, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                    <?php if (trim((string) $prof) !== ''): ?>
                    <span class="mss-chip"><i class="lucide-icon" data-lucide="briefcase" aria-hidden="true"></i><?php echo htmlspecialchars((string) $prof, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <?php if (trim((string) $body) !== ''): ?>
                <p class="mss-story"><?php echo htmlspecialchars((string) $body, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
                <?php if (trim((string) $help) !== ''): ?>
                <div class="mss-help">
                    <div class="mss-help-label"><i class="lucide-icon" data-lucide="handshake" aria-hidden="true"></i><?php echo isEnglish() ? 'How we helped' : 'संस्थाले गरेको सहयोग'; ?></div>
                    <p><?php echo htmlspecialchars((string) $help, ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <?php endif; ?>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="text-center mt-4" data-aos="fade-up">
            <a href="<?php echo htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8'); ?>about.php" class="btn btn-outline-primary">
                <i class="lucide-icon me-1" data-lucide="arrow-left" aria-hidden="true"></i><?php echo isEnglish() ? 'Back to About' : 'हाम्रो बारेमा फर्कनुहोस्'; ?>
            </a>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
