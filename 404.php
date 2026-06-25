<?php
/**
 * Custom 404 Not Found Page
 * File: 404.php
 *
 * Issue #15: Custom 404 page
 * यो page automatically देखिन्छ जब कुनै URL फेला पर्दैन।
 * (Web server .htaccess मा: ErrorDocument 404 /404.php)
 */

require_once __DIR__ . '/_bootstrap.php'; // bootstrap → config auto-loaded

// HTTP 404 status code set गर्नुहोस्
http_response_code(404);

$pageTitle = isEnglish() ? 'Page Not Found — 404' : 'पृष्ठ फेला परेन — 404';
$robotsMeta = 'noindex, follow';
require_once 'includes/header.php';
?>

<section class="py-5 error-404-section">
    <div class="container text-center py-5">
        <!-- Big 404 number -->
        <div class="error-404-number">
            404
        </div>

        <!-- Icon -->
        <div class="mb-3">
            <i class="fas fa-magnifying-glass" style="font-size:3rem;color:var(--primary-color);opacity:0.6;"></i>
        </div>

        <!-- Heading -->
        <h2 class="fw-bold mb-3">
            <?php echo isEnglish() ? 'Page Not Found' : 'पृष्ठ फेला परेन'; ?>
        </h2>

        <!-- Message -->
        <p class="text-muted mb-4 fs-5" style="max-width:500px;margin:0 auto;">
            <?php echo isEnglish()
                ? 'The page you are looking for doesn\'t exist or has been moved.'
                : 'तपाईंले खोज्नुभएको पृष्ठ अवस्थित छैन वा सारिएको छ।'; ?>
        </p>

        <!-- Action Buttons -->
        <div class="d-flex flex-wrap justify-content-center gap-3">
            <a href="<?php echo SITE_URL; ?>" class="btn btn-primary btn-lg px-4">
                <i class="fas fa-home me-2"></i>
                <?php echo isEnglish() ? 'Go to Home' : 'गृहपृष्ठमा जानुहोस्'; ?>
            </a>
            <button onclick="history.back()" class="btn btn-outline-secondary btn-lg px-4">
                <i class="fas fa-arrow-left me-2"></i>
                <?php echo isEnglish() ? 'Go Back' : 'फर्कनुहोस्'; ?>
            </button>
            <a href="<?php echo SITE_URL; ?>contact.php" class="btn btn-outline-primary btn-lg px-4">
                <i class="fas fa-phone me-2"></i>
                <?php echo isEnglish() ? 'Contact Us' : 'सम्पर्क गर्नुहोस्'; ?>
            </a>
        </div>

        <!-- Popular Links -->
        <div class="mt-5">
            <p class="text-muted small mb-3">
                <strong><?php echo isEnglish() ? 'Popular pages:' : 'लोकप्रिय पृष्ठहरू:'; ?></strong>
            </p>
            <div class="d-flex flex-wrap justify-content-center gap-2">
                <?php
                // Popular links — हरेक link को लागि badge button
                $popularLinks = [
                    ['url'=>'services.php',      'icon'=>'fa-hand-holding-heart', 'np'=>'सेवाहरू',        'en'=>'Services'],
                    ['url'=>'notices.php',        'icon'=>'fa-bullhorn',           'np'=>'सूचनाहरू',       'en'=>'Notices'],
                    ['url'=>'interest-rates.php', 'icon'=>'fa-percent',            'np'=>'ब्याज दर',       'en'=>'Interest Rates'],
                    ['url'=>'loan-apply.php',     'icon'=>'fa-hand-holding-dollar','np'=>'ऋण आवेदन',      'en'=>'Loan Apply'],
                    ['url'=>'contact.php',        'icon'=>'fa-phone',              'np'=>'सम्पर्क',        'en'=>'Contact'],
                    ['url'=>'downloads.php',      'icon'=>'fa-download',           'np'=>'डाउनलोड',        'en'=>'Downloads'],
                ];
                foreach ($popularLinks as $link):
                ?>
                <a href="<?php echo SITE_URL . $link['url']; ?>" class="btn btn-sm btn-light border">
                    <i class="fas <?php echo $link['icon']; ?> me-1"></i>
                    <?php echo isEnglish() ? $link['en'] : $link['np']; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

<?php require_once 'includes/footer.php'; ?>
