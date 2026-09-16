<?php
/**
 * Public: अध्यक्षको सन्देश — dedicated page (About dropdown).
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/leadership-message-helpers.php';

$lead = coop_load_leadership_messages(function_exists('getDB') ? getDB() : null);
$pageTitle = isEnglish() ? 'Chairman\'s Message' : 'अध्यक्षको सन्देश';
$pageDescription = isEnglish()
    ? 'Message from the Chairman of our cooperative.'
    : 'हाम्रो सहकारीका अध्यक्षको सन्देश।';
require_once __DIR__ . '/includes/header.php';
$L = function_exists('getLangStrings') ? getLangStrings() : [];
$name = (string) $lead['chairman_name'];
$photo = (string) $lead['chairman_photo'];
$message = (string) $lead['chairman_message'];
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

<section class="leadership-messages-about section-padding bg-light" id="chairman">
    <div class="container">
        <?php if ($message === ''): ?>
        <div class="text-center text-muted py-5">
            <p class="mb-0"><?php echo isEnglish() ? 'Chairman message will appear here soon.' : 'अध्यक्षको सन्देश चाँडै यहाँ देखिनेछ।'; ?></p>
        </div>
        <?php else: ?>
        <div class="leadership-message-full" id="chairman-message" data-aos="fade-up">
            <div class="row align-items-center">
                <div class="col-lg-3 col-md-4 text-center mb-4 mb-md-0">
                    <div class="leader-photo-large">
                        <?php if ($photo !== ''): ?>
                        <img src="<?php echo e(safe_versioned_media_src($photo)); ?>" alt="<?php echo e($name); ?>" loading="lazy" decoding="async">
                        <?php else: ?>
                        <div class="photo-placeholder-large"><i class="lucide-icon" data-lucide="user-round" aria-hidden="true"></i></div>
                        <?php endif; ?>
                    </div>
                    <h4 class="mt-3"><?php echo e($name); ?></h4>
                    <span class="leader-position"><?php echo isEnglish() ? 'Chairman' : 'अध्यक्ष'; ?></span>
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
