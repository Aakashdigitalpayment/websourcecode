<?php
require_once __DIR__ . '/_bootstrap.php'; // bootstrap → config auto-loaded
$pageTitle = isEnglish() ? 'Downloads' : 'डाउनलोडहरू';
require_once 'includes/header.php';
$L = getLangStrings();

// Get downloads from database
try {
    $db = getDB();
    $downloads = $db->query("SELECT * FROM downloads WHERE is_active = 1 ORDER BY category, id DESC")->fetchAll();
} catch (Throwable $e) {
    $downloads = [];
}

// Group downloads by category
$categorizedDownloads = [];
foreach ($downloads as $download) {
    $cat = $download['category'] ?: 'general';
    $categorizedDownloads[$cat][] = $download;
}
?>

<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><?php echo $L['downloads']; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item active"><?php echo $L['downloads']; ?></li>
            </ol>
        </nav>
    </div>
</section>

<!-- Downloads Section -->
<section class="section-padding">
    <div class="container">
        <div class="section-header section-header-unified text-center mb-5" data-aos="fade-up">
            <div class="section-badge-wrap">
                <span class="section-badge"><i class="fas fa-download"></i> <?php echo $L['downloads']; ?></span>
            </div>
            <h2><?php echo isEnglish() ? 'Download Resources' : 'स्रोत सामग्रीहरू डाउनलोड गर्नुहोस्'; ?></h2>
            <div class="section-divider"></div>
            <p><?php echo isEnglish() ? 'Download forms, reports, and other documents' : 'फारमहरू, प्रतिवेदनहरू र अन्य कागजातहरू डाउनलोड गर्नुहोस्'; ?></p>
        </div>

        <?php if (!empty($categorizedDownloads)): ?>
            <?php
            $categoryLabels = [
                'general' => isEnglish() ? 'General Documents' : 'सामान्य कागजातहरू',
                'forms' => isEnglish() ? 'Application Forms' : 'आवेदन फारमहरू',
                'reports' => isEnglish() ? 'Reports' : 'प्रतिवेदनहरू',
                'policies' => isEnglish() ? 'Policies' : 'नीतिहरू',
                'notices' => isEnglish() ? 'Notices' : 'सूचनाहरू',
            ];
            ?>
            <?php foreach ($categorizedDownloads as $category => $items): ?>
            <div class="download-category mb-5">
                <h4 class="category-title">
                    <i class="lucide-icon" aria-hidden="true" data-lucide="folder-open"></i>
                    <?php echo $categoryLabels[$category] ?? ucfirst($category); ?>
                </h4>
                <div class="row">
                    <?php foreach ($items as $item): ?>
                    <div class="col-lg-4 col-md-6 mb-4">
                        <div class="download-card">
                            <div class="download-icon">
                                <?php
                                $fileIcon = 'fas fa-file';
                                if (strpos($item['file_type'] ?? '', 'pdf') !== false) $fileIcon = 'fas fa-file-pdf';
                                elseif (strpos($item['file_type'] ?? '', 'doc') !== false) $fileIcon = 'fas fa-file-word';
                                elseif (strpos($item['file_type'] ?? '', 'xls') !== false) $fileIcon = 'fas fa-file-excel';
                                elseif (strpos($item['file_type'] ?? '', 'image') !== false) $fileIcon = 'fas fa-file-image';
                                ?>
                                <i class="<?php echo $fileIcon; ?>"></i>
                            </div>
                            <div class="download-info">
                                <h5><?php echo getLangField($item, 'title'); ?></h5>
                                <span class="file-type"><?php echo strtoupper($item['file_type'] ?? 'PDF'); ?></span>
                                <span class="download-count">
                                    <i class="fas fa-download"></i> <?php echo $item['download_count'] ?? 0; ?>
                                </span>
                            </div>
                            <a href="<?php echo SITE_URL . $item['file_path']; ?>" class="btn btn-primary btn-sm" target="_blank" download>
                                <i class="fas fa-download"></i> <?php echo $L['download']; ?>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <!-- Sample Downloads when database is empty -->
            <div class="row">
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="download-card">
                        <div class="download-icon">
                            <i class="fas fa-file-pdf"></i>
                        </div>
                        <div class="download-info">
                            <h5><?php echo isEnglish() ? 'Membership Form' : 'सदस्यता फारम'; ?></h5>
                            <span class="file-type">PDF</span>
                        </div>
                        <button type="button" class="btn btn-secondary btn-sm" disabled title="Admin panel मा file upload गर्नुहोस्">
                            <i class="fas fa-download"></i> <?php echo $L['download']; ?>
                        </button>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="download-card">
                        <div class="download-icon">
                            <i class="fas fa-file-pdf"></i>
                        </div>
                        <div class="download-info">
                            <h5><?php echo isEnglish() ? 'Loan Application Form' : 'ऋण आवेदन फारम'; ?></h5>
                            <span class="file-type">PDF</span>
                        </div>
                        <button type="button" class="btn btn-secondary btn-sm" disabled title="Admin panel मा file upload गर्नुहोस्">
                            <i class="fas fa-download"></i> <?php echo $L['download']; ?>
                        </button>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="download-card">
                        <div class="download-icon">
                            <i class="fas fa-file-pdf"></i>
                        </div>
                        <div class="download-info">
                            <h5><?php echo isEnglish() ? 'Annual Report' : 'वार्षिक प्रतिवेदन'; ?></h5>
                            <span class="file-type">PDF</span>
                        </div>
                        <button type="button" class="btn btn-secondary btn-sm" disabled title="Admin panel मा file upload गर्नुहोस्">
                            <i class="fas fa-download"></i> <?php echo $L['download']; ?>
                        </button>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="download-card">
                        <div class="download-icon">
                            <i class="fas fa-file-pdf"></i>
                        </div>
                        <div class="download-info">
                            <h5><?php echo isEnglish() ? 'Saving Account Form' : 'बचत खाता फारम'; ?></h5>
                            <span class="file-type">PDF</span>
                        </div>
                        <button type="button" class="btn btn-secondary btn-sm" disabled title="Admin panel मा file upload गर्नुहोस्">
                            <i class="fas fa-download"></i> <?php echo $L['download']; ?>
                        </button>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="download-card">
                        <div class="download-icon">
                            <i class="fas fa-file-pdf"></i>
                        </div>
                        <div class="download-info">
                            <h5><?php echo isEnglish() ? 'KYC Form' : 'KYC फारम'; ?></h5>
                            <span class="file-type">PDF</span>
                        </div>
                        <button type="button" class="btn btn-secondary btn-sm" disabled title="Admin panel मा file upload गर्नुहोस्">
                            <i class="fas fa-download"></i> <?php echo $L['download']; ?>
                        </button>
                    </div>
                </div>
                <div class="col-lg-4 col-md-6 mb-4">
                    <div class="download-card">
                        <div class="download-icon">
                            <i class="fas fa-file-pdf"></i>
                        </div>
                        <div class="download-info">
                            <h5><?php echo isEnglish() ? 'Bylaws' : 'विनियमावली'; ?></h5>
                            <span class="file-type">PDF</span>
                        </div>
                        <button type="button" class="btn btn-secondary btn-sm" disabled title="Admin panel मा file upload गर्नुहोस्">
                            <i class="fas fa-download"></i> <?php echo $L['download']; ?>
                        </button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</section>

<?php require_once 'includes/footer.php'; ?>
