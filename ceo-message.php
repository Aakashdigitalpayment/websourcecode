<?php
/**
 * Public: प्रमुख कार्यकारी अधिकृतको सन्देश — dedicated page (About dropdown).
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/leadership-message-helpers.php';

$lead = coop_load_leadership_messages(function_exists('getDB') ? getDB() : null);
$ceoDesignation = isEnglish() ? $lead['ceo_designation_en'] : $lead['ceo_designation_np'];
$pageTitle = isEnglish()
    ? ($lead['ceo_designation_en'] . '\'s Message')
    : ($lead['ceo_designation_np'] . 'को सन्देश');
$pageDescription = isEnglish()
    ? ('Message from the ' . $lead['ceo_designation_en'] . ' of our cooperative.')
    : ('हाम्रो सहकारीका ' . $lead['ceo_designation_np'] . 'को सन्देश।');
$extraHead = (isset($extraHead) ? (string) $extraHead : '')
    . (function_exists('coopThemeLinkHtml')
        ? coopThemeLinkHtml('assets/css/leadership-message-page.css')
        : '');
require_once __DIR__ . '/includes/header.php';
$L = function_exists('getLangStrings') ? getLangStrings() : [];
$name = (string) $lead['ceo_name'];
$photo = (string) $lead['ceo_photo'];
$message = (string) $lead['ceo_message'];
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

<section class="leadership-messages-about section-padding bg-light" id="ceo-message">
    <div class="container">
        <?php if ($message === ''): ?>
        <div class="text-center text-muted py-5">
            <p class="mb-0"><?php echo isEnglish() ? 'CEO message will appear here soon.' : 'CEO सन्देश चाँडै यहाँ देखिनेछ।'; ?></p>
        </div>
        <?php else: ?>
        <div class="leadership-message-full" data-aos="fade-up">
            <div class="row align-items-center flex-md-row-reverse">
                <div class="col-lg-3 col-md-4 text-center mb-4 mb-md-0">
                    <div class="leader-photo-large">
                        <?php if ($photo !== ''): ?>
                        <img src="<?php echo e(safe_versioned_media_src($photo)); ?>" alt="<?php echo e($name); ?>" loading="lazy" decoding="async">
                        <?php else: ?>
                        <div class="photo-placeholder-large"><i class="lucide-icon" data-lucide="briefcase" aria-hidden="true"></i></div>
                        <?php endif; ?>
                    </div>
                    <h4 class="mt-3"><?php echo e($name); ?></h4>
                    <span class="leader-position"><?php echo htmlspecialchars((string) $ceoDesignation, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <div class="col-lg-9 col-md-8">
                    <div class="message-content-full">
                        <i class="lucide-icon quote-icon-large" data-lucide="quote" aria-hidden="true"></i>
                        <div class="message-text-full coop-prose">
                            <?php echo coop_render_cms_prose($message); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="text-center mt-4">
            <a href="<?php echo htmlspecialchars(SITE_URL, ENT_QUOTES, 'UTF-8'); ?>about.php" class="btn btn-outline-primary">
                <i class="lucide-icon me-1" data-lucide="arrow-left" aria-hidden="true"></i><?php echo isEnglish() ? 'Back to About' : 'हाम्रो बारेमा फर्कनुहोस्'; ?>
            </a>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
