<?php
require_once __DIR__ . '/_bootstrap.php'; // bootstrap → config auto-loaded
$pageTitle = isEnglish() ? 'Important Links' : 'महत्त्वपूर्ण लिंकहरू';
require_once 'includes/header.php';
$L = getLangStrings();
?>

<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><?php echo $L['important_links']; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item active"><?php echo $L['important_links']; ?></li>
            </ol>
        </nav>
    </div>
</section>

<!-- Important Links Section -->
<section class="section-padding">
    <div class="container">
        <div class="section-header text-center mb-5" data-aos="fade-up">
            <div class="section-badge-wrap">
                <span class="section-badge"><i class="lucide-icon" aria-hidden="true" data-lucide="link"></i> <?php echo $L['important_links']; ?></span>
            </div>
            <h2><?php echo isEnglish() ? 'Important Resources' : 'महत्त्वपूर्ण स्रोतहरू'; ?></h2>
            <div class="section-divider"></div>
            <p><?php echo isEnglish() ? 'Useful links to government and regulatory bodies' : 'सरकारी र नियामक निकायहरूको उपयोगी लिंकहरू'; ?></p>
        </div>

        <div class="row">
            <!-- Nepal Rastra Bank -->
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="links-card">
                    <div class="link-icon">
                        <img src="https://www.nrb.org.np/images/nrb_logo.png" alt="NRB" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2280%22>🏛️</text></svg>'">
                    </div>
                    <h5><?php echo isEnglish() ? 'Nepal Rastra Bank' : 'नेपाल राष्ट्र बैंक'; ?></h5>
                    <p><?php echo isEnglish() ? 'Central Bank of Nepal' : 'नेपालको केन्द्रीय बैंक'; ?></p>
                    <a href="https://www.nrb.org.np" target="_blank">
                        <?php echo isEnglish() ? 'Visit Website' : 'वेबसाइट हेर्नुहोस्'; ?> <i class="fas fa-external-link-alt"></i>
                    </a>
                </div>
            </div>

            <!-- Department of Cooperatives -->
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="links-card">
                    <div class="link-icon">
                        <img src="https://doc.gov.np/images/logo.png" alt="DOC" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2280%22>🏛️</text></svg>'">
                    </div>
                    <h5><?php echo isEnglish() ? 'Department of Cooperatives' : 'सहकारी विभाग'; ?></h5>
                    <p><?php echo isEnglish() ? 'Government of Nepal' : 'नेपाल सरकार'; ?></p>
                    <a href="https://doc.gov.np" target="_blank">
                        <?php echo isEnglish() ? 'Visit Website' : 'वेबसाइट हेर्नुहोस्'; ?> <i class="fas fa-external-link-alt"></i>
                    </a>
                </div>
            </div>

            <!-- National Cooperative Federation -->
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="links-card">
                    <div class="link-icon">
                        <img src="https://ncfnepal.com.np/images/logo.png" alt="NCF" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2280%22>🏛️</text></svg>'">
                    </div>
                    <h5><?php echo isEnglish() ? 'National Cooperative Federation' : 'राष्ट्रिय सहकारी महासंघ'; ?></h5>
                    <p><?php echo isEnglish() ? 'Nepal' : 'नेपाल'; ?></p>
                    <a href="https://ncfnepal.com.np" target="_blank">
                        <?php echo isEnglish() ? 'Visit Website' : 'वेबसाइट हेर्नुहोस्'; ?> <i class="fas fa-external-link-alt"></i>
                    </a>
                </div>
            </div>

            <!-- Nepal Government Portal -->
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="links-card">
                    <div class="link-icon">
                        <img src="https://nepal.gov.np/images/logo.png" alt="Nepal Gov" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2280%22>🇳🇵</text></svg>'">
                    </div>
                    <h5><?php echo isEnglish() ? 'Nepal Government Portal' : 'नेपाल सरकार पोर्टल'; ?></h5>
                    <p><?php echo isEnglish() ? 'Official Government Website' : 'आधिकारिक सरकारी वेबसाइट'; ?></p>
                    <a href="https://nepal.gov.np" target="_blank">
                        <?php echo isEnglish() ? 'Visit Website' : 'वेबसाइट हेर्नुहोस्'; ?> <i class="fas fa-external-link-alt"></i>
                    </a>
                </div>
            </div>

            <!-- Deposit and Credit Guarantee Fund -->
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="links-card">
                    <div class="link-icon">
                        <img src="https://dcgf.org.np/images/logo.png" alt="DCGF" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2280%22>💰</text></svg>'">
                    </div>
                    <h5><?php echo isEnglish() ? 'Deposit & Credit Guarantee Fund' : 'निक्षेप तथा कर्जा सुरक्षण कोष'; ?></h5>
                    <p><?php echo isEnglish() ? 'DCGF Nepal' : 'DCGF नेपाल'; ?></p>
                    <a href="https://dcgf.org.np" target="_blank">
                        <?php echo isEnglish() ? 'Visit Website' : 'वेबसाइट हेर्नुहोस्'; ?> <i class="fas fa-external-link-alt"></i>
                    </a>
                </div>
            </div>

            <!-- Cooperative Training Center -->
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="links-card">
                    <div class="link-icon">
                        <img src="https://ctc.gov.np/images/logo.png" alt="CTC" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2280%22>📚</text></svg>'">
                    </div>
                    <h5><?php echo isEnglish() ? 'Cooperative Training Center' : 'सहकारी तालिम केन्द्र'; ?></h5>
                    <p><?php echo isEnglish() ? 'Training & Development' : 'तालिम तथा विकास'; ?></p>
                    <a href="https://ctc.gov.np" target="_blank">
                        <?php echo isEnglish() ? 'Visit Website' : 'वेबसाइट हेर्नुहोस्'; ?> <i class="fas fa-external-link-alt"></i>
                    </a>
                </div>
            </div>

            <!-- Ministry of Land Management -->
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="links-card">
                    <div class="link-icon">
                        <img src="https://molmac.gov.np/images/logo.png" alt="MOLMAC" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2280%22>🏛️</text></svg>'">
                    </div>
                    <h5><?php echo isEnglish() ? 'Ministry of Land Management' : 'भूमि व्यवस्था मन्त्रालय'; ?></h5>
                    <p><?php echo isEnglish() ? 'Agriculture & Cooperatives' : 'कृषि तथा सहकारी'; ?></p>
                    <a href="https://molmac.gov.np" target="_blank">
                        <?php echo isEnglish() ? 'Visit Website' : 'वेबसाइट हेर्नुहोस्'; ?> <i class="fas fa-external-link-alt"></i>
                    </a>
                </div>
            </div>

            <!-- Securities Board of Nepal -->
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="links-card">
                    <div class="link-icon">
                        <img src="https://sebon.gov.np/images/logo.png" alt="SEBON" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2280%22>📈</text></svg>'">
                    </div>
                    <h5><?php echo isEnglish() ? 'Securities Board of Nepal' : 'नेपाल धितोपत्र बोर्ड'; ?></h5>
                    <p><?php echo isEnglish() ? 'SEBON' : 'सेबोन'; ?></p>
                    <a href="https://sebon.gov.np" target="_blank">
                        <?php echo isEnglish() ? 'Visit Website' : 'वेबसाइट हेर्नुहोस्'; ?> <i class="fas fa-external-link-alt"></i>
                    </a>
                </div>
            </div>

            <!-- Insurance Board -->
            <div class="col-lg-4 col-md-6 mb-4">
                <div class="links-card">
                    <div class="link-icon">
                        <img src="https://nib.gov.np/images/logo.png" alt="NIB" onerror="this.src='data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2280%22>🛡️</text></svg>'">
                    </div>
                    <h5><?php echo isEnglish() ? 'Insurance Board Nepal' : 'बीमा समिति नेपाल'; ?></h5>
                    <p><?php echo isEnglish() ? 'Insurance Regulator' : 'बीमा नियामक'; ?></p>
                    <a href="https://nib.gov.np" target="_blank">
                        <?php echo isEnglish() ? 'Visit Website' : 'वेबसाइट हेर्नुहोस्'; ?> <i class="fas fa-external-link-alt"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once 'includes/footer.php'; ?>
