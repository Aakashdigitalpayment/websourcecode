<?php
/**
 * Dynamic Page Viewer
 * Displays pages created from admin panel
 */
require_once 'includes/config.php';

$slug = clean_text($_GET['slug'] ?? '', 200);
$pageId = intval($_GET['id'] ?? 0);

$page = null;
$pageTitle = isEnglish() ? 'Page' : 'पृष्ठ';
$pageDescription = '';
$pageOgImage = '';
$robotsMeta = 'index, follow';

try {
    $db = getDB();

    /* v10.3 (Issue #10): Auto-seed default policy pages if missing — admin
     * can later edit/disable from admin/pages.php. यो सिर्फ row नभए मात्र
     * चल्छ, exists भए overwrite हुँदैन। */
    $defaultPolicies = [
        'privacy-policy' => [
            'np' => 'गोपनीयता नीति',
            'en' => 'Privacy Policy',
            'body_np' => '<h3>हाम्रो गोपनीयता प्रति प्रतिबद्धता</h3><p>आकाश सहकारीले तपाईंको व्यक्तिगत जानकारीको गोपनीयतालाई महत्त्व दिन्छ। यो नीतिले हामी कुन प्रकारको जानकारी सङ्कलन गर्छौं, कसरी प्रयोग गर्छौं र सुरक्षित राख्छौं भन्ने बारेमा बताउँछ।</p><h4>१. सङ्कलित जानकारी</h4><ul><li>नाम, ठेगाना, सम्पर्क विवरण</li><li>सदस्यता र खाता विवरण</li><li>KYC कागजातहरू</li></ul><h4>२. प्रयोग</h4><p>तपाईंको जानकारी सेवा प्रदान गर्न, नियामक आवश्यकता पूरा गर्न र सञ्चारको लागि मात्र प्रयोग गरिन्छ।</p><h4>३. सुरक्षा</h4><p>हामी encryption, RBAC र सुरक्षित servers मार्फत तपाईंको data सुरक्षित राख्छौं।</p><h4>४. सम्पर्क</h4><p>थप जानकारीका लागि <a href="contact.php">सम्पर्क पृष्ठ</a> मा सम्पर्क गर्नुहोस्।</p>',
            'body_en' => '<h3>Our Commitment to Privacy</h3><p>Aakash Sahakari values your privacy. This policy explains what data we collect, how we use it, and how we keep it safe.</p><h4>1. Information We Collect</h4><ul><li>Name, address, contact details</li><li>Membership and account details</li><li>KYC documents</li></ul><h4>2. Use</h4><p>Your information is used solely to provide services, meet regulatory requirements, and communicate with you.</p><h4>3. Security</h4><p>We protect your data using encryption, RBAC and secure servers.</p><h4>4. Contact</h4><p>For more information please <a href="contact.php">contact us</a>.</p>',
        ],
        'terms-of-service' => [
            'np' => 'सेवाका सर्तहरू',
            'en' => 'Terms of Service',
            'body_np' => '<h3>सेवाका सर्तहरू</h3><p>यस वेबसाइट प्रयोग गरेर तपाईं निम्न सर्तहरूमा सहमत हुनुहुन्छ।</p><h4>१. सदस्यता</h4><p>सदस्यता आकाश सहकारीको नियमावली अनुसार सञ्चालन हुन्छ।</p><h4>२. जिम्मेवारी</h4><p>तपाईंले प्रदान गर्ने जानकारी सत्य हुनुपर्छ।</p>',
            'body_en' => '<h3>Terms of Service</h3><p>By using this website you agree to the following terms.</p><h4>1. Membership</h4><p>Membership is governed by the bylaws of Aakash Sahakari.</p><h4>2. Responsibility</h4><p>Information you provide must be truthful.</p>',
        ],
        'cookie-policy' => [
            'np' => 'कुकी नीति',
            'en' => 'Cookie Policy',
            'body_np' => '<h3>कुकी नीति</h3><p>हामी session सञ्चालन र अनुभव सुधार गर्न cookies प्रयोग गर्छौं।</p>',
            'body_en' => '<h3>Cookie Policy</h3><p>We use cookies to manage your session and improve your experience.</p>',
        ],
    ];
    if ($slug && isset($defaultPolicies[$slug])) {
        try {
            $exists = $db->prepare('SELECT id FROM pages WHERE slug = ?');
            $exists->execute([$slug]);
            if (!$exists->fetch()) {
                $d = $defaultPolicies[$slug];
                $ins = $db->prepare("INSERT INTO pages (slug, title, title_np, title_en, content, content_np, show_in_menu, menu_position, menu_order, is_active)
                                     VALUES (?, ?, ?, ?, ?, ?, 0, 'footer', 99, 1)");
                $ins->execute([$slug, $d['np'], $d['np'], $d['en'], $d['body_en'], $d['body_np']]);
            }
        } catch (\Throwable $e) { /* table missing on fresh install — ignore */
        }
    }

    if ($slug) {
        $stmt = $db->prepare('SELECT * FROM pages WHERE slug = ? AND is_active = 1');
        $stmt->execute([$slug]);
    } elseif ($pageId) {
        $stmt = $db->prepare('SELECT * FROM pages WHERE id = ? AND is_active = 1');
        $stmt->execute([$pageId]);
    } else {
        throw new Exception('Page not found');
    }

    $page = $stmt->fetch();

    if (!$page) {
        throw new Exception('Page not found');
    }

    $pageTitle = isEnglish() ? ($page['title_en'] ?: $page['title']) : ($page['title'] ?: $page['title_en']);
    $bodyForDesc = isEnglish() ? ($page['content'] ?: $page['content_np']) : ($page['content_np'] ?: $page['content']);
    $pageDescription = function_exists('seo_meta_description_from_html')
        ? seo_meta_description_from_html($bodyForDesc)
        : '';
    if ($pageDescription === '') {
        $pageDescription = $pageTitle;
    }
    $__fi = safe_public_upload_path($page['featured_image'] ?? '');
    if ($__fi !== '') {
        $pageOgImage = $__fi;
    }
} catch (Exception $e) {
    $page = null;
    $pageTitle = isEnglish() ? 'Page Not Found' : 'पृष्ठ फेला परेन';
    $robotsMeta = 'noindex, follow';
}

require_once 'includes/header.php';
?>

<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><?php echo $page ? htmlspecialchars($pageTitle) : (isEnglish() ? 'Page Not Found' : 'पृष्ठ फेला परेन'); ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item active"><?php echo $page ? htmlspecialchars($pageTitle) : '404'; ?></li>
            </ol>
        </nav>
    </div>
</section>

<!-- Page Content -->
<section class="page-content section-padding">
    <div class="container">
        <?php if ($page): ?>
        <div class="row">
            <div class="col-lg-10 mx-auto">
                <div class="page-content-box">
                    <?php
                    $__fi = safe_public_upload_path($page['featured_image'] ?? '');
                    if ($__fi !== ''):
                    ?>
                    <div class="page-featured-image mb-4">
                        <img src="<?php echo htmlspecialchars(SITE_URL . $__fi, ENT_QUOTES, 'UTF-8'); ?>" loading="lazy" alt="<?php echo htmlspecialchars($pageTitle); ?>" class="img-fluid rounded-3">
                    </div>
                    <?php endif; ?>

                    <div class="page-body coop-prose">
                        <?php
                        // Display content based on language
                        // Database: content = English, content_np = Nepali
                        $content = isEnglish() ? ($page['content'] ?: $page['content_np']) : ($page['content_np'] ?: $page['content']);
                        echo $content;
                        ?>
                    </div>

                    <?php if (!empty($page['meta_keywords'])): ?>
                    <div class="page-tags mt-4">
                        <strong><i class="fas fa-tags me-2"></i><?php echo isEnglish() ? 'Tags:' : 'ट्यागहरू:'; ?></strong>
                        <?php
                        $tags = explode(',', $page['meta_keywords']);
                        foreach ($tags as $tag):
                            $tag = trim($tag);
                            if ($tag):
                        ?>
                        <span class="badge bg-light text-dark me-1"><?php echo htmlspecialchars($tag); ?></span>
                        <?php endif; endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php else: ?>
        <!-- 404 Error -->
        <div class="row">
            <div class="col-lg-8 mx-auto text-center">
                <div class="error-box py-5">
                    <div class="error-icon mb-4">
                        <i class="fas fa-exclamation-triangle fa-5x text-warning"></i>
                    </div>
                    <h2><?php echo isEnglish() ? 'Page Not Found' : 'पृष्ठ फेला परेन'; ?></h2>
                    <p class="text-muted mb-4"><?php echo isEnglish() ? 'The page you are looking for might have been removed, had its name changed, or is temporarily unavailable.' : 'तपाईंले खोज्नुभएको पृष्ठ हटाइएको हुन सक्छ, यसको नाम परिवर्तन गरिएको हुन सक्छ, वा अस्थायी रूपमा उपलब्ध छैन।'; ?></p>
                    <a href="<?php echo SITE_URL; ?>" class="btn btn-primary">
                        <i class="fas fa-home me-2"></i><?php echo isEnglish() ? 'Back to Home' : 'गृहपृष्ठमा फर्कनुहोस्'; ?>
                    </a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>


<?php require_once 'includes/footer.php'; ?>
