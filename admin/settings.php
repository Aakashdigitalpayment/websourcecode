<?php
$__t = static function (string $np, string $en): string {
    $lang = (string)($_SESSION['admin_lang'] ?? $_SESSION['lang'] ?? 'np');
    return strtolower($lang) === 'en' ? $en : $np;
};
$pageTitle = $__t('साइट सेटिङ्स', 'Site Settings');
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$updateSuccess = false;
$updateError = '';
$canEditFooterDev = !empty($_SESSION['is_superadmin']);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $db = getDB();

/* CSRF सुरक्षा: POST अनुरोध प्रमाणित गर्नुहोस् */
checkCSRF();

        // Update text settings
        /* site_version थपियो — admin ले version number अपडेट गर्न सक्छ */
        $textSettings = ['site_name', 'site_name_en', 'site_slogan', 'site_slogan_en', 'meta_description', 'meta_description_en', 'meta_keywords', 'seo_title', 'seo_title_en', 'google_site_verification', 'phone', 'mobile', 'email', 'address', 'facebook_url', 'youtube_url', 'twitter_url', 'instagram_url', 'whatsapp_number', 'about_short', 'hero_title', 'hero_subtitle', 'footer_text', 'internet_banking_url', 'play_store_url', 'app_store_url', 'developer_name', 'developer_url', 'supported_name', 'supported_url', 'google_map_url', 'working_hours', 'saturday_hours', 'office_time_start', 'office_time_end', 'primary_color', 'secondary_color', 'header_color', 'footer_color', 'topbar_color', 'site_version', 'site_launch_date', 'google_client_id', 'google_client_secret', 'facebook_app_id', 'facebook_app_secret', 'twofa_admin_required', 'twofa_member_required', 'pwa_app_name', 'pwa_short_name'];

        /* Color inputs सुरक्षित/valid hex मा मात्र save गर्ने:
           invalid value ले UI text invisible/unstyled बनाउने risk कम हुन्छ। */
        $sanitizeHexColor = function ($value, $fallback = '#1a5f2a') {
            $value = trim((string)$value);
            if (!preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $value)) return $fallback;
            // 3-digit hex लाई 6-digit मा normalize
            if (strlen($value) === 4) {
                $value = '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3];
            }
            return strtolower($value);
        };

        foreach ($textSettings as $key) {
            if (isset($_POST[$key])) {
                if (in_array($key, ['twofa_admin_required','twofa_member_required'], true) && empty($_SESSION['is_superadmin'])) {
                    continue;
                }
                if (in_array($key, ['footer_text', 'developer_name', 'developer_url', 'supported_name', 'supported_url'], true) && !$canEditFooterDev) {
                    continue;
                }
                $value = $_POST[$key];
                if (in_array($key, ['meta_description', 'meta_description_en'], true)) {
                    $value = function_exists('clean_text') ? clean_text((string) $value, 400) : trim((string) $value);
                } elseif ($key === 'meta_keywords') {
                    $value = function_exists('clean_text') ? clean_text((string) $value, 500) : trim((string) $value);
                } elseif (in_array($key, ['seo_title', 'seo_title_en'], true)) {
                    $value = function_exists('clean_text') ? clean_text((string) $value, 70) : trim((string) $value);
                } elseif ($key === 'google_site_verification') {
                    /* Meta tag content only — strip HTML / full meta tag if pasted */
                    $value = trim((string) $value);
                    if (preg_match('/content=["\']([^"\']+)["\']/i', $value, $m)) {
                        $value = $m[1];
                    }
                    $value = preg_replace('/[^A-Za-z0-9_\-]/', '', $value) ?? '';
                    $value = substr($value, 0, 100);
                } elseif ($key === 'site_slogan_en') {
                    $value = function_exists('clean_text') ? clean_text((string) $value, 300) : trim((string) $value);
                }
                if ($key === 'primary_color') {
                    $value = $sanitizeHexColor($value, '#1a5f2a');
                } elseif ($key === 'secondary_color') {
                    $value = $sanitizeHexColor($value, '#c0392b');
                } elseif ($key === 'header_color') {
                    $value = $sanitizeHexColor($value, '#c0392b');
                } elseif ($key === 'footer_color') {
                    $value = $sanitizeHexColor($value, '#1a5f2a');
                } elseif ($key === 'topbar_color') {
                    $value = $sanitizeHexColor($value, '#c0392b');
                } elseif ($key === 'google_map_url') {
                    require_once dirname(__DIR__) . '/includes/google-map-embed.php';
                    $value = trim((string)$value);
                    $normalized = normalizeGoogleMapEmbedUrl($value);
                    /* Prefer embed URL when convertible; keep original share link if resolve fails */
                    if ($normalized !== '') {
                        $value = $normalized;
                    }
                }
                updateSetting($key, $value);
            }
        }
        // checkbox fallback (unchecked हुँदा key नआउने)
        if (!empty($_SESSION['is_superadmin'])) {
            if (!isset($_POST['twofa_admin_required'])) updateSetting('twofa_admin_required', '0');
            if (!isset($_POST['twofa_member_required'])) updateSetting('twofa_member_required', '0');
        }

        $uploadErrors = [];

        // Site favicon (browser tab icon)
        if (isset($_FILES['site_favicon']) && $_FILES['site_favicon']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['site_favicon'], 'logo');
            if ($upload['success']) {
                $result = updateSetting('site_favicon', $upload['path']);
                if (!$result) {
                    $uploadErrors[] = $__t('Favicon save गर्न सकिएन', 'Could not save favicon');
                }
            } else {
                $uploadErrors[] = $__t('Favicon upload', 'Favicon upload') . ': ' . ($upload['message'] ?? $__t('अज्ञात त्रुटि', 'Unknown error'));
            }
        }
        if (!empty($_POST['clear_site_favicon'])) {
            updateSetting('site_favicon', '');
        }

        // Handle default logo upload
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['logo'], 'logo');
            if ($upload['success']) {
                $result = updateSetting('logo', $upload['path']);
                if (!$result) {
                    $uploadErrors[] = $__t('Logo save गर्न सकिएन', 'Could not save logo');
                }
            } else {
                $uploadErrors[] = $__t('Logo upload', 'Logo upload') . ': ' . ($upload['message'] ?? $__t('अज्ञात त्रुटि', 'Unknown error'));
            }
        }

        // Nepali logo upload (lang-specific)
        if (isset($_FILES['logo_np']) && $_FILES['logo_np']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['logo_np'], 'logo');
            if ($upload['success']) {
                $result = updateSetting('logo_np', $upload['path']);
                if (!$result) {
                    $uploadErrors[] = $__t('Nepali logo save गर्न सकिएन', 'Could not save Nepali logo');
                }
            } else {
                $uploadErrors[] = $__t('Nepali logo upload', 'Nepali logo upload') . ': ' . ($upload['message'] ?? $__t('अज्ञात त्रुटि', 'Unknown error'));
            }
        }

        // English logo upload (lang-specific)
        if (isset($_FILES['logo_en']) && $_FILES['logo_en']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['logo_en'], 'logo');
            if ($upload['success']) {
                $result = updateSetting('logo_en', $upload['path']);
                if (!$result) {
                    $uploadErrors[] = $__t('English logo save गर्न सकिएन', 'Could not save English logo');
                }
            } else {
                $uploadErrors[] = $__t('English logo upload', 'English logo upload') . ': ' . ($upload['message'] ?? $__t('अज्ञात त्रुटि', 'Unknown error'));
            }
        }

        // Handle chairman photo upload
        if (isset($_FILES['chairman_photo']) && $_FILES['chairman_photo']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['chairman_photo'], 'leadership');
            if ($upload['success']) {
                $result = updateSetting('chairman_photo', $upload['path']);
                if (!$result) {
                    $uploadErrors[] = $__t('Chairman photo save गर्न सकिएन', 'Could not save chairman photo');
                }
            } else {
                $uploadErrors[] = $__t('Chairman photo upload', 'Chairman photo upload') . ': ' . ($upload['message'] ?? $__t('अज्ञात त्रुटि', 'Unknown error'));
            }
        }

        // Handle CEO photo upload
        if (isset($_FILES['ceo_photo']) && $_FILES['ceo_photo']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['ceo_photo'], 'leadership');
            if ($upload['success']) {
                $result = updateSetting('ceo_photo', $upload['path']);
                if (!$result) {
                    $uploadErrors[] = $__t('CEO photo save गर्न सकिएन', 'Could not save CEO photo');
                }
            } else {
                $uploadErrors[] = $__t('CEO photo upload', 'CEO photo upload') . ': ' . ($upload['message'] ?? $__t('अज्ञात त्रुटि', 'Unknown error'));
            }
        }

        // Handle mobile app photo upload
        if (isset($_FILES['mobile_app_photo']) && $_FILES['mobile_app_photo']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['mobile_app_photo'], 'app');
            if ($upload['success']) {
                $result = updateSetting('mobile_app_photo', $upload['path']);
                if (!$result) {
                    $uploadErrors[] = $__t('Mobile app photo save गर्न सकिएन', 'Could not save mobile app photo');
                }
            } else {
                $uploadErrors[] = $__t('Mobile app photo upload', 'Mobile app photo upload') . ': ' . ($upload['message'] ?? $__t('अज्ञात त्रुटि', 'Unknown error'));
            }
        }

        // Handle about page image upload
        if (isset($_FILES['about_page_image']) && $_FILES['about_page_image']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['about_page_image'], 'pages');
            if ($upload['success']) {
                $result = updateSetting('about_page_image', $upload['path']);
                if (!$result) {
                    $uploadErrors[] = $__t('About page image save गर्न सकिएन', 'Could not save About page image');
                }
            } else {
                $uploadErrors[] = $__t('About page image upload', 'About page image upload') . ': ' . ($upload['message'] ?? $__t('अज्ञात त्रुटि', 'Unknown error'));
            }
        }

        // Handle about intro right image upload
        if (isset($_FILES['about_intro_image']) && $_FILES['about_intro_image']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['about_intro_image'], 'pages');
            if ($upload['success']) {
                $result = updateSetting('about_intro_image', $upload['path']);
                if (!$result) {
                    $uploadErrors[] = $__t('About intro image save गर्न सकिएन', 'Could not save About intro image');
                }
            } else {
                $uploadErrors[] = $__t('About intro image upload', 'About intro image upload') . ': ' . ($upload['message'] ?? $__t('अज्ञात त्रुटि', 'Unknown error'));
            }
        }

        // Handle history photo upload (about history section)
        if (isset($_FILES['history_photo']) && $_FILES['history_photo']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['history_photo'], 'pages');
            if ($upload['success']) {
                $result = updateSetting('history_photo', $upload['path']);
                if (!$result) {
                    $uploadErrors[] = $__t('History photo save गर्न सकिएन', 'Could not save history photo');
                }
            } else {
                $uploadErrors[] = $__t('History photo upload', 'History photo upload') . ': ' . ($upload['message'] ?? $__t('अज्ञात त्रुटि', 'Unknown error'));
            }
        }

        // Handle himal background photo upload
        if (isset($_FILES['himal_bg']) && $_FILES['himal_bg']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['himal_bg'], 'header');
            if ($upload['success']) {
                $result = updateSetting('himal_bg', $upload['path']);
                if (!$result) {
                    $uploadErrors[] = $__t('Himal photo save गर्न सकिएन', 'Could not save himal photo');
                }
            } else {
                $uploadErrors[] = $__t('Himal photo upload', 'Himal photo upload') . ': ' . ($upload['message'] ?? $__t('अज्ञात त्रुटि', 'Unknown error'));
            }
        }

        // SEO — default Open Graph / Facebook share image (प्रति सहकारी फरक)
        if (isset($_FILES['seo_og_image']) && $_FILES['seo_og_image']['error'] === UPLOAD_ERR_OK) {
            $upload = uploadFile($_FILES['seo_og_image'], 'seo');
            if ($upload['success']) {
                $result = updateSetting('seo_og_image', $upload['path']);
                if (!$result) {
                    $uploadErrors[] = $__t('SEO share image save गर्न सकिएन', 'Could not save SEO share image');
                }
            } else {
                $uploadErrors[] = $__t('SEO share image upload', 'SEO share image upload') . ': ' . ($upload['message'] ?? $__t('अज्ञात त्रुटि', 'Unknown error'));
            }
        }
        if (!empty($_POST['clear_seo_og_image'])) {
            updateSetting('seo_og_image', '');
        }
        // Himal photo: clear and opacity
        if (!empty($_POST['clear_himal_bg'])) {
            updateSetting('himal_bg', '');
        }
        if (isset($_POST['himal_bg_opacity'])) {
            updateSetting('himal_bg_opacity', (string)max(0, min(100, (int)$_POST['himal_bg_opacity'])));
        }

        // Log any upload errors
        if (!empty($uploadErrors)) {
            error_log('Settings upload errors: ' . implode(', ', $uploadErrors));
        }

        $updateSuccess = true;
        writeAuditLog('settings_update', 'Site settings updated', 'settings');
        setFlash('success', $__t('सेटिङ्स सफलतापूर्वक अपडेट भयो।', 'Settings updated successfully.'));

        // Use JavaScript redirect to ensure session is saved
        echo '<script>window.location.href = "settings.php";</script>';
        exit();

    } catch (Exception $e) {
        $updateError = $e->getMessage();
        setFlash('error', $__t('त्रुटि भयो। कृपया पछि प्रयास गर्नुहोस्।', 'An error occurred. Please try again later.'));
    }
}

// Get current settings
try {
    $db = getDB();
    $settingsStmt = $db->query("SELECT setting_key, setting_value FROM site_settings");
    $settings = [];
    while ($row = $settingsStmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    $settings = [];
}
?>

<?php
echo adminPageHeader($__t('साइट सेटिङ्स', 'Site Settings'), 'fa-cogs', $__t('आकाश सहकारी वेबसाइटको सामान्य सेटिङ्स', 'General settings for cooperative website'));
?>
<?php echo adminHelpTip($__t('यो पृष्ठबाट Website को नाम, Logo, रंग, र सम्पर्क विवरण परिवर्तन गर्न सकिन्छ।', 'You can change website name, logo, colors and contact details from this page.'), [$__t('Logo बदल्न: "Site Logo" section मा जानुहोस्।', 'To change logo: go to "Site Logo" section.'), $__t('रंग बदल्न: "Primary Color" section मा color picker प्रयोग गर्नुहोस्।', 'To change colors: use color picker in "Primary Color" section.'), $__t('परिवर्तन गरेपछि: "Save" बटन थिच्नुहोस्।', 'After changes: click "Save" button.')]); ?>
<?php $_flash = getFlash(); if ($_flash) echo adminAlert($_flash['type'], $_flash['message']); ?>
<?php
$panel = (string)($_GET['panel'] ?? 'general');
if (!in_array($panel, ['general', 'branding'], true)) {
    $panel = 'general';
}
?>
<form method="POST" action="settings.php" enctype="multipart/form-data" class="settings-page-compact">
    <?php echo csrfField(); ?>
    <ul class="nav admin-nav-tabs mb-4" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $panel === 'general' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#settings-general-tab" type="button" role="tab">
                <i class="lucide-icon me-1" aria-hidden="true" data-lucide="sliders"></i> <?php echo $__t('सामान्य सेटिङ्स', 'General Settings'); ?>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $panel === 'branding' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#settings-branding-tab" type="button" role="tab">
                <i class="fas fa-image me-1"></i> <?php echo $__t('ब्रान्डिङ / मिडिया', 'Branding / Media'); ?>
            </button>
        </li>
    </ul>
    <div class="tab-content">
        <div class="tab-pane fade <?php echo $panel === 'general' ? 'show active' : ''; ?>" id="settings-general-tab" role="tabpanel">
        <div class="alert alert-light border settings-tab-note mb-3">
            <i class="lucide-icon me-2 stg-ico-primary" aria-hidden="true" data-lucide="info"></i>
            <?php echo $__t('वेबसाइटको नाम, SEO, सम्पर्क, social links, banking links, नेतृत्व र footer सम्बन्धी मुख्य सेटिङ्स यही tab मा छन्।', 'Main settings for website name, SEO, contacts, social links, banking links, leadership and footer are in this tab.'); ?>
        </div>
        <div class="stg-subtabs mb-3" data-stg-panel="general">
            <button type="button" class="stg-subtab-btn active" data-stg-group="identity"><i class="lucide-icon me-1" aria-hidden="true" data-lucide="globe"></i> <?php echo $__t('साइट / SEO', 'Site / SEO'); ?></button>
            <button type="button" class="stg-subtab-btn" data-stg-group="contact"><i class="fas fa-address-book me-1"></i> <?php echo $__t('सम्पर्क / Maps', 'Contact / Maps'); ?></button>
            <button type="button" class="stg-subtab-btn" data-stg-group="banking"><i class="lucide-icon me-1" aria-hidden="true" data-lucide="lock"></i> <?php echo $__t('Banking / Security', 'Banking / Security'); ?></button>
            <button type="button" class="stg-subtab-btn" data-stg-group="leadership"><i class="lucide-icon me-1" aria-hidden="true" data-lucide="users"></i> <?php echo $__t('नेतृत्व / Footer', 'Leadership / Footer'); ?></button>
            <button type="button" class="stg-subtab-btn" data-stg-group="all"><i class="fas fa-table-cells-large me-1"></i> <?php echo $__t('सबै देखाउनुहोस्', 'Show All'); ?></button>
        </div>
        <div class="row">
        <div class="col-lg-12">
            <div class="card mb-4 stg-section-card stg-filter-card" data-stg-panel="general" data-stg-group="identity" data-stg-order="1">
                <div class="card-header stg-section-header">
                    <h5 class="stg-section-title"><i class="lucide-icon" aria-hidden="true" data-lucide="globe"></i> <?php echo $__t('साइट जानकारी', 'Site Information'); ?></h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label"><?php echo $__t('साइट नाम (नेपाली)', 'Site Name (Nepali)'); ?></label>
                                <input type="text" name="site_name" class="form-control"
                                       value="<?php echo $settings['site_name'] ?? ''; ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Site Name (English)</label>
                                <input type="text" name="site_name_en" class="form-control"
                                       value="<?php echo $settings['site_name_en'] ?? ''; ?>">
                            </div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label"><?php echo $__t('साइट स्लोगन', 'Site Slogan'); ?></label>
                        <input type="text" name="site_slogan" class="form-control"
                               value="<?php echo htmlspecialchars($settings['site_slogan'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Site slogan (English)</label>
                        <input type="text" name="site_slogan_en" class="form-control"
                               value="<?php echo htmlspecialchars($settings['site_slogan_en'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                               placeholder="Short tagline for English UI / SEO fallback">
                    </div>

                    <hr>
                    <h6 class="stg-title-accent fw-bold mb-3"><i class="fas fa-mobile-screen-button me-2"></i><?php echo $__t('PWA / मोबाइल एप नाम', 'PWA / Mobile App Name'); ?></h6>
                    <p class="stg-muted small mb-3"><?php echo $__t('मोबाइलमा Install गर्दा देखिने App नाम। खाली छोड्नुभयो भने माथिको साइट नाम प्रयोग हुन्छ।', 'App name shown when installing on mobile. If left blank, the site name above is used.'); ?></p>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label"><?php echo $__t('PWA App नाम (पूरा)', 'PWA App Name (Full)'); ?></label>
                                <input type="text" name="pwa_app_name" class="form-control"
                                       value="<?php echo htmlspecialchars($settings['pwa_app_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                       placeholder="<?php echo htmlspecialchars($settings['site_name'] ?? 'सहकारी HRM & CMS System', ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="form-text"><?php echo $__t('Install prompt र splash screen मा देखिन्छ।', 'Shown on install prompt and splash screen.'); ?></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label"><?php echo $__t('PWA Short Name (छोटो नाम)', 'PWA Short Name'); ?></label>
                                <input type="text" name="pwa_short_name" class="form-control" maxlength="12"
                                       value="<?php echo htmlspecialchars($settings['pwa_short_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                       placeholder="<?php echo htmlspecialchars($settings['site_name_en'] ?? 'HRM System', ENT_QUOTES, 'UTF-8'); ?>">
                                <div class="form-text"><?php echo $__t('Home screen icon मुनि देखिन्छ — अधिकतम १२ अक्षर।', 'Shown under home screen icon — max 12 characters.'); ?></div>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- SEO — प्रति डोमेन/सहकारी (Google, Facebook share) -->
            <div class="card mb-4 stg-section-card stg-filter-card" data-stg-panel="general" data-stg-group="identity" data-stg-order="2">
                <div class="card-header stg-section-header">
                    <h5 class="stg-section-title"><i class="lucide-icon" aria-hidden="true" data-lucide="search"></i> <?php echo $__t('SEO (Google / सामाजिक साझेदारी)', 'SEO (Google / Social Sharing)'); ?></h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="fas fa-info-circle me-1"></i>
                        <?php echo $__t(
                            'यो थिम धेरै SACCOS मा चल्छ — तलका field हरू यस सहकारीको site settings बाट मात्र आउँछन्। Google मा शीर्षक brand-first हुनुपर्छ (जस्तै: जनउत्थान | बचत तथा ऋण सहकारी…), «गृहपृष्ठ -» बाट सुरु नगर्नुहोस्।',
                            'This theme runs on many SACCOS — fields below come only from this coop’s site settings. Google titles should be brand-first (e.g. Janautthan | Savings & Credit…), not starting with “Home -”.'
                        ); ?>
                    </div>
                    <h6 class="stg-title-accent fw-bold mb-3"><i class="fas fa-heading me-2"></i><?php echo $__t('Google खोज शीर्षक (Homepage)', 'Google Search Title (Homepage)'); ?></h6>
                    <div class="mb-3">
                        <label class="form-label"><?php echo $__t('SEO शीर्षक (नेपाली)', 'SEO Title (Nepali)'); ?> — &lt;title&gt;</label>
                        <input type="text" name="seo_title" class="form-control" maxlength="70"
                               value="<?php echo htmlspecialchars($settings['seo_title'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                               placeholder="<?php echo $__t('उदा.: जनउत्थान | बचत तथा ऋण सहकारी संस्था लि.', 'e.g. Janautthan | Saving & Credit Cooperative Ltd.'); ?>">
                        <div class="form-text"><?php echo $__t('खाली छोड्नुभयो भने: English छोटो नाम + | + नेपाली पूरा नाम। अधिकतम ~६०–७० अक्षर राम्रो।', 'If empty: short English name + | + full Nepali name. Aim for ~60–70 characters.'); ?></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">SEO title (English)</label>
                        <input type="text" name="seo_title_en" class="form-control" maxlength="70"
                               value="<?php echo htmlspecialchars($settings['seo_title_en'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                               placeholder="e.g. Janautthan SACCOS | Saving & Credit Cooperative Ltd.">
                    </div>

                    <hr>
                    <h6 class="stg-title-accent fw-bold mb-3"><i class="fas fa-bullseye me-2"></i>Search / Share Content</h6>
                    <div class="mb-3">
                        <label class="form-label"><?php echo $__t('संक्षिप्त परिचय', 'Short Introduction'); ?></label>
                        <textarea name="about_short" class="form-control" rows="3"><?php echo $settings['about_short'] ?? ''; ?></textarea>
                    </div>

                    <hr>
                    <p class="stg-muted small mb-3"><?php echo $__t('मेटा विवरण ~१५०–१६० अक्षर राख्नुहोस् — लामो इतिहास लेख नराख्नुहोस्। पहिलो वाक्यमा संस्थाको नाम + बचत/ऋण/स्थान लेख्नुहोस्।', 'Keep meta description ~150–160 characters — not a long history essay. Lead with coop name + savings/loans/location.'); ?></p>
                    <div class="mb-3">
                        <label class="form-label"><?php echo $__t('मेटा विवरण (नेपाली)', 'Meta Description (Nepali)'); ?> — &lt;meta name=&quot;description&quot;&gt;</label>
                        <textarea name="meta_description" class="form-control" rows="3" maxlength="400"
                                  placeholder="<?php echo $__t('उदा.: जनउत्थान SACCOS — तिलोत्तमा, रुपन्देही। सुरक्षित बचत, सहज ऋण र सदस्य सेवा।', 'e.g. Janautthan SACCOS — Tilottama, Rupandehi. Safe savings, easy loans and member services.'); ?>"><?php echo htmlspecialchars($settings['meta_description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Meta description (English)</label>
                        <textarea name="meta_description_en" class="form-control" rows="3" maxlength="400"
                                  placeholder="Short summary for English UI / search snippets"><?php echo htmlspecialchars($settings['meta_description_en'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo $__t('मेटा कीवर्ड (अल्पविरामले छुट्याउनुहोस्)', 'Meta Keywords (comma separated)'); ?></label>
                        <textarea name="meta_keywords" class="form-control" rows="2" maxlength="500"
                                  placeholder="<?php echo $__t('जनउत्थान, Janautthan SACCOS, बचत, ऋण, रुपन्देही, सहकारी', 'Janautthan, SACCOS, savings, loan, Rupandehi, cooperative'); ?>"><?php echo htmlspecialchars($settings['meta_keywords'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>

                    <hr>
                    <h6 class="stg-title-accent fw-bold mb-3"><i class="fab fa-google me-2"></i><?php echo $__t('Google Search Console (एकपटक)', 'Google Search Console (one-time)'); ?></h6>
                    <div class="alert alert-secondary py-2 small mb-3">
                        <strong><?php echo $__t('महत्त्वपूर्ण:', 'Important:'); ?></strong>
                        <?php echo $__t(
                            ' यो Chrome को F12 / Console होइन। Google को छुट्टै प्यानल हो। Browser मा “URL Inspection” खोज्दा केही आउँदैन।',
                            ' This is not Chrome F12 / Console. It is a separate Google panel. Searching “URL Inspection” in the browser will not find it.'
                        ); ?>
                    </div>
                    <ol class="small mb-3 ps-3">
                        <li class="mb-1"><?php echo $__t('एकपटक मात्र:', 'One time only:'); ?>
                            <a href="https://search.google.com/search-console" target="_blank" rel="noopener noreferrer">search.google.com/search-console</a>
                            <?php echo $__t(' खोल्नुहोस् → Google खाताले login → “Add property” → यस साइटको URL थप्नुहोस्:', ' → sign in with Google → Add property → add this site URL:'); ?>
                            <code><?php echo htmlspecialchars(rtrim(defined('SITE_URL') ? SITE_URL : '', '/') . '/', ENT_QUOTES, 'UTF-8'); ?></code>
                        </li>
                        <li class="mb-1"><?php echo $__t('Verification: “HTML tag” छान्नुहोस् → Google ले दिएको code को content=“...” भित्रको कोड तल टाँस्नुहोस् → Save → Google मा Verify।', 'Verification: choose “HTML tag” → paste the content=“...” value below → Save → click Verify in Google.'); ?></li>
                        <li class="mb-1"><?php echo $__t('Sitemaps मेनु → नयाँ sitemap:', 'Sitemaps menu → new sitemap:'); ?>
                            <code>sitemap.php</code>
                            <?php echo $__t(' Submit गर्नुहोस् (एकपटक)। त्यसपछि Google आफैं पृष्ठहरू खोज्दै जान्छ।', ' Submit once. After that Google crawls pages automatically.'); ?>
                        </li>
                        <li class="mb-0"><?php echo $__t('“Request indexing” optional हो — homepage छिटो चाहियो भने मात्र। हरेक पेजको लागि दिनदिनै गर्नु पर्दैन।', '“Request indexing” is optional — only if you want the homepage faster. You do not need to do it for every page daily.'); ?></li>
                    </ol>
                    <div class="mb-3">
                        <label class="form-label"><?php echo $__t('Google site verification code', 'Google site verification code'); ?></label>
                        <input type="text" name="google_site_verification" class="form-control font-monospace" maxlength="100"
                               value="<?php echo htmlspecialchars($settings['google_site_verification'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                               placeholder="ex: AbCdEfGhIjKlMnOpQrStUvWxYz1234567890">
                        <div class="form-text"><?php echo $__t('पूरा &lt;meta ...&gt; टाँसे पनि हुन्छ — हामी content मात्र राख्छौं। खाली = verification meta नदेखिने।', 'You may paste the full &lt;meta&gt; tag — we store only the content value. Empty = no verification meta.'); ?></div>
                    </div>
                    <div class="alert alert-warning py-2 small mb-0">
                        <strong><?php echo $__t('के आफैं हुन्छ?', 'What is automatic?'); ?></strong>
                        <?php echo $__t(
                            ' Website मा SEO tags + sitemap.php + robots.txt आफैं छन्। Google खाता verify + sitemap Submit भएपछि बाँकी crawl प्रायः आफैं हुन्छ। Ranking तुरुन्त top मा आउँदैन।',
                            ' SEO tags + sitemap.php + robots.txt are automatic on the site. After you verify the Google account and submit the sitemap once, crawling is mostly automatic. Ranking will not jump to #1 instantly.'
                        ); ?>
                        <br>
                        <span class="text-muted"><?php echo $__t('Sitemap URL:', 'Sitemap URL:'); ?></span>
                        <code><?php echo htmlspecialchars(rtrim(defined('SITE_URL') ? SITE_URL : '', '/') . '/sitemap.php', ENT_QUOTES, 'UTF-8'); ?></code>
                    </div>
                    <?php
                    /* Live preview — यस सहकारीको DB settings बाट (Google-facing tags) */
                    $__prevTitle = function_exists('seo_document_title')
                        ? seo_document_title(isEnglish() ? 'Home' : 'गृहपृष्ठ', false)
                        : (string)($settings['site_name'] ?? '');
                    $__prevDesc = function_exists('seo_resolve_meta_description')
                        ? seo_resolve_meta_description(null, false)
                        : (string)($settings['meta_description'] ?? '');
                    ?>
                    <div class="border rounded p-3 mt-3 bg-light">
                        <div class="small text-muted mb-1"><?php echo $__t('Google मा देखिने preview (यो सहकारीको settings अनुसार)', 'Google-style preview (from this coop’s settings)'); ?></div>
                        <div class="text-primary fw-semibold" style="font-size:1.05rem;line-height:1.35;"><?php echo htmlspecialchars($__prevTitle, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="text-success small"><?php echo htmlspecialchars(rtrim(defined('SITE_URL') ? SITE_URL : '', '/') . '/', ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="small text-secondary mt-1"><?php echo htmlspecialchars($__prevDesc, ENT_QUOTES, 'UTF-8'); ?></div>
                        <div class="form-text mt-2 mb-0"><?php echo $__t('Save पछि website मा तुरुन्त tags बदलिन्छ। Google ranking मा देखिन केही दिन–हप्ता लाग्न सक्छ (crawl/reindex)।', 'After Save, website tags change immediately. Google ranking may take days–weeks (crawl/reindex).'); ?></div>
                    </div>
                </div>
            </div>

            <!-- Contact + Social Media -->
            <div class="card mb-4 stg-section-card stg-filter-card" data-stg-panel="general" data-stg-group="contact" data-stg-order="2">
                <div class="card-header stg-section-header">
                    <h5 class="stg-section-title"><i class="fas fa-address-book"></i> <?php echo $__t('सम्पर्क / सामाजिक सञ्जाल', 'Contact / Social Media'); ?></h5>
                </div>
                <div class="card-body">
                    <h6 class="stg-title-accent fw-bold mb-3"><i class="fas fa-phone me-2"></i><?php echo $__t('सम्पर्क जानकारी', 'Contact Information'); ?></h6>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label"><?php echo $__t('फोन नम्बर', 'Phone Number'); ?></label>
                                <input type="text" name="phone" class="form-control"
                                       value="<?php echo $settings['phone'] ?? ''; ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label"><?php echo $__t('मोबाइल नम्बर', 'Mobile Number'); ?></label>
                                <input type="text" name="mobile" class="form-control"
                                       value="<?php echo $settings['mobile'] ?? ''; ?>">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo $__t('इमेल', 'Email'); ?></label>
                        <input type="email" name="email" class="form-control"
                               value="<?php echo $settings['email'] ?? ''; ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><?php echo $__t('ठेगाना', 'Address'); ?></label>
                        <input type="text" name="address" class="form-control"
                               value="<?php echo $settings['address'] ?? ''; ?>">
                    </div>

                    <hr>
                    <h6 class="stg-title-accent fw-bold mb-3"><i class="fas fa-share-alt me-2"></i><?php echo $__t('सामाजिक सञ्जाल', 'Social Media'); ?></h6>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label"><i class="fab fa-facebook stg-ico-primary"></i> Facebook URL</label>
                                <input type="url" name="facebook_url" class="form-control"
                                       value="<?php echo $settings['facebook_url'] ?? ''; ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label"><i class="fab fa-youtube stg-ico-danger"></i> YouTube URL</label>
                                <input type="url" name="youtube_url" class="form-control"
                                       value="<?php echo $settings['youtube_url'] ?? ''; ?>">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label"><i class="fab fa-twitter stg-ico-info"></i> Twitter URL</label>
                                <input type="url" name="twitter_url" class="form-control"
                                       value="<?php echo $settings['twitter_url'] ?? ''; ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label"><i class="fab fa-instagram stg-ico-danger"></i> Instagram URL</label>
                                <input type="url" name="instagram_url" class="form-control"
                                       value="<?php echo $settings['instagram_url'] ?? ''; ?>">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label"><i class="fab fa-whatsapp stg-ico-success"></i> WhatsApp Number</label>
                        <input type="text" name="whatsapp_number" class="form-control"
                               value="<?php echo $settings['whatsapp_number'] ?? ''; ?>"
                               placeholder="9779812345678">
                        <small class="stg-muted"><?php echo $__t('Country code सहित (जस्तै: 9779812345678)', 'Include country code (e.g., 9779812345678)'); ?></small>
                    </div>
                </div>
            </div>

            <!-- Digital Banking -->
            <div class="card mb-4 stg-section-card stg-filter-card" data-stg-panel="general" data-stg-group="banking" data-stg-order="1">
                <div class="card-header stg-section-header">
                    <h5 class="stg-section-title"><i class="fas fa-laptop"></i> <?php echo $__t('डिजिटल बैंकिङ', 'Digital Banking'); ?></h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Internet Banking URL</label>
                        <input type="url" name="internet_banking_url" class="form-control"
                               value="<?php echo $settings['internet_banking_url'] ?? ''; ?>"
                               placeholder="https://ibanking.yoursite.com">
                        <small class="stg-muted"><?php echo $__t('इन्टरनेट बैंकिङ लगइन URL', 'Internet banking login URL'); ?></small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Google Play Store URL</label>
                        <input type="url" name="play_store_url" class="form-control"
                               value="<?php echo $settings['play_store_url'] ?? ''; ?>"
                               placeholder="https://play.google.com/store/apps/details?id=...">
                        <small class="stg-muted"><?php echo $__t('मोबाइल एप (Android)', 'Mobile app (Android)'); ?></small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Apple App Store URL</label>
                        <input type="url" name="app_store_url" class="form-control"
                               value="<?php echo $settings['app_store_url'] ?? ''; ?>"
                               placeholder="https://apps.apple.com/app/...">
                        <small class="stg-muted"><?php echo $__t('मोबाइल एप (iOS)', 'Mobile app (iOS)'); ?></small>
                    </div>

                    <!-- OAuth Settings -->
                    <hr><h6 class="stg-title-accent fw-bold mt-3"><i class="lucide-icon me-2" aria-hidden="true" data-lucide="key"></i><?php echo $__t('Member Portal — Social Login (OAuth)', 'Member Portal — Social Login (OAuth)'); ?></h6>
                    <div class="alert alert-info py-2 px-3 stg-help-compact">
                        <i class="fas fa-info-circle me-1"></i>
                        <?php echo $__t('Google OAuth', 'Google OAuth'); ?>: <a href="https://console.cloud.google.com/apis/credentials" target="_blank">Google Cloud Console</a> <?php echo $__t('बाट Client ID र Secret लिनुहोस्।', 'to get Client ID and Secret.'); ?><br>
                        Facebook: <a href="https://developers.facebook.com/apps" target="_blank">Meta Developers</a> <?php echo $__t('बाट App ID र Secret लिनुहोस्।', 'to get App ID and Secret.'); ?><br>
                        <strong>Redirect URI:</strong> <code><?php echo SITE_URL; ?>member/oauth.php?provider=google</code>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label"><i class="fab fa-google stg-ico-danger me-1"></i>Google Client ID</label>
                            <input type="text" name="google_client_id" class="form-control font-monospace"
                                   value="<?php echo htmlspecialchars($settings['google_client_id'] ?? ''); ?>"
                                   placeholder="xxxx.apps.googleusercontent.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><i class="fab fa-google stg-ico-danger me-1"></i>Google Client Secret</label>
                            <input type="password" name="google_client_secret" class="form-control font-monospace"
                                   value="<?php echo htmlspecialchars($settings['google_client_secret'] ?? ''); ?>"
                                   placeholder="GOCSPX-...">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><i class="fab fa-facebook stg-ico-primary me-1"></i>Facebook App ID</label>
                            <input type="text" name="facebook_app_id" class="form-control font-monospace"
                                   value="<?php echo htmlspecialchars($settings['facebook_app_id'] ?? ''); ?>"
                                   placeholder="1234567890">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><i class="fab fa-facebook stg-ico-primary me-1"></i>Facebook App Secret</label>
                            <input type="password" name="facebook_app_secret" class="form-control font-monospace"
                                   value="<?php echo htmlspecialchars($settings['facebook_app_secret'] ?? ''); ?>"
                                   placeholder="abcdef1234...">
                        </div>
                    </div>
                    <?php if (!empty($_SESSION['is_superadmin'])): ?>
                    <hr>
                    <h6 class="stg-title-accent fw-bold mt-3"><i class="lucide-icon me-2" aria-hidden="true" data-lucide="shield-halved"></i><?php echo $__t('2FA नीति (Superadmin)', '2FA Policy (Superadmin)'); ?></h6>
                    <div class="alert alert-warning py-2 px-3 stg-help-compact">
                        <i class="lucide-icon me-1" aria-hidden="true" data-lucide="lock"></i> <?php echo $__t('तलको toggle अनुसार Google Authenticator 2FA login मा लागू हुन्छ।', 'Google Authenticator 2FA is enforced on login based on toggles below.'); ?>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="twofa_admin_required" name="twofa_admin_required" value="1"
                               <?php echo (($settings['twofa_admin_required'] ?? '0') === '1') ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="twofa_admin_required"><?php echo $__t('Admin Login मा 2FA अनिवार्य', 'Require 2FA for Admin Login'); ?></label>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="twofa_member_required" name="twofa_member_required" value="1"
                               <?php echo (($settings['twofa_member_required'] ?? '0') === '1') ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="twofa_member_required"><?php echo $__t('Member Login मा 2FA अनिवार्य', 'Require 2FA for Member Login'); ?></label>
                    </div>
                    <?php endif; ?>

                </div>
            </div>

            <div class="row">
            <div class="col-xl-6">
            <!-- Leadership Section removed — Chairman/CEO name, photo, designation
                 are now managed from Team Members (team-karmachari.php).
                 Keeping col-xl-6 wrapper intact so Footer card layout is unchanged. -->
            </div>

            <div class="col-xl-6">
            <!-- Footer -->
            <div class="card mb-4 stg-section-card stg-filter-card" data-stg-panel="general" data-stg-group="leadership" data-stg-order="2">
                <div class="card-header stg-section-header">
                    <h5 class="stg-section-title"><i class="lucide-icon" aria-hidden="true" data-lucide="copyright"></i> <?php echo $__t('फुटर', 'Footer'); ?></h5>
                </div>
                <div class="card-body">
                    <?php if (!$canEditFooterDev): ?>
                    <div class="alert alert-warning py-2 mb-3">
                        <i class="lucide-icon me-1" aria-hidden="true" data-lucide="lock"></i> <?php echo $__t('Copyright/Developed By सेटिङ्स Super Admin ले मात्र परिवर्तन गर्न मिल्छ।', 'Only Super Admin can modify Copyright/Developed By settings.'); ?>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label"><?php echo $__t('Copyright Text', 'Copyright Text'); ?></label>
                        <input type="text" name="footer_text" class="form-control"
                               value="<?php echo $settings['footer_text'] ?? ''; ?>"
                               <?php echo $canEditFooterDev ? '' : 'readonly'; ?>>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Developed By (Name)</label>
                                <input type="text" name="developer_name" class="form-control"
                                       value="<?php echo $settings['developer_name'] ?? 'Tanka Adhikari'; ?>"
                                       <?php echo $canEditFooterDev ? '' : 'readonly'; ?>>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Developed By URL</label>
                                <input type="url" name="developer_url" class="form-control"
                                       value="<?php echo $settings['developer_url'] ?? 'https://www.tankaadhikari.com.np/'; ?>"
                                       <?php echo $canEditFooterDev ? '' : 'readonly'; ?>>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label"><?php echo $__t('Supported By (Name)', 'Supported By (Name)'); ?></label>
                                <input type="text" name="supported_name" class="form-control"
                                       value="<?php echo $settings['supported_name'] ?? ''; ?>"
                                       <?php echo $canEditFooterDev ? '' : 'readonly'; ?>>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label"><?php echo $__t('Supported By URL', 'Supported By URL'); ?></label>
                                <input type="url" name="supported_url" class="form-control"
                                       value="<?php echo $settings['supported_url'] ?? ''; ?>"
                                       <?php echo $canEditFooterDev ? '' : 'readonly'; ?>>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            </div>
            </div>

            <!-- Office Info -->
            <div class="card mb-4 stg-section-card stg-filter-card" data-stg-panel="general" data-stg-group="contact" data-stg-order="3">
                <div class="card-header stg-section-header">
                    <h5 class="stg-section-title"><i class="fas fa-building"></i> <?php echo $__t('Office Info (Map + कार्य समय)', 'Office Info (Map + Working Hours)'); ?></h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Google Map Embed URL</label>
                        <input type="url" name="google_map_url" class="form-control"
                               value="<?php echo htmlspecialchars($settings['google_map_url'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                               placeholder="https://www.google.com/maps/embed?pb=...">
                        <small class="stg-muted d-block mt-1">
                            <?php echo $__t(
                                'Google Maps → Share → Embed a map बाट src URL copy गर्नुहोस् (…/maps/embed?pb=…)। maps.app.goo.gl share link iframe मा चल्दैन — save गर्दा स्वतः embed मा बदल्ने प्रयास हुन्छ।',
                                'Copy the Embed map src from Google Maps → Share → Embed a map (…/maps/embed?pb=…). Short maps.app.goo.gl links cannot load in an iframe — save will try to convert them automatically.'
                            ); ?>
                        </small>
                        <?php
                        $_gmapSaved = trim((string)($settings['google_map_url'] ?? ''));
                        if ($_gmapSaved !== '' && preg_match('#(?:maps\.app\.goo\.gl|goo\.gl/maps)/#i', $_gmapSaved)):
                        ?>
                        <div class="alert alert-warning small py-2 mt-2 mb-0">
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            <?php echo $__t(
                                'अहिले share link सेभ छ। Save Settings थिच्नुहोस् — सिस्टमले embed URL मा बदल्नेछ। नभए Google Maps बाट Embed URL राख्नुहोस्।',
                                'A share link is saved. Click Save Settings to convert it, or paste a real Embed URL from Google Maps.'
                            ); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <h6 class="stg-title-accent fw-bold mb-2"><i class="lucide-icon me-2" aria-hidden="true" data-lucide="eye"></i><?php echo $__t('सार्वजनिक प्रदर्शन समय (वेबसाइटमा देखिने)', 'Public Display Hours (shown on website)'); ?></h6>
                    <div class="alert alert-light border small py-2 mb-3"><i class="fas fa-info-circle me-1 text-primary"></i><?php echo $__t('यो text Footer / Contact पेजमा जस्ताको तस्तै देखिन्छ। मानिसले पढ्नका लागि — कुनै time-picker मा प्रयोग हुँदैन।', 'This text is shown as-is on the Footer / Contact page. For human reading only — not used by any time-picker.'); ?></div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label"><?php echo $__t('आइत–शुक्रबार समय', 'Sunday–Friday Hours'); ?> <small class="stg-muted">(Sunday–Friday)</small></label>
                                <input type="text" name="working_hours" class="form-control"
                                       placeholder="बिहान १०:०० - साँझ ५:००"
                                       value="<?php echo $settings['working_hours'] ?? 'बिहान १०:०० - साँझ ५:००'; ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label"><?php echo $__t('शनिबार समय', 'Saturday Hours'); ?> <small class="stg-muted">(Saturday)</small></label>
                                <input type="text" name="saturday_hours" class="form-control"
                                       placeholder="बिहान १०:०० - दिउँसो १:००"
                                       value="<?php echo $settings['saturday_hours'] ?? 'बिहान १०:०० - दिउँसो १:००'; ?>">
                            </div>
                        </div>
                    </div>
                    <hr>
                    <h6 class="stg-title-accent fw-bold mb-2"><i class="lucide-icon me-2" aria-hidden="true" data-lucide="calendar-check"></i><?php echo $__t('Appointment समयसीमा (Time-picker मा प्रयोग)', 'Appointment Time Range (for time-picker)'); ?></h6>
                    <div class="alert alert-light border small py-2 mb-3"><i class="fas fa-info-circle me-1 text-primary"></i><?php echo $__t('यी मानहरूले Appointment booking फर्मको time-picker मा कति बजेदेखि कति बजेसम्म छनोट गर्न मिल्ने हो भन्ने सीमा तोक्छ। माथिको Display Hours सँग स्वतन्त्र हुन्छ।', 'These values define the from–to range of allowed times in the Appointment booking time-picker. Independent of the Display Hours above.'); ?></div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label"><?php echo $__t('Appointment सुरु समय', 'Appointment Start Time'); ?></label>
                                <input type="time" name="office_time_start" class="form-control" step="1800"
                                       value="<?php echo htmlspecialchars($settings['office_time_start'] ?? '10:00', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label"><?php echo $__t('Appointment अन्त्य समय', 'Appointment End Time'); ?></label>
                                <input type="time" name="office_time_end" class="form-control" step="1800"
                                       value="<?php echo htmlspecialchars($settings['office_time_end'] ?? '17:00', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card stg-save-card">
                <div class="card-body py-3 admin-form-actions">
                    <button type="submit" class="btn stg-save-btn px-4">
                        <i class="lucide-icon me-1" aria-hidden="true" data-lucide="save"></i> <?php echo $__t('सेटिङ्स सेभ गर्नुहोस्', 'Save Settings'); ?>
                    </button>
                </div>
            </div>
        </div>
        </div>
        </div>

        <div class="tab-pane fade <?php echo $panel === 'branding' ? 'show active' : ''; ?>" id="settings-branding-tab" role="tabpanel">
        <div class="alert alert-light border settings-tab-note mb-3">
            <i class="lucide-icon me-2 stg-ico-primary" aria-hidden="true" data-lucide="info"></i>
            <?php echo $__t('लोगो, header image, about image, theme colors र version जस्ता branding/media सम्बन्धी सेटिङ्स यही tab मा छन्।', 'Branding/media settings like logo, header image, about image, theme colors and version are in this tab.'); ?>
        </div>
        <div class="stg-subtabs mb-3" data-stg-panel="branding">
            <button type="button" class="stg-subtab-btn active" data-stg-group="media"><i class="lucide-icon me-1" aria-hidden="true" data-lucide="images"></i> <?php echo $__t('मिडिया व्यवस्थापक', 'Media Manager'); ?></button>
            <button type="button" class="stg-subtab-btn" data-stg-group="colors"><i class="fas fa-palette me-1"></i> <?php echo $__t('थिम रङहरू', 'Theme Colors'); ?></button>
            <button type="button" class="stg-subtab-btn" data-stg-group="version"><i class="fas fa-code-branch me-1"></i> <?php echo $__t('संस्करण', 'Version'); ?></button>
            <button type="button" class="stg-subtab-btn" data-stg-group="all"><i class="fas fa-table-cells-large me-1"></i> <?php echo $__t('सबै देखाउनुहोस्', 'Show All'); ?></button>
        </div>
        <div class="row">
        <div class="col-lg-12">
            <!-- Sidebar -->
            <!-- Media Manager -->
            <div class="card mb-4 stg-section-card stg-accent-card stg-filter-card" data-stg-panel="branding" data-stg-group="media" data-stg-order="1">
                <div class="card-header stg-section-header stg-soft-green-header">
                    <h5 class="mb-0 stg-section-title"><i class="lucide-icon me-2" aria-hidden="true" data-lucide="images"></i><?php echo $__t('मिडिया व्यवस्थापक (सबै fixed फोटो)', 'Media Manager (all fixed photos)'); ?></h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?php echo $__t('साइट लोगो (Default)', 'Site Logo (Default)'); ?></label>
                            <?php if (!empty($settings['logo'])): ?><img src="../<?php echo htmlspecialchars($settings['logo'], ENT_QUOTES, 'UTF-8'); ?>" alt="Logo" class="img-fluid mb-2 border rounded stg-media-preview-logo"><?php endif; ?>
                            <input type="file" name="logo" class="form-control" accept="image/*">
                            <small class="stg-muted"><?php echo $__t('Fallback लोगो', 'Fallback logo'); ?> · <?php echo $__t('अनुशंसित', 'Recommended'); ?>: 1200x460+</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?php echo $__t('नेपाली लोगो (NE)', 'Nepali Logo (NE)'); ?></label>
                            <?php if (!empty($settings['logo_np'])): ?><img src="../<?php echo htmlspecialchars($settings['logo_np'], ENT_QUOTES, 'UTF-8'); ?>" alt="Logo NP" class="img-fluid mb-2 border rounded stg-media-preview-logo"><?php endif; ?>
                            <input type="file" name="logo_np" class="form-control" accept="image/*">
                            <small class="stg-muted"><?php echo $__t('नेपाली भाषा हुँदा यो देखिन्छ', 'Shown when site language is Nepali'); ?></small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold"><?php echo $__t('अंग्रेजी लोगो (EN)', 'English Logo (EN)'); ?></label>
                            <?php if (!empty($settings['logo_en'])): ?><img src="../<?php echo htmlspecialchars($settings['logo_en'], ENT_QUOTES, 'UTF-8'); ?>" alt="Logo EN" class="img-fluid mb-2 border rounded stg-media-preview-logo"><?php endif; ?>
                            <input type="file" name="logo_en" class="form-control" accept="image/*">
                            <small class="stg-muted"><?php echo $__t('English भाषा हुँदा यो देखिन्छ', 'Shown when site language is English'); ?></small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">
                                <i class="fas fa-globe text-success me-1"></i>
                                <?php echo $__t('Favicon (Site Icon)', 'Favicon (Site Icon)'); ?>
                            </label>
                            <?php
                            $faviconPreview = trim((string)($settings['site_favicon'] ?? ''));
                            if ($faviconPreview === '' && function_exists('getSiteFaviconPath')) {
                                $faviconPreview = getSiteFaviconPath();
                            }
                            ?>
                            <?php if ($faviconPreview !== ''): ?>
                            <div class="mb-2 d-flex align-items-center gap-2">
                                <img src="../<?php echo htmlspecialchars(ltrim($faviconPreview, '/'), ENT_QUOTES, 'UTF-8'); ?>"
                                     alt="Favicon" class="border rounded" style="width:48px;height:48px;object-fit:contain;background:#f8fafc;padding:4px;">
                                <small class="stg-muted"><?php echo $__t('Browser tab मा देखिने icon', 'Icon shown in browser tab'); ?></small>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="clear_site_favicon" value="1" id="clear_site_favicon">
                                <label class="form-check-label text-danger" for="clear_site_favicon">
                                    <i class="fas fa-trash-alt me-1"></i><?php echo $__t('Favicon हटाउनुहोस्', 'Remove favicon'); ?>
                                </label>
                            </div>
                            <?php endif; ?>
                            <input type="file" name="site_favicon" class="form-control" accept="image/png,image/jpeg,image/webp,image/gif,image/svg+xml,.ico">
                            <small class="stg-muted d-block mt-1"><?php echo $__t('अनुशंसित: 64×64 वा 192×192 PNG/ICO — Google search र browser tab', 'Recommended: 64×64 or 192×192 PNG/ICO — for browser tab and search'); ?></small>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">
                                <i class="fas fa-mountain text-info me-1"></i>
                                <?php echo $__t('हेडर हिमाल पृष्ठभूमि फोटो', 'Header Himal Background Photo'); ?>
                            </label>
                            <?php
                            $himalCurrent = $settings['himal_bg'] ?? '';
                            $himalOpacityVal = isset($settings['himal_bg_opacity']) ? (int)$settings['himal_bg_opacity'] : 100;
                            ?>
                            <?php if (!empty($himalCurrent)): ?>
                            <!-- Panoramic preview matching actual header ratio -->
                            <div style="position:relative;width:100%;height:80px;overflow:hidden;border-radius:6px;border:1px solid #dee2e6;margin-bottom:10px;background:#f8f9fa;">
                                <img src="../<?php echo htmlspecialchars($himalCurrent, ENT_QUOTES, 'UTF-8'); ?>"
                                     alt="Himal preview"
                                     style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:right center;opacity:<?php echo round($himalOpacityVal/100,2); ?>">
                                <div style="position:absolute;inset:0;background:linear-gradient(to right,rgba(255,255,255,1) 0%,rgba(255,255,255,0.85) 30%,rgba(255,255,255,0.2) 70%,transparent 100%);"></div>
                                <small style="position:absolute;bottom:4px;right:8px;color:rgba(0,0,0,0.45);font-size:10px;">
                                    <?php echo $__t('हेडरमा यस्तो देखिन्छ', 'Preview of header effect'); ?>
                                </small>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="clear_himal_bg" value="1" id="clear_himal_bg">
                                <label class="form-check-label text-danger" for="clear_himal_bg">
                                    <i class="fas fa-trash-alt me-1"></i><?php echo $__t('हिमाल फोटो हटाउनुहोस्', 'Remove himal photo'); ?>
                                </label>
                            </div>
                            <?php endif; ?>
                            <input type="file" name="himal_bg" class="form-control mb-2" accept="image/*">
                            <small class="stg-muted d-block mb-2">
                                <?php echo $__t('अनुशंसित: फराकिलो panoramic फोटो (जस्तै 1400×220px) — navigation को दायाँ भागमा gradient सहित देखिन्छ।', 'Recommended: wide panoramic photo (e.g. 1400×220px) — shows on the right side of the navigation with a white gradient fade.'); ?>
                            </small>
                            <!-- Opacity slider -->
                            <label class="form-label fw-semibold mb-1">
                                <?php echo $__t('हिमाल देखिने मात्रा', 'Himal Visibility'); ?>:
                                <strong id="himal_opacity_display"><?php echo $himalOpacityVal; ?>%</strong>
                            </label>
                            <input type="range" name="himal_bg_opacity" id="himal_bg_opacity"
                                   class="form-range" min="0" max="100" step="5"
                                   value="<?php echo $himalOpacityVal; ?>"
                                   oninput="document.getElementById('himal_opacity_display').textContent=this.value+'%'">
                            <div class="d-flex justify-content-between">
                                <small class="text-muted"><?php echo $__t('अदृश्य', 'Hidden'); ?></small>
                                <small class="text-muted"><?php echo $__t('पूर्ण दृश्यमान', 'Fully Visible'); ?></small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?php echo $__t('About पेज फोटो', 'About Page Image'); ?></label>
                            <?php if (!empty($settings['about_page_image'])): ?><img src="../<?php echo htmlspecialchars($settings['about_page_image'], ENT_QUOTES, 'UTF-8'); ?>" alt="About" class="img-fluid mb-2 border rounded stg-media-preview-md"><?php endif; ?>
                            <input type="file" name="about_page_image" class="form-control" accept="image/*">
                            <small class="stg-muted"><?php echo $__t('अनुशंसित', 'Recommended'); ?>: 600x400</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?php echo $__t('About Intro दायाँ फोटो', 'About Intro Right Image'); ?></label>
                            <?php if (!empty($settings['about_intro_image'])): ?><img src="../<?php echo htmlspecialchars($settings['about_intro_image'], ENT_QUOTES, 'UTF-8'); ?>" alt="About Intro" class="img-fluid mb-2 border rounded stg-media-preview-md"><?php endif; ?>
                            <input type="file" name="about_intro_image" class="form-control" accept="image/*">
                            <small class="stg-muted"><?php echo $__t('अनुशंसित', 'Recommended'); ?>: 700x900</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?php echo $__t('इतिहास सेक्शन फोटो', 'History Section Photo'); ?></label>
                            <?php if (!empty($settings['history_photo'])): ?><img src="../<?php echo htmlspecialchars($settings['history_photo'], ENT_QUOTES, 'UTF-8'); ?>" alt="History" class="img-fluid mb-2 border rounded stg-media-preview-md"><?php endif; ?>
                            <input type="file" name="history_photo" class="form-control" accept="image/*">
                            <small class="stg-muted"><?php echo $__t('"हाम्रो इतिहास" section फोटो', '"Our History" section photo'); ?></small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold"><?php echo $__t('मोबाइल एप फोटो', 'Mobile App Photo'); ?></label>
                            <?php if (!empty($settings['mobile_app_photo'])): ?><img src="../<?php echo htmlspecialchars($settings['mobile_app_photo'], ENT_QUOTES, 'UTF-8'); ?>" alt="App" class="img-fluid mb-2 border rounded stg-media-preview-md"><?php endif; ?>
                            <input type="file" name="mobile_app_photo" class="form-control" accept="image/*">
                            <small class="stg-muted"><?php echo $__t('अनुशंसित', 'Recommended'); ?>: 400x600</small>
                        </div>
                        <div class="col-md-6 d-flex align-items-center">
                            <div class="alert alert-secondary w-100 mb-0 small" role="alert">
                                <i class="fas fa-users me-1"></i>
                                <?php echo $__t('अध्यक्ष / CEO को फोटो अब <strong>टिम सदस्य</strong> बाट व्यवस्थापन गर्नुहोस्।','Chairman / CEO photos are now managed from <strong>Team Members</strong>.'); ?>
                                <a href="team-karmachari.php" class="d-block mt-1"><?php echo $__t('→ टिम खोल्नुहोस्','→ Open Team'); ?></a>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label fw-semibold"><?php echo $__t('Default Share Image (SEO OG)', 'Default Share Image (SEO OG)'); ?></label>
                            <?php if (!empty($settings['seo_og_image'])): ?><img src="../<?php echo htmlspecialchars($settings['seo_og_image'], ENT_QUOTES, 'UTF-8'); ?>" alt="OG" class="img-fluid mb-2 border rounded stg-media-preview-md"><?php endif; ?>
                            <input type="file" name="seo_og_image" class="form-control" accept="image/jpeg,image/png,image/webp">
                            <small class="stg-muted d-block mt-1"><?php echo $__t('अनुशंसित', 'Recommended'); ?>: 1200x630</small>
                            <?php if (!empty($settings['seo_og_image'])): ?>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" name="clear_seo_og_image" value="1" id="clear_seo_og_image">
                                <label class="form-check-label" for="clear_seo_og_image"><?php echo $__t('Share image हटाउनुहोस्', 'Remove share image'); ?></label>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

<!-- ═══════════════════════════════════════════════════════════════
     IMPROVED THEME COLOR SECTION — Live Preview Color Picker
     Replace the existing "Theme Color" card in admin/settings.php
     ═══════════════════════════════════════════════════════════════ -->

<!-- Theme Color — Live Preview -->
<div class="card mb-4 stg-section-card stg-filter-card" data-stg-panel="branding" data-stg-group="colors" data-stg-order="1" id="stg-color-card">
    <div class="card-header stg-section-header">
        <h5 class="stg-section-title"><i class="fas fa-palette"></i> <?php echo $__t('थिम रंग (Live Preview)', 'Theme Colors (Live Preview)'); ?></h5>
        <small class="text-muted ms-auto"><?php echo $__t('रंग बदल्दा तुरुन्तै preview देखिन्छ — Save गरेपछि website मा लागू हुन्छ', 'Preview updates instantly — applied to website after Save'); ?></small>
    </div>
    <div class="card-body p-0">
        <div class="d-flex flex-lg-row flex-column" style="min-height:460px">

            <!-- LEFT: Color Inputs -->
            <div class="stg-clr-inputs p-3 p-lg-4" style="flex:0 0 380px;max-width:100%;border-right:1px solid #eee">

                <!-- Presets -->
                <div class="mb-3">
                    <label class="form-label fw-semibold small text-uppercase" style="letter-spacing:.05em;color:#6b7280">
                        <i class="fas fa-swatchbook me-1"></i><?php echo $__t('प्रिसेट', 'Presets'); ?>
                    </label>
                    <div class="d-flex flex-wrap gap-2" id="stgPresets">
                        <?php
                        $presets = [
                            ['name'=>'हरियो (आकाश)', 'name_en'=>'Green (Aakash)',   'primary'=>'#1a5f2a','secondary'=>'#c0392b','header'=>'#c0392b','footer'=>'#1a5f2a'],
                            ['name'=>'निलो', 'name_en'=>'Blue',       'primary'=>'#1a4f7a','secondary'=>'#e67e22','header'=>'#1e3a5f','footer'=>'#1a4f7a'],
                            ['name'=>'बैजनी', 'name_en'=>'Purple',   'primary'=>'#5b21b6','secondary'=>'#f59e0b','header'=>'#4c1d95','footer'=>'#3b0764'],
                            ['name'=>'डार्क', 'name_en'=>'Dark',     'primary'=>'#1e293b','secondary'=>'#3b82f6','header'=>'#0f172a','footer'=>'#0f172a'],
                            ['name'=>'टील', 'name_en'=>'Teal',       'primary'=>'#0f766e','secondary'=>'#f97316','header'=>'#115e59','footer'=>'#0f766e'],
                            ['name'=>'मरून', 'name_en'=>'Maroon',    'primary'=>'#881337','secondary'=>'#fbbf24','header'=>'#9f1239','footer'=>'#881337'],
                        ];
                        foreach ($presets as $p): ?>
                        <button type="button" class="stg-preset-btn"
                                title="<?php echo htmlspecialchars($__t($p['name'], $p['name_en'])); ?>"
                                data-primary="<?php echo $p['primary']; ?>"
                                data-secondary="<?php echo $p['secondary']; ?>"
                                data-header="<?php echo $p['header']; ?>"
                                data-footer="<?php echo $p['footer']; ?>"
                                style="background:linear-gradient(135deg,<?php echo $p['primary']; ?> 50%,<?php echo $p['secondary']; ?> 50%);width:36px;height:36px;border-radius:8px;border:2px solid #e5e7eb;cursor:pointer;transition:transform .15s,border-color .15s"
                                onmouseover="this.style.transform='scale(1.15)';this.style.borderColor='#6b7280'"
                                onmouseout="this.style.transform='scale(1)';this.style.borderColor='#e5e7eb'">
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <hr class="my-3" style="border-color:#f0f0f0">

                <!-- Color Fields -->
                <?php
                $colorFields = [
                    ['key'=>'primary_color',   'label'=>$__t('प्राथमिक रंग','Primary Color'),  'label_en'=>'Primary Color',  'desc'=>$__t('Buttons, links, cards border','Buttons, links, cards border'),  'default'=>'#1a5f2a', 'icon'=>'fas fa-circle',  'preview'=>'primary'],
                    ['key'=>'secondary_color',  'label'=>$__t('सेकेन्डरी रंग','Secondary Color'),'label_en'=>'Secondary Color','desc'=>$__t('Accent, badges, highlights','Accent, badges, highlights'),        'default'=>'#c0392b', 'icon'=>'fas fa-circle',  'preview'=>'secondary'],
                    ['key'=>'header_color',    'label'=>$__t('हेडर रंग','Header Color'),        'label_en'=>'Header Color',   'desc'=>$__t('Navigation header background','Navigation header background'),    'default'=>'#c0392b', 'icon'=>'fas fa-grip-horizontal', 'preview'=>'header'],
                    ['key'=>'footer_color',    'label'=>$__t('फुटर रंग','Footer Color'),        'label_en'=>'Footer Color',   'desc'=>$__t('Footer section background','Footer section background'),          'default'=>'#1a5f2a', 'icon'=>'fas fa-grip-horizontal', 'preview'=>'footer'],
                    ['key'=>'topbar_color',    'label'=>$__t('टप बार रंग','Top Bar Color'),     'label_en'=>'Top Bar Color',  'desc'=>$__t('माथिल्लो utility strip','Top utility strip'),                    'default'=>'#c0392b', 'icon'=>'fas fa-bars',    'preview'=>'topbar'],
                ];
                foreach ($colorFields as $cf):
                    $val = htmlspecialchars($settings[$cf['key']] ?? $cf['default']);
                ?>
                <div class="stg-color-row mb-3" data-preview="<?php echo $cf['preview']; ?>">
                    <label class="form-label fw-semibold small mb-1">
                        <i class="<?php echo $cf['icon']; ?> me-1" style="color:<?php echo $val; ?>;" id="stg-icon-<?php echo $cf['key']; ?>"></i>
                        <?php echo $cf['label']; ?>
                    </label>
                    <div class="input-group input-group-sm">
                        <input type="color"
                               name="<?php echo $cf['key']; ?>"
                               id="stg-clr-<?php echo $cf['key']; ?>"
                               class="form-control form-control-color stg-color-input"
                               value="<?php echo $val; ?>"
                               style="width:48px;flex:0 0 48px;cursor:pointer;padding:2px 3px;border-radius:6px 0 0 6px">
                        <input type="text"
                               id="stg-hex-<?php echo $cf['key']; ?>"
                               class="form-control stg-hex-input font-monospace"
                               value="<?php echo strtoupper($val); ?>"
                               maxlength="7"
                               placeholder="#000000"
                               style="border-radius:0;letter-spacing:.05em;font-size:.82rem">
                        <button type="button"
                                class="btn btn-outline-secondary stg-reset-btn"
                                data-key="<?php echo $cf['key']; ?>"
                                data-default="<?php echo $cf['default']; ?>"
                                title="<?php echo $__t('डिफल्टमा फर्काउनुस्','Reset to default'); ?>"
                                style="border-radius:0 6px 6px 0;padding:0 8px">
                            <i class="fas fa-undo" style="font-size:.75rem"></i>
                        </button>
                    </div>
                    <small class="text-muted" style="font-size:.75rem"><?php echo $cf['desc']; ?></small>
                </div>
                <?php endforeach; ?>

                <!-- Auto-harmony button -->
                <div class="d-flex gap-2 mt-3 pt-2 border-top">
                    <button type="button" class="btn btn-outline-secondary btn-sm flex-fill" id="stgAutoSecondary">
                        <i class="fas fa-magic me-1"></i><?php echo $__t('सेकेन्डरी Auto', 'Auto Secondary'); ?>
                    </button>
                    <button type="button" class="btn btn-outline-secondary btn-sm flex-fill" id="stgResetAll">
                        <i class="fas fa-rotate-left me-1"></i><?php echo $__t('सबै Reset', 'Reset All'); ?>
                    </button>
                </div>
            </div>

            <!-- RIGHT: Live Preview Panel -->
            <div class="stg-clr-preview flex-fill p-3 p-lg-4" style="background:#f1f5f9">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <span class="fw-semibold small" style="color:#374151">
                        <i class="lucide-icon me-1" aria-hidden="true" data-lucide="eye"></i><?php echo $__t('Live Preview', 'Live Preview'); ?>
                    </span>
                    <div class="d-flex gap-1">
                        <button type="button" class="btn btn-sm stg-device-btn active" data-device="desktop" style="padding:3px 10px;font-size:.72rem;border-radius:6px" aria-label="Desktop" title="Desktop">
                            <i class="fas fa-desktop"></i>
                        </button>
                        <button type="button" class="btn btn-sm stg-device-btn" data-device="mobile" style="padding:3px 10px;font-size:.72rem;border-radius:6px" aria-label="Mobile Alt" title="Mobile Alt">
                            <i class="fas fa-mobile-alt"></i>
                        </button>
                    </div>
                </div>

                <!-- Preview Frame -->
                <div id="stgPreviewWrap" style="transition:all .3s ease">
                    <div id="stgPreviewFrame" style="border-radius:12px;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,.18);max-width:560px;margin:0 auto;font-family:'Mukta',sans-serif;font-size:13px;">

                        <!-- Topbar -->
                        <div id="prev-topbar" style="padding:6px 16px;display:flex;align-items:center;justify-content:space-between;font-size:11px;color:rgba(255,255,255,.9);">
                            <span><i class="fas fa-phone me-1" style="font-size:10px"></i> 061-590067</span>
                            <span><i class="fas fa-envelope me-1" style="font-size:10px"></i> info@sahakari.org.np</span>
                            <span style="background:rgba(255,255,255,.18);border-radius:4px;padding:1px 7px;font-size:10px">EN | NP</span>
                        </div>

                        <!-- Header / Nav -->
                        <div id="prev-header" style="padding:12px 16px;display:flex;align-items:center;justify-content:space-between;color:#fff;">
                            <div style="display:flex;align-items:center;gap:8px">
                                <div style="width:32px;height:32px;border-radius:8px;background:rgba(255,255,255,.25);display:flex;align-items:center;justify-content:center">
                                    <i class="fas fa-seedling" style="font-size:14px;color:#fff"></i>
                                </div>
                                <div>
                                    <div style="font-weight:800;font-size:13px;line-height:1.1">आकाश सहकारी</div>
                                    <div style="font-size:10px;opacity:.8">Aakash Cooperative</div>
                                </div>
                            </div>
                            <div style="display:flex;gap:10px;font-size:11px;opacity:.9">
                                <span><?php echo $__t('गृहपृष्ठ','Home'); ?></span>
                                <span><?php echo $__t('सेवाहरू','Services'); ?></span>
                                <span><?php echo $__t('ब्याज दर','Rates'); ?></span>
                                <span><?php echo $__t('सम्पर्क','Contact'); ?></span>
                            </div>
                        </div>

                        <!-- Hero Banner -->
                        <div id="prev-hero" style="padding:20px 16px;color:#fff;position:relative;overflow:hidden;">
                            <div style="position:absolute;top:-20px;right:-20px;width:100px;height:100px;border-radius:50%;background:rgba(255,255,255,.08)"></div>
                            <div style="font-size:15px;font-weight:800;margin-bottom:5px"><?php echo $__t('समृद्ध जीवन, सुरक्षित बचत','Prosperous Life, Safe Savings'); ?></div>
                            <div style="font-size:11px;opacity:.85;margin-bottom:12px"><?php echo $__t('विश्वसनीय सहकारी सेवा','Trusted cooperative service'); ?></div>
                            <div style="display:flex;gap:8px">
                                <span id="prev-btn-primary" style="padding:5px 14px;border-radius:6px;font-size:11px;font-weight:700;cursor:default;color:#fff"><?php echo $__t('सदस्य बन्नुस्','Become Member'); ?></span>
                                <span style="padding:5px 14px;border-radius:6px;font-size:11px;font-weight:600;border:1.5px solid rgba(255,255,255,.5);color:#fff;cursor:default"><?php echo $__t('थप जान्नुस्','Learn More'); ?></span>
                            </div>
                        </div>

                        <!-- Cards row -->
                        <div style="background:#f8fdf9;padding:12px 16px;display:flex;gap:8px">
                            <?php $cards = [
                                ['icon'=>'fa-piggy-bank', 'title'=>$__t('बचत खाता','Savings'), 'val'=>'8%'],
                                ['icon'=>'fa-hand-holding-usd','title'=>$__t('ऋण','Loan'),'val'=>'12%'],
                                ['icon'=>'fa-users','title'=>$__t('सदस्य','Members'),'val'=>'५,२००'],
                            ];
                            foreach ($cards as $card): ?>
                            <div style="flex:1;background:#fff;border-radius:8px;padding:10px 8px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.07)">
                                <div id="prev-card-icon-<?php echo $loop ?? 0; ?>" style="width:28px;height:28px;border-radius:7px;margin:0 auto 5px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:12px">
                                    <i class="fas <?php echo $card['icon']; ?>"></i>
                                </div>
                                <div style="font-size:12px;font-weight:800;color:#1a1a2e"><?php echo $card['val']; ?></div>
                                <div style="font-size:10px;color:#6b7280"><?php echo $card['title']; ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Footer -->
                        <div id="prev-footer" style="padding:14px 16px;color:rgba(255,255,255,.9);display:flex;align-items:center;justify-content:space-between;">
                            <div>
                                <div style="font-weight:700;font-size:12px">आकाश सहकारी</div>
                                <div style="font-size:10px;opacity:.7"><?php echo $__t('© २०८१ सबै अधिकार सुरक्षित','© 2081 All rights reserved'); ?></div>
                            </div>
                            <div style="display:flex;gap:6px">
                                <?php foreach(['fa-facebook-f','fa-youtube','fa-phone'] as $si): ?>
                                <span style="width:24px;height:24px;border-radius:6px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:10px;cursor:default">
                                    <i class="fab <?php echo $si; ?>" style="color:#fff"></i>
                                </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Color harmony info -->
                <div class="mt-3 p-2 rounded" style="background:#fff;border:1px solid #e5e7eb;font-size:.78rem;color:#6b7280">
                    <i class="fas fa-info-circle me-1 text-primary"></i>
                    <?php echo $__t(
                        'Save गरेपछि website को सबै page मा नयाँ रंग लागू हुनेछ।',
                        'After saving, new colors will apply across all website pages.'
                    ); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Live Color Preview JavaScript -->
<script>
(function () {
'use strict';

// ─── Config ───────────────────────────────────────────────
var DEFAULTS = {
    primary_color:   '<?php echo $settings['primary_color']   ?? '#1a5f2a'; ?>',
    secondary_color: '<?php echo $settings['secondary_color'] ?? '#c0392b'; ?>',
    header_color:    '<?php echo $settings['header_color']    ?? '#c0392b'; ?>',
    footer_color:    '<?php echo $settings['footer_color']    ?? '#1a5f2a'; ?>',
    topbar_color:    '<?php echo $settings['topbar_color']    ?? '#c0392b'; ?>',
};

// ─── DOM refs ─────────────────────────────────────────────
function $id(id) { return document.getElementById(id); }
function $all(sel, root) { return (root || document).querySelectorAll(sel); }

var prevTopbar   = $id('prev-topbar');
var prevHeader   = $id('prev-header');
var prevHero     = $id('prev-hero');
var prevBtnPrim  = $id('prev-btn-primary');
var prevFooter   = $id('prev-footer');
var cardIcons    = $all('[id^="prev-card-icon-"]');
var previewWrap  = $id('stgPreviewWrap');
var previewFrame = $id('stgPreviewFrame');

// ─── Color Utilities ─────────────────────────────────────
function hexToRgb(hex) {
    hex = hex.replace('#', '');
    if (hex.length === 3) hex = hex.split('').map(function(c){ return c+c; }).join('');
    var n = parseInt(hex, 16);
    return { r: (n >> 16) & 255, g: (n >> 8) & 255, b: n & 255 };
}

function lighten(hex, pct) {
    var c = hexToRgb(hex);
    var r = Math.min(255, Math.round(c.r + (255 - c.r) * pct));
    var g = Math.min(255, Math.round(c.g + (255 - c.g) * pct));
    var b = Math.min(255, Math.round(c.b + (255 - c.b) * pct));
    return 'rgb('+r+','+g+','+b+')';
}

function darken(hex, pct) {
    var c = hexToRgb(hex);
    var r = Math.max(0, Math.round(c.r * (1-pct)));
    var g = Math.max(0, Math.round(c.g * (1-pct)));
    var b = Math.max(0, Math.round(c.b * (1-pct)));
    return 'rgb('+r+','+g+','+b+')';
}

function complementary(hex) {
    // Simple complement — rotate hue ~150°
    var c = hexToRgb(hex);
    // Shift RGB channels for warm/cool complement
    var r2 = Math.min(255, Math.round(255 - c.r * 0.3));
    var g2 = Math.max(0, Math.round(c.g * 0.3));
    var b2 = Math.max(0, Math.round(c.b * 0.5));
    return '#' + [r2,g2,b2].map(function(v){ return v.toString(16).padStart(2,'0'); }).join('');
}

function isValidHex(h) { return /^#[0-9a-fA-F]{6}$/.test(h); }

/* WCAG relative luminance — match global-theme.php $__textOn */
function textOnColor(hex) {
    hex = String(hex || '').replace('#', '');
    if (hex.length !== 6) return '#ffffff';
    function lin(c) {
        c = c / 255;
        return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
    }
    var r = lin(parseInt(hex.slice(0, 2), 16));
    var g = lin(parseInt(hex.slice(2, 4), 16));
    var b = lin(parseInt(hex.slice(4, 6), 16));
    var lum = 0.2126 * r + 0.7152 * g + 0.0722 * b;
    var contrastWhite = 1.05 / (lum + 0.05);
    var contrastBlack = (lum + 0.05) / 0.05;
    return contrastBlack > contrastWhite ? '#111827' : '#ffffff';
}

function brandInk(hex, darker) {
    if (textOnColor(hex) === '#ffffff') return hex;
    if (textOnColor(darker) === '#ffffff') return darker;
    return '#1a2e1f';
}

function hexToRgbCsv(hex) {
    hex = String(hex || '').replace('#', '');
    if (hex.length !== 6) return '26, 95, 42';
    return parseInt(hex.slice(0, 2), 16) + ', '
         + parseInt(hex.slice(2, 4), 16) + ', '
         + parseInt(hex.slice(4, 6), 16);
}

// ─── Apply colors to preview ──────────────────────────────
function applyPreview(colors) {
    var pc  = colors.primary_color   || DEFAULTS.primary_color;
    var sc  = colors.secondary_color || DEFAULTS.secondary_color;
    var hc  = colors.header_color    || DEFAULTS.header_color;
    var fc  = colors.footer_color    || DEFAULTS.footer_color;
    var tc  = colors.topbar_color    || DEFAULTS.topbar_color;
    var pcDark = darken(pc, 0.22);
    var scDark = darken(sc, 0.18);
    var onP = textOnColor(pc);
    var onS = textOnColor(sc);
    var onH = textOnColor(hc);
    var onF = textOnColor(fc);
    var pInk = brandInk(pc, pcDark);
    var sInk = brandInk(sc, scDark);

    if (prevTopbar)  prevTopbar.style.background = 'linear-gradient(90deg,'+tc+','+darken(tc,0.15)+')';
    if (prevHeader)  prevHeader.style.background = 'linear-gradient(135deg,'+hc+','+darken(hc,0.2)+')';

    if (prevHero) {
        prevHero.style.background = 'linear-gradient(135deg,'+pc+' 0%,'+darken(pc,0.25)+' 100%)';
        prevHero.style.color = onP;
    }
    if (prevBtnPrim) {
        prevBtnPrim.style.background = sc;
        prevBtnPrim.style.color = onS;
        prevBtnPrim.style.boxShadow  = '0 2px 8px ' + sc + '66';
    }
    if (prevFooter)  prevFooter.style.background = 'linear-gradient(135deg,'+fc+','+darken(fc,0.25)+')';

    // Card icons
    cardIcons.forEach(function(el) {
        el.style.background = 'linear-gradient(135deg,'+pc+','+darken(pc,0.2)+')';
        el.style.color = onP;
    });

    // Update CSS variables on the live page too (for real-time feel)
    var root = document.documentElement;
    root.style.setProperty('--primary-color', pc);
    root.style.setProperty('--primary-dark', pcDark);
    root.style.setProperty('--primary-rgb', hexToRgbCsv(pc));
    root.style.setProperty('--primary-ink', pInk);
    root.style.setProperty('--secondary-color', sc);
    root.style.setProperty('--secondary-dark', scDark);
    root.style.setProperty('--secondary-rgb', hexToRgbCsv(sc));
    root.style.setProperty('--secondary-ink', sInk);
    root.style.setProperty('--header-color', hc);
    root.style.setProperty('--footer-color', fc);
    root.style.setProperty('--topbar-bg', tc);
    root.style.setProperty('--text-on-primary', onP);
    root.style.setProperty('--text-on-secondary', onS);
    root.style.setProperty('--text-on-header', onH);
    root.style.setProperty('--text-on-footer', onF);
}

// ─── Gather current color values ─────────────────────────
function gatherColors() {
    var out = {};
    Object.keys(DEFAULTS).forEach(function(key) {
        var el = $id('stg-clr-' + key);
        out[key] = el ? el.value : DEFAULTS[key];
    });
    return out;
}

// ─── Sync color input ↔ hex text input ───────────────────
function syncColorToHex(key) {
    var clrEl = $id('stg-clr-' + key);
    var hexEl = $id('stg-hex-' + key);
    var iconEl = $id('stg-icon-' + key);
    if (!clrEl || !hexEl) return;

    clrEl.addEventListener('input', function() {
        hexEl.value = clrEl.value.toUpperCase();
        if (iconEl) iconEl.style.color = clrEl.value;
        applyPreview(gatherColors());
    });

    hexEl.addEventListener('input', function() {
        var v = hexEl.value.trim();
        if (!v.startsWith('#')) v = '#' + v;
        if (isValidHex(v)) {
            clrEl.value = v;
            if (iconEl) iconEl.style.color = v;
            applyPreview(gatherColors());
        }
    });

    hexEl.addEventListener('blur', function() {
        // Normalize on blur
        hexEl.value = clrEl.value.toUpperCase();
    });
}

// ─── Init all color fields ────────────────────────────────
Object.keys(DEFAULTS).forEach(function(key) {
    syncColorToHex(key);
});

// Initial preview render
applyPreview(gatherColors());

// ─── Presets ──────────────────────────────────────────────
$all('.stg-preset-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var map = {
            primary_color:   btn.dataset.primary,
            secondary_color: btn.dataset.secondary,
            header_color:    btn.dataset.header,
            footer_color:    btn.dataset.footer,
            topbar_color:    btn.dataset.secondary || btn.dataset.header,
        };
        Object.keys(map).forEach(function(key) {
            var clrEl = $id('stg-clr-' + key);
            var hexEl = $id('stg-hex-' + key);
            var iconEl = $id('stg-icon-' + key);
            if (clrEl && map[key]) {
                clrEl.value = map[key];
                if (hexEl) hexEl.value = map[key].toUpperCase();
                if (iconEl) iconEl.style.color = map[key];
            }
        });
        applyPreview(gatherColors());

        // Visual feedback on selected preset
        $all('.stg-preset-btn').forEach(function(b){ b.style.borderColor='#e5e7eb'; b.style.transform='scale(1)'; });
        btn.style.borderColor = '#374151';
        btn.style.transform   = 'scale(1.15)';
    });
});

// ─── Auto Secondary (complementary of primary) ────────────
var autoSecBtn = $id('stgAutoSecondary');
if (autoSecBtn) {
    autoSecBtn.addEventListener('click', function() {
        var primaryEl = $id('stg-clr-primary_color');
        if (!primaryEl) return;
        var comp = complementary(primaryEl.value);

        ['secondary_color','header_color','topbar_color'].forEach(function(key) {
            var clrEl = $id('stg-clr-' + key);
            var hexEl = $id('stg-hex-' + key);
            var iconEl = $id('stg-icon-' + key);
            if (clrEl) { clrEl.value = comp; }
            if (hexEl) { hexEl.value = comp.toUpperCase(); }
            if (iconEl) { iconEl.style.color = comp; }
        });
        applyPreview(gatherColors());

        autoSecBtn.innerHTML = '<i class="fas fa-check me-1"></i><?php echo $__t("लागू भयो","Applied"); ?>';
        setTimeout(function(){
            autoSecBtn.innerHTML = '<i class="fas fa-magic me-1"></i><?php echo $__t("सेकेन्डरी Auto","Auto Secondary"); ?>';
        }, 1800);
    });
}

// ─── Reset All ────────────────────────────────────────────
var resetAllBtn = $id('stgResetAll');
if (resetAllBtn) {
    resetAllBtn.addEventListener('click', function() {
        if (!confirm('<?php echo $__t("सबै रंग डिफल्टमा फर्काउने?","Reset all colors to defaults?"); ?>')) return;
        Object.keys(DEFAULTS).forEach(function(key) {
            var clrEl  = $id('stg-clr-' + key);
            var hexEl  = $id('stg-hex-' + key);
            var iconEl = $id('stg-icon-' + key);
            if (clrEl)  clrEl.value  = DEFAULTS[key];
            if (hexEl)  hexEl.value  = DEFAULTS[key].toUpperCase();
            if (iconEl) iconEl.style.color = DEFAULTS[key];
        });
        applyPreview(DEFAULTS);
    });
}

// ─── Individual Reset buttons ─────────────────────────────
$all('.stg-reset-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var key      = btn.dataset.key;
        var defVal   = btn.dataset.default;
        var clrEl    = $id('stg-clr-' + key);
        var hexEl    = $id('stg-hex-' + key);
        var iconEl   = $id('stg-icon-' + key);
        if (clrEl)  clrEl.value  = defVal;
        if (hexEl)  hexEl.value  = defVal.toUpperCase();
        if (iconEl) iconEl.style.color = defVal;
        applyPreview(gatherColors());
    });
});

// ─── Desktop / Mobile preview toggle ─────────────────────
$all('.stg-device-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        $all('.stg-device-btn').forEach(function(b){
            b.classList.remove('active');
            b.style.background = '';
            b.style.color = '';
        });
        btn.classList.add('active');

        if (btn.dataset.device === 'mobile') {
            if (previewFrame) {
                previewFrame.style.maxWidth = '280px';
                previewFrame.style.fontSize = '11px';
            }
        } else {
            if (previewFrame) {
                previewFrame.style.maxWidth = '560px';
                previewFrame.style.fontSize = '13px';
            }
        }
    });
});

})();
</script>

<style>
/* ── Color Picker Row ── */
.stg-color-row { transition: background .15s; border-radius: 8px; padding: 6px; margin-left: -6px; }
.stg-color-row:hover { background: #f8fafc; }

/* ── Hex input ── */
.stg-hex-input:focus { border-color: var(--primary-color, #1a5f2a); box-shadow: 0 0 0 2px rgba(26,95,42,.12); }

/* ── Device buttons ── */
.stg-device-btn { background: #f3f4f6; color: #6b7280; border: 1.5px solid #e5e7eb; }
.stg-device-btn.active { background: var(--primary-color, #1a5f2a); color: #fff; border-color: var(--primary-color, #1a5f2a); }

/* ── Preset btn focus ring ── */
.stg-preset-btn:focus-visible { outline: 2px solid var(--primary-color, #1a5f2a); outline-offset: 2px; }

/* ── Mobile preview frame ── */
#stgPreviewFrame { transition: all .3s ease; }

/* ── Responsive: stack on small screens ── */
@media (max-width: 767px) {
    #stg-color-card .d-flex.flex-lg-row { flex-direction: column !important; }
    .stg-clr-inputs { flex: 0 0 auto !important; max-width: 100% !important; border-right: none !important; border-bottom: 1px solid #eee; }
}
</style>

            <!-- ===================================================
                 Website Version Management
                 Admin ले website को version number अपडेट गर्न सक्छ।
                 यो version footer मा / system info मा देखाउन सकिन्छ।
                 =================================================== -->
            <div class="card mb-4 stg-section-card stg-accent-card stg-filter-card" id="version" data-stg-panel="branding" data-stg-group="version" data-stg-order="1">
                <div class="card-header stg-section-header stg-soft-green-header">
                    <h5 class="mb-0 stg-section-title"><i class="fas fa-code-branch me-2"></i><?php echo $__t('वेबसाइट संस्करण', 'Website Version'); ?></h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php echo $__t('संस्करण नम्बर', 'Version Number'); ?></label>
                        <!-- जस्तै: 1.0.0 वा 2.5.1 -->
                        <input type="text" name="site_version" class="form-control"
                               value="<?php echo htmlspecialchars($settings['site_version'] ?? '1.0.0'); ?>"
                               placeholder="e.g. 1.0.0">
                        <small class="stg-muted"><?php echo $__t('Website को संस्करण नम्बर — जस्तै: 1.0.0, 2.1.0', 'Website version number — e.g., 1.0.0, 2.1.0'); ?></small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><?php echo $__t('सुरु मिति (BS)', 'Launch Date (BS)'); ?></label>
                        <!-- Website सुरु भएको मिति — BS (बि.सं.) format मा -->
                        <div class="input-group">
                            <input type="text" name="site_launch_date"
                                   class="form-control nepali-datepicker"
                                   value="<?php echo htmlspecialchars($settings['site_launch_date'] ?? ''); ?>"
                                   placeholder="YYYY-MM-DD" autocomplete="off">
                            <span class="input-group-text stg-date-addon">
                                <i class="fas fa-calendar-alt"></i>
                            </span>
                        </div>
                        <small class="stg-muted"><?php echo $__t('Website सुरु भएको मिति (BS / बि.सं.)', 'Website launch date (BS)'); ?></small>
                    </div>
                    <!-- हालको version देखाउँछ -->
                    <div class="alert stg-alert-success py-2 mb-0 d-flex align-items-center gap-2">
                        <i class="fas fa-info-circle"></i>
                        <span><?php echo $__t('हालको संस्करण', 'Current version'); ?>:
                            <strong><?php echo htmlspecialchars($settings['site_version'] ?? '1.0.0'); ?></strong>
                        </span>
                    </div>
                </div>
            </div>

            <!-- Save Button -->
            <div class="card stg-save-card">
                <div class="card-body py-3 admin-form-actions">
                    <button type="submit" class="btn stg-save-btn px-4">
                        <i class="lucide-icon me-1" aria-hidden="true" data-lucide="save"></i> सेटिङ्स सेभ गर्नुहोस्
                    </button>
                </div>
            </div>
        </div>
        </div>
        </div>
    </div>
    </div>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.stg-subtabs[data-stg-panel]').forEach(function (bar) {
        var panel = bar.getAttribute('data-stg-panel') || '';
        var buttons = Array.prototype.slice.call(bar.querySelectorAll('.stg-subtab-btn[data-stg-group]'));
        var cards = Array.prototype.slice.call(document.querySelectorAll('.stg-filter-card[data-stg-panel="' + panel + '"]'));
        if (!panel || !buttons.length || !cards.length) return;

        function setGroup(group) {
            buttons.forEach(function (btn) {
                btn.classList.toggle('active', btn.getAttribute('data-stg-group') === group);
            });
            cards.forEach(function (card) {
                var cardGroup = card.getAttribute('data-stg-group') || 'all';
                var show = (group === 'all' || cardGroup === group);
                card.classList.toggle('d-none', !show);
            });
        }

        buttons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                setGroup(btn.getAttribute('data-stg-group') || 'all');
            });
        });

        var defaultBtn = bar.querySelector('.stg-subtab-btn.active[data-stg-group]');
        setGroup(defaultBtn ? defaultBtn.getAttribute('data-stg-group') : 'all');
    });
});
</script>

<?php require_once 'includes/admin-footer.php'; ?>
