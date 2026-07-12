<?php
// Get footer settings
$footerText = getSetting('footer_text', '© ' . date('Y') . ' आकाश सहकारी। सर्वाधिकार सुरक्षित।');
$aboutShort = getSetting('about_short', 'आकाश बचत तथा ऋण सहकारी संस्था लि. एक अग्रणी वित्तीय संस्था हो।');
$developerName = getSetting('developer_name', 'Tanka Adhikari');
$developerUrl = getSetting('developer_url', 'https://www.tankaadhikari.com.np/');
$supportedName = trim((string)getSetting('supported_name', ''));
$supportedUrl = trim((string)getSetting('supported_url', ''));
$whatsappNumber = getSetting('whatsapp_number', '');
$workingHours = getSetting('working_hours', 'आइतबार - शुक्रबार: बिहान १०:०० - साँझ ५:००');

// Track and get visitor count - with safe table checks
$totalVisitors = 0;
$todayVisitors = 0;
try {
    $db = getDB();
    $visitorIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $today = date('Y-m-d');

    /* visitor_counter table check — fetch() प्रयोग गर्नुहोस् (rowCount() unreliable) */
    $tableCheck = $db->query("SHOW TABLES LIKE 'visitor_counter'");
    if ($tableCheck && $tableCheck->fetch() !== false) {
        $checkStmt = $db->prepare("SELECT id FROM visitor_counter WHERE ip_address = ? AND visit_date = ?");
        $checkStmt->execute([$visitorIp, $today]);

        if (!$checkStmt->fetch()) {
            $insertStmt = $db->prepare("INSERT INTO visitor_counter (ip_address, user_agent, page_visited, visit_date) VALUES (?, ?, ?, ?)");
            $insertStmt->execute([$visitorIp, $_SERVER['HTTP_USER_AGENT'] ?? '', $_SERVER['REQUEST_URI'] ?? '/', $today]);

            $statsCheck = $db->query("SHOW TABLES LIKE 'site_stats'");
            if ($statsCheck && $statsCheck->fetch() !== false) {
                $db->query("UPDATE site_stats SET stat_value = stat_value + 1 WHERE stat_key = 'total_visitors'");
            }
        }

        $todayStmt = $db->prepare("SELECT COUNT(DISTINCT ip_address) as count FROM visitor_counter WHERE visit_date = ?");
        $todayStmt->execute([$today]);
        $todayRow = $todayStmt->fetch();
        $todayVisitors = $todayRow['count'] ?? 0;
    }

    /* site_stats total visitors */
    $statsCheck = $db->query("SHOW TABLES LIKE 'site_stats'");
    if ($statsCheck && $statsCheck->fetch() !== false) {
        $totalStmt = $db->query("SELECT stat_value FROM site_stats WHERE stat_key = 'total_visitors'");
        if ($totalStmt) {
            $totalRow = $totalStmt->fetch();
            $totalVisitors = $totalRow['stat_value'] ?? 0;
        }
    }

} catch (Exception $e) {
    // Table might not exist yet - use defaults
    $totalVisitors = 0;
    $todayVisitors = 0;
}

// Get latest notices for footer - with safe query
$footerNotices = [];
try {
    $db = getDB();
    $noticesStmt = $db->query("SELECT id, title, notice_date FROM notices WHERE is_active = 1 ORDER BY id DESC LIMIT 3");
    if ($noticesStmt) $footerNotices = $noticesStmt->fetchAll() ?: [];
} catch (Exception $e) {
    $footerNotices = [];
}

// Get useful links for footer - with safe table check
$usefulLinks = [];
try {
    $db = getDB();
    /* useful_links table check — fetch() प्रयोग गर्नुहोस् */
    $tableCheck = $db->query("SHOW TABLES LIKE 'useful_links'");
    if ($tableCheck && $tableCheck->fetch() !== false) {
        $usefulLinksStmt = $db->query("SELECT * FROM useful_links WHERE is_active = 1 ORDER BY display_order ASC LIMIT 6");
        if ($usefulLinksStmt) $usefulLinks = $usefulLinksStmt->fetchAll() ?: [];
    }
} catch (Exception $e) {
    $usefulLinks = [];
}
?>

    <!-- Footer Section -->
    <footer class="main-footer">
        <div class="footer-top">
            <div class="container">
                <div class="row">
                    <!-- About Section -->
                    <div class="col-lg-4 col-md-6 mb-4">
                        <div class="footer-widget">
                            <!-- Footer Logo: logo तल name राखिएको छ (issue #4) -->
                                <?php
                                $footerLogo = function_exists('getLocalizedLogoPath')
                                    ? trim((string) getLocalizedLogoPath('assets/images/logo.png'))
                                    : trim((string) getSetting('site_logo', getSetting('logo', 'assets/images/logo.png')));
                                ?>
                        <div class="footer-logo footer-logo-stacked <?php echo !empty($footerLogo) ? 'has-logo' : 'no-logo'; ?>">
                <!-- Logo image — logo भए नाम नदेखाउने -->
                <?php if (!empty($footerLogo)): ?>
                <img src="<?php echo SITE_URL . $footerLogo; ?>" alt="<?php echo $siteName ?? 'आकाश सहकारी'; ?>" onerror="this.style.display='none'">
                <?php else: ?>
                                <span class="footer-sahakari-name"><?php echo $siteName ?? 'आकाश सहकारी'; ?></span>
                <?php endif; ?>
                            </div>
                            <p><?php echo $aboutShort; ?></p>
                            <div class="footer-social">
                                <a href="<?php echo $facebookUrl ?? '#'; ?>" title="Facebook"><i class="fab fa-facebook-f"></i></a>
                                <a href="<?php echo $youtubeUrl ?? '#'; ?>" title="YouTube"><i class="fab fa-youtube"></i></a>
                                <a href="mailto:<?php echo $email ?? ''; ?>" title="Email"><i class="fas fa-envelope"></i></a>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Links - 2 columns -->
                    <div class="col-lg-5 col-md-6 mb-4">
                        <div class="footer-widget">
                            <h4><?php echo isEnglish() ? 'Quick Links' : 'द्रुत लिंकहरू'; ?></h4>
                            <div class="row">
                                <div class="col-6">
                                    <ul class="footer-links">
                                        <li><a href="<?php echo SITE_URL; ?>about.php"><?php echo isEnglish() ? 'About Us' : 'हाम्रो बारेमा'; ?></a></li>
                                        <li><a href="<?php echo SITE_URL; ?>services.php"><?php echo isEnglish() ? 'Services' : 'सेवाहरू'; ?></a></li>
                                        <li><a href="<?php echo SITE_URL; ?>service-centers.php"><?php echo isEnglish() ? 'Service Centers' : 'सेवा कार्यालयहरू'; ?></a></li>
                                        <li><a href="<?php echo SITE_URL; ?>news.php"><?php echo isEnglish() ? 'News' : 'समाचार'; ?></a></li>
                                    </ul>
                                </div>
                                <div class="col-6">
                                    <ul class="footer-links">
                                        <li><a href="<?php echo SITE_URL; ?>career.php"><?php echo isEnglish() ? 'Careers' : 'बिज्ञापन'; ?></a></li>
                                        <li><a href="<?php echo SITE_URL; ?>reports.php"><?php echo isEnglish() ? 'Reports' : 'प्रतिवेदन'; ?></a></li>
                                        <li><a href="<?php echo SITE_URL; ?>faqs.php"><?php echo isEnglish() ? 'FAQs' : 'प्रश्नोत्तर'; ?></a></li>
                                        <li><a href="<?php echo SITE_URL; ?>member-survey.php"><?php echo isEnglish() ? 'Suggestion Box' : 'सुझाव बक्स'; ?></a></li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Contact Info -->
                    <div class="col-lg-3 col-md-6 mb-4">
                        <div class="footer-widget">
                            <h4><?php echo isEnglish() ? 'Contact Info' : 'सम्पर्क जानकारी'; ?></h4>
                            <ul class="footer-contact">
                                <li>
                                    <i class="fas fa-map-marker-alt"></i>
                                    <span><?php echo $address ?? 'काठमाडौं, नेपाल'; ?></span>
                                </li>
                                <li>
                                    <i class="fas fa-phone-alt"></i>
                                    <span><?php echo $phone ?? '061590067'; ?></span>
                                </li>
                                <li>
                                    <i class="fas fa-mobile-alt"></i>
                                    <span><?php echo $mobile ?? '9827157000'; ?></span>
                                </li>
                                <li>
                                    <i class="fas fa-envelope"></i>
                                    <span><?php echo $email ?? 'info@sahakari.org.np'; ?></span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>


            </div>
        </div>

        <!-- Footer Bottom -->
        <div class="footer-bottom">
            <div class="container">
                <p class="copyright">
                    <i class="lucide-icon" aria-hidden="true" data-lucide="copyright"></i>
                    <?php echo $footerText; ?>
                </p>

                <!-- v10.3 (Issue #10): Footer policy links — admin बाट pages.php मा edit गर्न मिल्छ -->
                <div class="footer-policy-links">
                        <a href="<?php echo SITE_URL; ?>page.php?slug=privacy-policy">
                        <i class="lucide-icon" aria-hidden="true" data-lucide="shield"></i>
                        <?php echo isEnglish() ? 'Privacy Policy' : 'गोपनीयता नीति'; ?>
                    </a>
                    <span class="footer-policy-dot">•</span>
                    <a href="<?php echo SITE_URL; ?>page.php?slug=terms-of-service">
                        <i class="lucide-icon" aria-hidden="true" data-lucide="file-text"></i>
                        <?php echo isEnglish() ? 'Terms of Service' : 'सेवाका सर्तहरू'; ?>
                    </a>
                    <span class="footer-policy-dot">•</span>
                    <a href="<?php echo SITE_URL; ?>page.php?slug=cookie-policy">
                        <i class="lucide-icon" aria-hidden="true" data-lucide="cookie"></i>
                        <?php echo isEnglish() ? 'Cookie Policy' : 'कुकी नीति'; ?>
                    </a>
                </div>

                <div class="footer-metrics" id="footerMetrics">
                    <button type="button" class="footer-metrics-toggle" id="footerMetricsToggle" title="<?php echo isEnglish() ? 'Visitor stats' : 'भ्रमण तथ्यांक'; ?>">
                        <i class="lucide-icon" aria-hidden="true" data-lucide="chart-column"></i>
                    </button>
                    <div class="footer-metrics-popup" id="footerMetricsPopup" aria-hidden="true">
                        <div class="footer-metrics-heading">
                            <span><?php echo isEnglish() ? 'Visitor Stats' : 'भ्रमण तथ्यांक'; ?></span>
                            <button type="button" class="footer-metrics-close" id="footerMetricsClose" aria-label="<?php echo isEnglish() ? 'Close' : 'बन्द गर्नुहोस्'; ?>">
                                <i class="lucide-icon" aria-hidden="true" data-lucide="x"></i>
                            </button>
                        </div>
                        <div class="visitor-counter">
                            <div class="visitor-item" title="<?php echo isEnglish() ? 'Total Visitors' : 'कुल भ्रमणकर्ता'; ?>">
                                <i class="lucide-icon" aria-hidden="true" data-lucide="users"></i>
                                <span><?php echo number_format($totalVisitors); ?></span>
                            </div>
                            <div class="visitor-item today" title="<?php echo isEnglish() ? "Today's Visitors" : 'आजका भ्रमणकर्ता'; ?>">
                                <i class="fas fa-user-clock"></i>
                                <span><?php echo number_format($todayVisitors); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($supportedName !== ''): ?>
                <p class="developer footer-developer-supported">
                    <?php echo isEnglish() ? 'Supported By' : 'समर्थन'; ?>
                    <?php if ($supportedUrl !== ''): ?>
                    <a href="<?php echo htmlspecialchars($supportedUrl, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($supportedName, ENT_QUOTES, 'UTF-8'); ?></a>
                    <?php else: ?>
                    <span><?php echo htmlspecialchars($supportedName, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                </p>
                <?php endif; ?>

                <p class="developer footer-developer-main">
                    <?php echo isEnglish() ? 'Developed By' : 'विकास सहयोग'; ?>
                    <a href="<?php echo $developerUrl; ?>" target="_blank" rel="noopener"><?php echo $developerName; ?></a>
                </p>
            </div>
        </div>
    </footer>

    <!-- WhatsApp Floating Button -->
    <?php if (!empty($whatsappNumber)): ?>
    <a href="https://wa.me/<?php echo $whatsappNumber; ?>" class="whatsapp-float" target="_blank" title="WhatsApp मा सम्पर्क गर्नुहोस्">
        <i class="fab fa-whatsapp"></i>
    </a>
    <?php endif; ?>

    <!-- Useful Links Floating Button -->
    <?php if (!empty($usefulLinks)): ?>
    <div class="useful-links-float" id="usefulLinksFloat">
        <button type="button" class="useful-links-toggle" id="usefulLinksToggle" title="<?php echo isEnglish() ? 'Useful Links' : 'उपयोगी लिंकहरू'; ?>">
            <i class="fas fa-external-link-alt"></i>
        </button>

        <div class="useful-links-popup-box" id="usefulLinksBox">
                <div class="useful-links-header">
                    <h5><i class="lucide-icon" aria-hidden="true" data-lucide="link"></i> <?php echo isEnglish() ? 'Useful Links' : 'उपयोगी लिंकहरू'; ?></h5>
                <button type="button" class="useful-links-close" id="usefulLinksClose" aria-label="<?php echo isEnglish() ? 'Close' : 'बन्द गर्नुहोस्'; ?>"><i class="fas fa-times"></i></button>
            </div>
            <div class="useful-links-body">
                <?php foreach ($usefulLinks as $link): ?>
                <a href="<?php echo $link['url']; ?>"
                   class="useful-link-row"
                   target="_blank">
                    <i class="lucide-icon" aria-hidden="true" data-lucide="link"></i>
                    <span><?php echo isEnglish() ? ($link['title'] ?? $link['title_np']) : ($link['title_np'] ?? $link['title']); ?></span>
                    <i class="fas fa-external-link-alt link-arrow"></i>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Chatbot / FAQ Widget -->
    <?php
    // Get chatbot FAQs - with safe table check
    $chatbotFaqs = [];
    try {
        $db = getDB();
        /* chatbot_faqs table check — fetch() reliable, rowCount() होइन */
        $tableCheck = $db->query("SHOW TABLES LIKE 'chatbot_faqs'");
        if ($tableCheck && $tableCheck->fetch() !== false) {
            $faqsStmt = $db->query("SELECT * FROM chatbot_faqs WHERE is_active = 1 ORDER BY display_order");
            if ($faqsStmt) $chatbotFaqs = $faqsStmt->fetchAll() ?: [];
        }
    } catch (Exception $e) {
        $chatbotFaqs = [];
    }
    ?>
    <div class="chatbot-widget" id="chatbotWidget">
        <div class="chatbot-toggle" id="chatbotToggle" title="<?php echo isEnglish() ? 'Help & FAQ' : 'सहायता र प्रश्नोत्तर'; ?>">
            <i class="fas fa-comments"></i>
            <span class="chatbot-badge">?</span>
        </div>

        <div class="chatbot-box" id="chatbotBox">
            <div class="chatbot-header">
                <div class="chatbot-title">
                    <i class="fas fa-robot"></i>
                    <span><?php echo isEnglish() ? 'Help Assistant' : 'सहायता केन्द्र'; ?></span>
                </div>
                <button class="chatbot-close" id="chatbotClose" aria-label="Close" title="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>

            <div class="chatbot-body" id="chatbotBody">
                <div class="chatbot-welcome">
                    <p><?php echo isEnglish() ? 'Hello! How can I help you today?' : 'नमस्कार! आज म तपाईंलाई कसरी मद्दत गर्न सक्छु?'; ?></p>
                </div>

                <div class="chatbot-quick-actions">
                    <a href="<?php echo SITE_URL; ?>appointment.php" class="quick-action-btn">
                        <i class="lucide-icon" aria-hidden="true" data-lucide="calendar-check"></i>
                        <?php echo isEnglish() ? 'Book Appointment' : 'भेटघाट बुक गर्नुहोस्'; ?>
                    </a>
                    <a href="<?php echo SITE_URL; ?>online-account.php" class="quick-action-btn">
                        <i class="lucide-icon" aria-hidden="true" data-lucide="user-plus"></i>
                        <?php echo isEnglish() ? 'Open Account' : 'खाता खोल्नुहोस्'; ?>
                    </a>
                    <a href="<?php echo SITE_URL; ?>grievance.php" class="quick-action-btn">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo isEnglish() ? 'File Grievance' : 'गुनासो दर्ता'; ?>
                    </a>
                    <!-- ट्र्याकर एप्लिकेसन button — अरू buttons जस्तै style -->
                    <a href="<?php echo SITE_URL; ?>application-tracker.php" class="quick-action-btn" target="_blank">
                        <i class="fas fa-magnifying-glass-chart"></i>
                        <?php echo isEnglish() ? 'Track Application' : 'ट्र्याकर एप्लिकेसन'; ?>
                    </a>
                </div>

                <div class="chatbot-faqs">
                    <h6><?php echo isEnglish() ? 'Frequently Asked Questions' : 'बारम्बार सोधिने प्रश्नहरू'; ?></h6>
                    <?php foreach ($chatbotFaqs as $faq): ?>
                    <div class="faq-item">
                        <div class="faq-question" onclick="toggleFaq(this)">
                            <span><?php echo isEnglish() ? $faq['question_en'] : $faq['question']; ?></span>
                            <i class="lucide-icon" aria-hidden="true" data-lucide="chevron-down"></i>
                        </div>
                        <div class="faq-answer">
                            <?php echo isEnglish() ? $faq['answer_en'] : $faq['answer']; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="chatbot-contact">
                    <p><?php echo isEnglish() ? 'Need more help?' : 'थप सहायता चाहिन्छ?'; ?></p>
                    <a href="<?php echo SITE_URL; ?>contact.php" class="btn btn-primary btn-sm">
                        <i class="fas fa-phone"></i> <?php echo isEnglish() ? 'Contact Us' : 'सम्पर्क गर्नुहोस्'; ?>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Chatbot toggle
    document.getElementById('chatbotToggle').addEventListener('click', function() {
        var box = document.getElementById('chatbotBox');
        if (!box) return;
        box.classList.toggle('active');
        document.body.classList.toggle('chatbot-open', box.classList.contains('active'));
    });

    document.getElementById('chatbotClose').addEventListener('click', function() {
        var box = document.getElementById('chatbotBox');
        if (!box) return;
        box.classList.remove('active');
        document.body.classList.remove('chatbot-open');
    });

    // Prevent background scroll on iOS/Android while scrolling inside chatbot
    (function(){
        var box = document.getElementById('chatbotBox');
        var body = document.getElementById('chatbotBody');
        if (!box || !body) return;
        box.addEventListener('touchmove', function(e){
            if (box.classList.contains('active') && !e.target.closest('#chatbotBody')) {
                e.preventDefault();
            }
        }, { passive: false });
    })();

    // FAQ toggle
    function toggleFaq(element) {
        element.parentElement.classList.toggle('active');
    }

    // Useful Links — open/close (Quick-Help launcher सँग conflict नहोस्)
    (function () {
        var usefulLinksToggle = document.getElementById('usefulLinksToggle');
        var usefulLinksBox = document.getElementById('usefulLinksBox');
        var usefulLinksClose = document.getElementById('usefulLinksClose');
        var usefulLinksFloat = document.getElementById('usefulLinksFloat');

        var footerMetricsToggle = document.getElementById('footerMetricsToggle');
        var footerMetricsPopup = document.getElementById('footerMetricsPopup');
        var footerMetricsClose = document.getElementById('footerMetricsClose');

        function openFooterMetrics() {
            if (footerMetricsPopup) {
                footerMetricsPopup.classList.add('active');
                footerMetricsPopup.setAttribute('aria-hidden', 'false');
            }
        }
        function closeFooterMetrics() {
            if (footerMetricsPopup) {
                footerMetricsPopup.classList.remove('active');
                footerMetricsPopup.setAttribute('aria-hidden', 'true');
            }
        }

        function openUsefulLinks() {
            if (usefulLinksBox) usefulLinksBox.classList.add('active');
        }
        function closeUsefulLinks() {
            if (usefulLinksBox) usefulLinksBox.classList.remove('active');
        }
        window.coopOpenUsefulLinks = openUsefulLinks;
        window.coopCloseUsefulLinks = closeUsefulLinks;

        if (usefulLinksToggle) {
            usefulLinksToggle.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (usefulLinksBox) usefulLinksBox.classList.toggle('active');
            });
        }
        if (footerMetricsToggle) {
            footerMetricsToggle.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (footerMetricsPopup) {
                    footerMetricsPopup.classList.toggle('active');
                    footerMetricsPopup.setAttribute('aria-hidden', footerMetricsPopup.classList.contains('active') ? 'false' : 'true');
                }
            });
        }
        if (footerMetricsClose) {
            footerMetricsClose.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                closeFooterMetrics();
            });
        }
        if (usefulLinksClose) {
            usefulLinksClose.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                closeUsefulLinks();
            });
        }
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') {
                closeUsefulLinks();
                closeFooterMetrics();
            }
        });
        document.addEventListener('click', function (e) {
            if (usefulLinksBox && usefulLinksBox.classList.contains('active')) {
                var qh = document.getElementById('qhLauncher');
                if (!(qh && qh.contains(e.target)) && usefulLinksFloat && !usefulLinksFloat.contains(e.target)) {
                    closeUsefulLinks();
                }
            }
            if (footerMetricsPopup && footerMetricsPopup.classList.contains('active')) {
                if (footerMetricsToggle && !footerMetricsToggle.contains(e.target) && !footerMetricsPopup.contains(e.target)) {
                    closeFooterMetrics();
                }
            }
        });
    })();
    </script>

    <!-- Site Search Modal — Voice + Type search -->
    <div class="search-modal" id="searchModal">
        <div class="search-modal-overlay"></div>
        <div class="search-modal-content">
            <button class="search-modal-close" id="searchModalClose" title="बन्द गर्नुहोस्">
                <i class="fas fa-times"></i>
            </button>
            <div class="search-modal-body">

                <!-- Animated icon ring: search / mic state -->
                <div class="smb-icon-ring" id="smbIconRing">
                    <i class="lucide-icon" aria-hidden="true" data-lucide="search"></i>
                    <i class="fas fa-microphone" id="smbIconMic" style="display:none;"></i>
                </div>

                <!-- Title changes while listening -->
                <h3 class="smb-title" id="smbTitle">
                    <?php echo isEnglish() ? 'Search' : 'खोज्नुहोस्'; ?>
                </h3>
                <p class="smb-subtitle" id="smbSubtitle">
                    <?php echo isEnglish()
                        ? 'Type below or tap the mic to speak'
                        : 'टाइप गर्नुहोस् वा माइकबाट बोल्नुहोस्'; ?>
                </p>

                <form action="<?php echo SITE_URL; ?>search.php" method="GET" class="search-form" id="searchForm">
                    <div class="search-input-wrapper" id="searchInputWrapper">
                        <!-- Search text field -->
                        <input type="text" name="q" class="search-input" id="searchInput"
                               placeholder="<?php echo isEnglish() ? 'Type to search...' : 'यहाँ टाइप गर्नुहोस्...'; ?>"
                               autocomplete="off" autofocus>

                        <!-- Mic button: JS ले insert गर्छ — यहाँ placeholder div -->
                        <div id="voiceBtnSlot"></div>

                        <button type="submit" class="search-submit" id="searchSubmitBtn" title="<?php echo isEnglish() ? 'Search' : 'खोज्नुहोस्'; ?>">
                            <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>

                    <!-- Voice listening status bar -->
                    <div class="voice-status-bar" id="voiceStatusBar" style="display:none;">
                        <span class="vsb-waves">
                            <span></span><span></span><span></span><span></span><span></span>
                        </span>
                        <span class="vsb-text" id="voiceStatusText">
                            <?php echo isEnglish() ? 'Listening… speak now' : 'सुन्दैछ… बोल्नुहोस्'; ?>
                        </span>
                        <button type="button" class="vsb-stop" id="voiceStopBtn" title="रोक्नुहोस्">
                            <i class="fas fa-stop-circle"></i>
                            <?php echo isEnglish() ? 'Stop' : 'रोक्नुहोस्'; ?>
                        </button>
                    </div>

                    <!-- Voice error message -->
                    <div class="voice-error-bar" id="voiceErrorBar" style="display:none;">
                        <i class="fas fa-exclamation-circle me-1"></i>
                        <span id="voiceErrorText"></span>
                    </div>
                </form>

                <!-- Standalone large mic button — always visible -->
                <button type="button" class="smb-voice-big" id="smbVoiceBig" title="<?php echo isEnglish() ? 'Voice Search' : 'आवाजबाट खोज्नुहोस्'; ?>">
                    <i class="fas fa-microphone"></i>
                    <span><?php echo isEnglish() ? 'Voice Search' : 'आवाजबाट खोज्नुहोस्'; ?></span>
                </button>

                <!-- Popular quick links -->
                <div class="search-quick-links">
                    <span><?php echo isEnglish() ? 'Popular:' : 'लोकप्रिय:'; ?></span>
                    <a href="<?php echo SITE_URL; ?>services.php"><?php echo isEnglish() ? 'Services' : 'सेवाहरू'; ?></a>
                    <a href="<?php echo SITE_URL; ?>interest-rates.php"><?php echo isEnglish() ? 'Interest Rates' : 'ब्याजदर'; ?></a>
                    <a href="<?php echo SITE_URL; ?>online-account.php"><?php echo isEnglish() ? 'Open Account' : 'खाता खोल्नुहोस्'; ?></a>
                    <a href="<?php echo SITE_URL; ?>career.php"><?php echo isEnglish() ? 'Careers' : 'बिज्ञापन'; ?></a>
                    <a href="<?php echo SITE_URL; ?>loan-apply.php"><?php echo isEnglish() ? 'Loan' : 'ऋण आवेदन'; ?></a>
                </div>
            </div>
        </div>
    </div>

    <script>
    // Search Modal Handler
    (function() {
        var searchBtn = document.getElementById('headerSearchBtn');
        var topbarSearchBtn = document.getElementById('topbarSearchBtn');
        var searchModal = document.getElementById('searchModal');
        var searchClose = document.getElementById('searchModalClose');
        var searchInput = document.getElementById('searchInput');
        var searchOverlay = searchModal ? searchModal.querySelector('.search-modal-overlay') : null;

        function openSearchModal(e) {
            if (e) e.preventDefault();
            if (searchModal) {
                searchModal.classList.add('active');
                document.body.style.overflow = 'hidden';
                if (searchInput) {
                    setTimeout(function() { searchInput.focus(); }, 100);
                }
            }
        }

        function closeSearchModal() {
            if (searchModal) {
                searchModal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        if (searchBtn) {
            searchBtn.addEventListener('click', openSearchModal);
        }

        // Topbar search button
        if (topbarSearchBtn) {
            topbarSearchBtn.addEventListener('click', openSearchModal);
        }

        if (searchClose) {
            searchClose.addEventListener('click', closeSearchModal);
        }

        if (searchOverlay) {
            searchOverlay.addEventListener('click', closeSearchModal);
        }

        // Close on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && searchModal && searchModal.classList.contains('active')) {
                closeSearchModal();
            }
        });
    })();

    // Dark Mode Toggle Handler
    (function() {
        var darkModeToggle = document.getElementById('darkModeToggle');
        var topbarDarkModeToggle = document.getElementById('topbarDarkModeToggle');
        var body = document.body;
        var storageKey = 'darkModeEnabled';

        // Check for saved preference
        var savedMode = localStorage.getItem(storageKey);
        if (savedMode === 'true') {
            body.classList.add('dark-mode');
            updateDarkModeIcon(true);
        }

        function toggleDarkMode(e) {
            if (e) e.preventDefault();
            body.classList.toggle('dark-mode');
            var isDark = body.classList.contains('dark-mode');
            localStorage.setItem(storageKey, isDark);
            updateDarkModeIcon(isDark);
        }

        function updateDarkModeIcon(isDark) {
            var topbarIcon = topbarDarkModeToggle ? topbarDarkModeToggle.querySelector('i') : null;
            if (topbarIcon) {
                topbarIcon.className = isDark ? 'fas fa-sun' : 'fas fa-moon';
            }
        }

        if (darkModeToggle) {
            darkModeToggle.addEventListener('click', toggleDarkMode);
        }

        if (topbarDarkModeToggle) {
            topbarDarkModeToggle.addEventListener('click', toggleDarkMode);
        }
    })();
    </script>

    </div>

    <!-- Bootstrap JS -->
    <script src="assets/vendor/bootstrap.bundle.min.js"></script>

    <!-- jQuery (required for Nepali Datepicker) -->
    <script src="assets/vendor/jquery.min.js"></script>

    <!-- Nepali Datepicker JS v5 (self-hosted) -->
    <script src="<?php echo SITE_URL; ?>assets/js/nepali.datepicker.min.js"></script>
    <script>
    /* ─── Nepali Datepicker v5 initialize function ─── */
    function initNepaliDatePicker(selector) {
        if (typeof $ === 'undefined' || typeof $.fn.nepaliDatePicker === 'undefined') return;
        $(selector).each(function() {
            var $inp = $(this);
            if ($inp.data('ndp-ready')) return;
            $inp.data('ndp-ready', true);
            $inp.nepaliDatePicker({
                dateFormat : 'YYYY-MM-DD',
                language   : 'nepali'
            });
            /* Calendar icon button छ भने click गर्दा datepicker खुल्छ */
            $inp.closest('.input-group, .nepali-datepicker-wrapper')
                .find('.input-group-text, .ndp-trigger')
                .off('click.ndp').on('click.ndp', function() { $inp.trigger('focus'); });
        });
    }

    $(document).ready(function() {
        initNepaliDatePicker('.nepali-datepicker');
    });
    </script>

    <!-- AOS Animation JS -->
    <script src="assets/vendor/aos.min.js"></script>
    <script>
        /* AOS — existing data-aos attributes का लागि */
        AOS.init({
            duration: 700,
            easing: 'ease-out-cubic',
            once: true,
            mirror: false,
            offset: 60,
            delay: 0
        });
    </script>

    <!-- Global Scroll Animation — सबै pages मा automatic -->
    <script>
    (function() {
        /* ─────────────────────────────────────────────────────
           GLOBAL SCROLL REVEAL
           यो script ले हरेक page को common elements मा
           scroll गर्दा animation automatically थप्छ।

           नयाँ element थप्न: SELECTORS array मा class थप्नुहोस्
           ───────────────────────────────────────────────────── */

        /* --- Animate हुने elements को list ---
           (data-aos भएका elements skip हुन्छन्) */
        var SELECTORS = [
            /* Cards */
            '.card:not([data-aos])',
            /* Page sections / boxes */
            '.stat-card:not([data-aos])',
            '.form-section:not([data-aos])',
            '.info-card:not([data-aos])',
            '.news-card:not([data-aos])',
            '.service-item:not([data-aos])',
            '.notice-item:not([data-aos])',
            '.team-member:not([data-aos])',
            '.committee-member:not([data-aos])',
            '.award-item:not([data-aos])',
            '.download-item:not([data-aos])',
            '.faq-item:not([data-aos])',
            '.career-card:not([data-aos])',
            '.gallery-item:not([data-aos])',
            '.report-card:not([data-aos])',
            '.link-card:not([data-aos])',
            '.auction-card:not([data-aos])',
            /* Section headers */
            '.section-header:not([data-aos])',
            '.page-header:not([data-aos])',
            /* Tables */
            '.table-responsive:not([data-aos])',
            /* Claim / form boxes */
            '.claim-form-card:not([data-aos])',
            '.vendor-form-card:not([data-aos])',
            '.contact-form-box:not([data-aos])',
        ];

        /* --- Animation types — element type अनुसार ---
           key = selector keyword, value = animation class */
        var ANIMATION_MAP = {
            'section-header' : 'sr-fade-up',
            'page-header'    : 'sr-fade-up',
            'stat-card'      : 'sr-zoom-in',
            'gallery-item'   : 'sr-zoom-in',
            'form-section'   : 'sr-fade-left',
            'table-responsive': 'sr-fade-up',
            '_default'       : 'sr-fade-up',
        };

        function getAnimationClass(el) {
            for (var key in ANIMATION_MAP) {
                if (key !== '_default' && el.classList.contains(key)) {
                    return ANIMATION_MAP[key];
                }
            }
            return ANIMATION_MAP['_default'];
        }

        /* --- Intersection Observer setup --- */
        if (!('IntersectionObserver' in window)) return; /* पुरानो browsers skip */

        var observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    var el = entry.target;
                    el.classList.add('sr-visible');
                    observer.unobserve(el);
                }
            });
        }, {
            threshold   : 0.08,          /* element को 8% देखिएपछि animate हुन्छ */
            rootMargin  : '0px 0px -40px 0px'  /* bottom मा 40px offset */
        });

        /* --- Stagger delay — grid items क्रमैसँग आउन --- */
        function applyStagger(elements) {
            var groups = {}; /* parent अनुसार group गर्छ */
            elements.forEach(function(el) {
                var parent = el.parentElement ? el.parentElement.className : 'root';
                if (!groups[parent]) groups[parent] = [];
                groups[parent].push(el);
            });
            Object.values(groups).forEach(function(group) {
                group.forEach(function(el, i) {
                    /* max 5 items सम्म stagger, त्यसपछि reset */
                    el.style.transitionDelay = Math.min(i % 6, 5) * 0.08 + 's';
                });
            });
        }

        /* --- सबै matching elements observe गर्छ --- */
        document.addEventListener('DOMContentLoaded', function() {
            var allElements = [];

            SELECTORS.forEach(function(sel) {
                document.querySelectorAll(sel).forEach(function(el) {
                    /* already processed छ? skip */
                    if (el.dataset.srDone) return;
                    el.dataset.srDone = '1';

                    var animClass = getAnimationClass(el);
                    el.classList.add('sr-hidden', animClass);
                    allElements.push(el);
                    observer.observe(el);
                });
            });

            applyStagger(allElements);

            /* --- Hero section / above-the-fold elements तुरुन्तै देखाउने ---
               Viewport भित्र नै भएका elements animate नगरी सिधै देखाउँछ */
            setTimeout(function() {
                document.querySelectorAll('.sr-hidden').forEach(function(el) {
                    var rect = el.getBoundingClientRect();
                    if (rect.top < window.innerHeight && rect.bottom > 0) {
                        el.classList.add('sr-visible');
                        el.style.transitionDelay = '0s';
                        observer.unobserve(el);
                    }
                });
            }, 100);
        });

    })();
    </script>

    <!-- Custom JS -->
    <script src="<?php echo SITE_URL; ?>assets/js/main.js"></script>

    <!-- Init uniformity helpers (datepicker init + conservative a11y fixes) -->
    <script src="<?php echo SITE_URL; ?>assets/js/init-uniformity.js"></script>

    <!-- Universal Phone/Email Validation — सबै public forms मा automatic -->
    <script src="<?php echo SITE_URL; ?>assets/js/form-validation.js"></script>

    <!-- Enhanced Search with Voice Support (issue #7) -->
    <script src="<?php echo SITE_URL; ?>assets/js/search-improved.js"></script>

    <!-- Voice/Camera/Tilt Scroll Accessibility — "माथि", "तला" आवाजले scroll -->
    <script src="<?php echo SITE_URL; ?>assets/js/scroll-accessibility.js"></script>

    <!-- Member Satisfaction Floating Widget (issue #5) -->
    <?php
    // Satisfaction widget include — admin ले enable गरेमा मात्र देखिन्छ
    $satisfactionWidgetFile = __DIR__ . '/satisfaction-widget.php';
    if (file_exists($satisfactionWidgetFile)) {
        include $satisfactionWidgetFile;
    }
    ?>

    <!-- Footer Logo Stacked Style (issue #4) -->
    <style>
    /* Footer: logo तल name राख्ने CSS — logo र name एकैठाउँ center align */
    .footer-logo.footer-logo-stacked {
        display: flex;
        flex-direction: column;  /* logo माथि, name तल */
        align-items: flex-start; /* left aligned by default */
        gap: 8px;
        margin-bottom: 16px;
    }
    .footer-logo.footer-logo-stacked img {
        display: block;
        width: auto;
        height: auto;
        max-width: min(280px, 100%);
        max-height: 56px;
        object-fit: contain;
    }
    /* Footer मा सहकारीको नाम — logo को तल देखिन्छ */
    .footer-logo.footer-logo-stacked .footer-sahakari-name {
        display: block;
        font-size: 1.05rem;
        font-weight: 600;
        color: #fff;
        line-height: 1.3;
        margin-top: 2px;
    }
    </style>

<!-- v9.6 Mobile bottom-nav (public) -->
<nav class="mob-bottomnav" aria-label="Quick nav">
    <a href="<?php echo SITE_URL; ?>" class="mob-bn-item <?php echo ($currentPage??'')==='index'?'active':''; ?>"><i class="lucide-icon" aria-hidden="true" data-lucide="house"></i><span><?php echo isEnglish()?'Home':'गृह'; ?></span></a>
    <a href="<?php echo SITE_URL; ?>services.php" class="mob-bn-item <?php echo ($currentPage??'')==='services'?'active':''; ?>"><i class="lucide-icon" aria-hidden="true" data-lucide="briefcase"></i><span><?php echo isEnglish()?'Services':'सेवा'; ?></span></a>
    <a href="<?php echo SITE_URL; ?>notices.php" class="mob-bn-item <?php echo ($currentPage??'')==='notices'?'active':''; ?>"><i class="lucide-icon" aria-hidden="true" data-lucide="megaphone"></i><span><?php echo isEnglish()?'Notices':'सूचना'; ?></span></a>
    <a href="<?php echo SITE_URL; ?>contact.php" class="mob-bn-item <?php echo ($currentPage??'')==='contact'?'active':''; ?>"><i class="lucide-icon" aria-hidden="true" data-lucide="phone"></i><span><?php echo isEnglish()?'Contact':'सम्पर्क'; ?></span></a>
    <a href="<?php echo SITE_URL; ?>member/" class="mob-bn-item"><i class="lucide-icon" aria-hidden="true" data-lucide="user"></i><span><?php echo isEnglish()?'Member':'सदस्य'; ?></span></a>
</nav>
<script>document.body.classList.add('has-bottomnav');</script>
<?php
$__v9MobileFixVer = (defined('ROOT_PATH') ? @filemtime(ROOT_PATH . 'assets/js/v9-mobile-fix.js') : false);
if (!$__v9MobileFixVer) { $__v9MobileFixVer = time(); }
?>
    <script src="<?php echo SITE_URL; ?>assets/js/v9-mobile-fix.js?v=<?php echo (int)$__v9MobileFixVer; ?>" defer></script>
<script>
function copyTrk(id,btn){
    var el=document.getElementById(id);
    if(!el)return;
    navigator.clipboard.writeText(el.innerText.trim()).then(function(){
        btn.innerHTML='<i class="fas fa-check"></i>';
        btn.classList.add('btn-success');btn.classList.remove('btn-outline-success');
        setTimeout(function(){btn.innerHTML='<i class="lucide-icon" aria-hidden="true" data-lucide="copy"></i>';btn.classList.remove('btn-success');btn.classList.add('btn-outline-success');},1800);
    }).catch(function(){
        var r=document.createRange();r.selectNode(el);window.getSelection().removeAllRanges();window.getSelection().addRange(r);
    });
}
</script>

<script>
/* ─── Global: Bootstrap form validation + submit loading state ─── */
(function(){
    // Bootstrap was-validated (skip multi-step wizard forms — they handle their own validation)
    document.querySelectorAll('form.needs-validation').forEach(function(form){
        if(form.id === 'fullKymForm') return;
        form.addEventListener('submit', function(e){
            if(!this.checkValidity()){ e.preventDefault(); e.stopPropagation(); }
            this.classList.add('was-validated');
        }, false);
    });
    // Submit button loading spinner (all forms)
    document.querySelectorAll('form').forEach(function(form){
        form.addEventListener('submit', function(){
            if(this.checkValidity && !this.checkValidity()) return;
            var btn = this.querySelector('[type="submit"]:not([data-no-spin])');
            if(btn && !btn.disabled){
                btn.dataset.origHtml = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>' + (btn.dataset.loadingText || '...');
                setTimeout(function(){ if(btn.dataset.origHtml){ btn.disabled=false; btn.innerHTML=btn.dataset.origHtml; } }, 12000);
            }
        });
    });
    // Auto-clear is-invalid on input
    document.addEventListener('input', function(e){
        if(e.target && e.target.classList && e.target.classList.contains('is-invalid')){
            if(e.target.checkValidity && e.target.checkValidity()){
                e.target.classList.remove('is-invalid');
                e.target.classList.add('is-valid');
            }
        }
    });
})();

    // ── Auto-inject CSRF token into all public POST forms ──
    (function(){
        var meta = document.querySelector('meta[name="csrf-token"]');
        if (!meta) return;
        document.querySelectorAll('form[method="post"], form[method="POST"]').forEach(function(f){
            if (!f.querySelector('input[name="csrf_token"]')) {
                var h = document.createElement('input');
                h.type = 'hidden'; h.name = 'csrf_token'; h.value = meta.content;
                f.prepend(h);
            }
        });
    })();
</script>

<?php
$__uiTestMode = isset($_GET['ui_test']) && (string)$_GET['ui_test'] === '1';
if ($__uiTestMode):
?>
<script>
(function(){
    function q(sel){ return document.querySelector(sel); }
    function byId(id){ return document.getElementById(id); }
    function log(msg){
        var out = byId('uiTestOut');
        if (!out) return;
        var t = new Date().toLocaleTimeString();
        out.textContent = '[' + t + '] ' + msg + '\n' + out.textContent;
    }
    function menuState(){
        var toggle = byId('mobileMenuToggle2');
        var nav = byId('mainNavV2');
        if (!toggle || !nav) return { ok:false, msg:'mobile toggle/nav not found' };
        var icon = toggle.querySelector('i');
        var expanded = toggle.getAttribute('aria-expanded') === 'true';
        var open = nav.classList.contains('nav-open') || nav.classList.contains('open') || nav.classList.contains('active');
        var iconX = !!(icon && icon.classList.contains('fa-xmark'));
        var iconBars = !!(icon && icon.classList.contains('fa-bars'));
        var ok = (expanded === open) && ((open && iconX) || (!open && iconBars));
        return {
            ok: ok,
            msg: 'expanded=' + expanded + ', open=' + open + ', iconX=' + iconX + ', iconBars=' + iconBars
        };
    }
    function checkSizes(){
        var dark = byId('topbarDarkModeToggle');
        var langDot = q('.pfl-lang-wrap .pfl-lang-dot');
        var res = [];
        if (dark) {
            var b = dark.getBoundingClientRect();
            res.push('dark=' + Math.round(b.width) + 'x' + Math.round(b.height));
        }
        if (langDot) {
            var d = langDot.getBoundingClientRect();
            res.push('langDot=' + d.width.toFixed(1) + 'x' + d.height.toFixed(1));
        }
        if (!res.length) res.push('target elements not found');
        return res.join(', ');
    }
    function inspectVisibleCloseElements(){
        var items = Array.prototype.slice.call(document.querySelectorAll('#closeMenuV2, #closeMenu, .close-menu, #mainNavV2 i.fa-times, i.fa-xmark'));
        var lines = [];
        items.forEach(function(el){
            var st = window.getComputedStyle(el);
            var r = el.getBoundingClientRect();
            var visible = st.display !== 'none' && st.visibility !== 'hidden' && parseFloat(st.opacity || '1') > 0.01 && r.width > 1 && r.height > 1;
            if (!visible) return;
            var id = el.id ? ('#' + el.id) : '(no-id)';
            lines.push(
                (el.tagName.toLowerCase()) + id +
                ' cls=' + (el.className || '(none)') +
                ' pos=' + st.position +
                ' z=' + st.zIndex +
                ' rect=' + Math.round(r.left) + ',' + Math.round(r.top) + ',' + Math.round(r.width) + 'x' + Math.round(r.height)
            );
        });
        return lines.length ? lines.join(' | ') : 'no visible close/x elements found';
    }
    function forceReset(){
        if (typeof window.__pflMobileMenuClose === 'function') window.__pflMobileMenuClose();
        var toggle = byId('mobileMenuToggle2');
        if (toggle) {
            toggle.classList.remove('is-open');
            toggle.setAttribute('aria-expanded','false');
            var i = toggle.querySelector('i');
            if (i) { i.classList.remove('fa-xmark'); i.classList.add('fa-bars'); }
        }
        var nav = byId('mainNavV2');
        if (nav) {
            nav.classList.remove('nav-open','open','active');
            nav.setAttribute('aria-hidden','true');
        }
        ['menuOverlay','pflMobileBackdrop'].forEach(function(id){
            var el = byId(id);
            if (el) el.classList.remove('active');
        });
        q('.mobile-nav-backdrop') && q('.mobile-nav-backdrop').classList.remove('active');
        document.body.classList.remove('mobile-nav-open');
        document.documentElement.classList.remove('mobile-nav-open');
        document.body.style.top = '';
        document.body.style.overflow = '';
        if (nav) {
            nav.querySelectorAll('.has-dropdown.open, .has-sub.open').forEach(function (el) {
                el.classList.remove('open');
            });
        }
        log('force reset done');
        setTimeout(function(){
            var s = menuState();
            log((s.ok ? 'OK ' : 'WARN ') + 'after reset -> ' + s.msg);
        }, 60);
    }
    function buildPanel(){
        var panel = document.createElement('aside');
        panel.id = 'uiTestPanel';
        panel.style.cssText = 'position:fixed;right:10px;bottom:84px;z-index:2147483647;width:min(92vw,320px);background:#0f172a;color:#e5e7eb;border:1px solid #334155;border-radius:12px;box-shadow:0 12px 26px rgba(2,6,23,.45);font:12px/1.35 -apple-system,BlinkMacSystemFont,Segoe UI,sans-serif;';
        panel.innerHTML = '' +
            '<div style="padding:8px 10px;border-bottom:1px solid #334155;font-weight:700;display:flex;justify-content:space-between;align-items:center;">UI Test Mode <button id="uiTestClose" style="background:#1e293b;color:#cbd5e1;border:1px solid #334155;border-radius:8px;padding:2px 8px;">hide</button></div>' +
            '<div style="padding:8px;display:grid;grid-template-columns:1fr 1fr;gap:6px;">' +
            '<button id="uiChkState" style="padding:7px;border-radius:8px;border:1px solid #334155;background:#111827;color:#e5e7eb;">Check State</button>' +
            '<button id="uiChkSizes" style="padding:7px;border-radius:8px;border:1px solid #334155;background:#111827;color:#e5e7eb;">Check Sizes</button>' +
            '<button id="uiOpenMenu" style="padding:7px;border-radius:8px;border:1px solid #334155;background:#0b5;color:#f8fffb;">Open Menu</button>' +
            '<button id="uiReset" style="padding:7px;border-radius:8px;border:1px solid #334155;background:#7f1d1d;color:#fee2e2;">Force Reset</button>' +
            '<button id="uiInspectX" style="grid-column:1/3;padding:7px;border-radius:8px;border:1px solid #334155;background:#1f2937;color:#d1d5db;">Inspect Visible X</button>' +
            '</div>' +
            '<pre id="uiTestOut" style="margin:0;padding:8px;max-height:170px;overflow:auto;background:#020617;border-top:1px solid #334155;white-space:pre-wrap;"></pre>';
        document.body.appendChild(panel);
        byId('uiTestClose').addEventListener('click', function(){ panel.style.display = 'none'; });
        byId('uiChkState').addEventListener('click', function(){ var s = menuState(); log((s.ok ? 'OK ' : 'WARN ') + s.msg); });
        byId('uiChkSizes').addEventListener('click', function(){ log(checkSizes()); });
        byId('uiOpenMenu').addEventListener('click', function(){ if (typeof window.__pflMobileMenuOpen === 'function') window.__pflMobileMenuOpen(); var s = menuState(); log('open-> ' + s.msg); });
        byId('uiReset').addEventListener('click', forceReset);
        byId('uiInspectX').addEventListener('click', function(){ log(inspectVisibleCloseElements()); });
        setTimeout(function(){ var s = menuState(); log((s.ok ? 'OK ' : 'WARN ') + s.msg); log(checkSizes()); log(inspectVisibleCloseElements()); }, 350);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', buildPanel);
    else buildPanel();
})();
</script>
<?php endif; ?>
<!-- v12 — Quick Actions FAB (loan, account, contact, tracker, branches, login + help items) -->
<div class="qh-launcher" id="qhLauncher" aria-label="Quick Actions">
  <div class="qh-menu" role="menu">

    <!-- ── Quick action label ── -->
    <div class="qh-section-label">द्रुत सेवाहरू</div>

    <!-- Loan Apply -->
    <a class="qh-item" role="menuitem"
       href="<?php echo SITE_URL; ?>services.php">
      <span class="qh-ic qh-loan"><i class="fas fa-hand-holding-dollar"></i></span>
      <span>ऋण आवेदन</span>
    </a>

    <!-- Open Account -->
    <a class="qh-item" role="menuitem"
       href="<?php echo SITE_URL; ?>online-account.php">
      <span class="qh-ic qh-account"><i class="lucide-icon" aria-hidden="true" data-lucide="landmark"></i></span>
      <span>खाता खोल्नुहोस्</span>
    </a>

    <!-- Contact -->
    <a class="qh-item" role="menuitem"
       href="<?php echo SITE_URL; ?>contact.php">
      <span class="qh-ic qh-contact"><i class="fas fa-phone"></i></span>
      <span>सम्पर्क गर्नुहोस्</span>
    </a>

    <!-- Track Application -->
    <a class="qh-item" role="menuitem"
       href="<?php echo SITE_URL; ?>application-tracker.php">
      <span class="qh-ic qh-track"><i class="fas fa-magnifying-glass-chart"></i></span>
      <span>आवेदन ट्र्याक</span>
    </a>

    <!-- Service Centers -->
    <a class="qh-item" role="menuitem"
       href="<?php echo SITE_URL; ?>service-centers.php">
      <span class="qh-ic qh-branch"><i class="fas fa-location-dot"></i></span>
      <span><?php echo isEnglish() ? 'Service Centers' : 'सेवा कार्यालयहरू'; ?></span>
    </a>

    <!-- Member Login -->
    <a class="qh-item" role="menuitem"
       href="<?php echo SITE_URL; ?>member/login.php">
      <span class="qh-ic qh-login"><i class="fas fa-right-to-bracket"></i></span>
      <span>सदस्य लगइन</span>
    </a>

    <!-- ── Divider before help items ── -->
    <div class="qh-divider"></div>
    <div class="qh-section-label">सहयोग</div>

    <?php if (!empty($whatsappNumber)): ?>
    <a class="qh-item" role="menuitem" target="_blank"
       href="https://wa.me/<?php echo htmlspecialchars($whatsappNumber, ENT_QUOTES); ?>">
      <span class="qh-ic wa"><i class="fab fa-whatsapp"></i></span>
      <span>WhatsApp सम्पर्क</span>
    </a>
    <?php endif; ?>
    <button type="button" class="qh-item" role="menuitem"
      onclick="document.getElementById('publicChatPanel').classList.add('open');document.getElementById('qhLauncher').classList.remove('open');">
      <span class="qh-ic chat"><i class="fas fa-comments"></i></span>
      <span>लाइभ च्याट / सन्देश</span>
    </button>
    <button type="button" class="qh-item" role="menuitem"
      onclick="var t=document.getElementById('chatbotToggle');if(t)t.click();document.getElementById('qhLauncher').classList.remove('open');">
      <span class="qh-ic help"><i class="lucide-icon" aria-hidden="true" data-lucide="circle-question"></i></span>
      <span>सहायता / FAQ</span>
    </button>
    <?php if (!empty($usefulLinks)): ?>
    <button type="button" class="qh-item" role="menuitem"
      onclick="event.stopPropagation();if(window.coopOpenUsefulLinks)window.coopOpenUsefulLinks();var l=document.getElementById('qhLauncher');if(l)l.classList.remove('open');">
      <span class="qh-ic links"><i class="fas fa-external-link-alt"></i></span>
      <span>उपयोगी लिंकहरू</span>
    </button>
    <?php endif; ?>
  </div>

  <button type="button" class="qh-fab" id="qhFab" aria-label="Quick Actions"
    onclick="document.getElementById('qhLauncher').classList.toggle('open');">
    <i class="fas fa-bolt qh-i-open"></i>
    <i class="fas fa-times qh-i-close"></i>
  </button>
</div>
<script>
(function(){
  document.addEventListener('click', function(e){
    var l=document.getElementById('qhLauncher');
    if(l && !l.contains(e.target)) l.classList.remove('open');
  });
})();
</script>
<div id="publicChatPanel" role="dialog" aria-label="Live Chat">
  <div class="pcp-h">
    <i class="lucide-icon" aria-hidden="true" data-lucide="headset"></i>
    <div>
      <div class="pcp-title">सहयोग केन्द्र</div>
      <div class="pcp-sub">हाम्रो टोलीसँग सिधा सम्पर्क</div>
    </div>
    <button type="button" class="pcp-x" onclick="document.getElementById('publicChatPanel').classList.remove('open')" aria-label="Close">×</button>
  </div>
  <form class="pcp-body" id="publicChatForm" novalidate>
    <label>तपाईंको नाम *</label>
    <input type="text" name="name" maxlength="80" required autocomplete="name">
    <label>सम्पर्क (फोन/इमेल) — वैकल्पिक</label>
    <input type="text" name="contact" maxlength="120" autocomplete="email">
    <label>सन्देश *</label>
    <textarea name="body" maxlength="2000" required placeholder="कसरी सहयोग गर्न सक्छौं?"></textarea>
    <!-- honeypot — hidden from real users -->
    <input type="text" name="website" tabindex="-1" autocomplete="off"
           style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;">
    <button type="submit"><i class="fas fa-paper-plane me-1"></i> पठाउनुहोस्</button>
    <div class="pcp-msg" id="pcpMsg" style="display:none;"></div>
  </form>
</div>
<script>
(function(){
  var f=document.getElementById('publicChatForm'); if(!f) return;
  var msg=document.getElementById('pcpMsg');
  f.addEventListener('submit', function(e){
    e.preventDefault();
    var btn=f.querySelector('button[type=submit]'); btn.disabled=true; btn.textContent='पठाउँदै ...';
    msg.style.display='none';
    var fd=new FormData(f);
    fetch('<?php echo SITE_URL; ?>api-public-chat.php', {method:'POST', body:fd})
      .then(function(r){ return r.json().catch(function(){ return {ok:false,msg:'त्रुटि'}; }); })
      .then(function(d){
        msg.style.display='block';
        msg.className='pcp-msg ' + (d.ok ? 'ok' : 'err');
        msg.textContent=d.msg || (d.ok?'पठाइयो':'त्रुटि');
        if(d.ok){ f.reset(); }
      })
      .catch(function(){ msg.style.display='block'; msg.className='pcp-msg err'; msg.textContent='नेटवर्क त्रुटि'; })
      .finally(function(){ btn.disabled=false; btn.innerHTML='<i class="fas fa-paper-plane me-1"></i> पठाउनुहोस्'; });
  });
})();
</script>
<style>
  .public-fab-help{
    position:fixed; right:18px; bottom:96px; z-index:60;
    display:inline-flex; align-items:center; gap:8px;
    padding:10px 14px 10px 12px; border-radius:999px;
    background:var(--primary-color,#1a5f2a); color:#fff;
    font-weight:600; font-size:14px; text-decoration:none;
    box-shadow:0 10px 24px rgba(15,23,42,.18);
    transition:transform .15s, box-shadow .15s;
  }
  .public-fab-help i{ font-size:18px; }
  .public-fab-help:hover{ color:#fff; transform:translateY(-2px); box-shadow:0 14px 30px rgba(15,23,42,.22); }
  @media (max-width:768px){
    .public-fab-help{ bottom:78px; padding:9px 12px; }
    .public-fab-help-label{ display:none; }
  }
</style>

<?php /* Duplicate public-mobile-footer disabled — .mob-bottomnav above is the single mobile bar */ ?>
<?php /* require_once __DIR__ . '/mobile-footer-nav.php'; */ ?>

<?php if (function_exists("coopThemeLucideInit")) { coopThemeLucideInit(); } ?>
</body>
</html>
