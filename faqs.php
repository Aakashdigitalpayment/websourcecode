<?php
require_once __DIR__ . '/_bootstrap.php'; // bootstrap → config auto-loaded
$pageTitle = isEnglish() ? 'Frequently Asked Questions' : 'बारम्बार सोधिने प्रश्नहरू';
require_once 'includes/header.php';
$L = getLangStrings();

// Get FAQs from database
try {
    $db = getDB();
    $faqsStmt = $db->query("SELECT * FROM faqs WHERE is_active = 1 ORDER BY display_order ASC, id ASC");
    $faqs = $faqsStmt->fetchAll();
} catch (Throwable $e) {
    $faqs = [];
}
?>

<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><?php echo isEnglish() ? 'FAQs' : 'प्रश्नोत्तर'; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item active"><?php echo isEnglish() ? 'FAQs' : 'प्रश्नोत्तर'; ?></li>
            </ol>
        </nav>
    </div>
</section>

<!-- FAQ Section -->
<section class="section-padding">
    <div class="container">
        <div class="section-header section-header-unified text-center mb-5" data-aos="fade-up">
            <div class="section-badge-wrap">
                <span class="section-badge"><i class="fas fa-question-circle"></i> <?php echo isEnglish() ? 'FAQs' : 'प्रश्नोत्तर'; ?></span>
            </div>
            <h2><?php echo isEnglish() ? 'Frequently Asked Questions' : 'बारम्बार सोधिने प्रश्नहरू'; ?></h2>
            <div class="section-divider"></div>
            <p><?php echo isEnglish() ? 'Find answers to commonly asked questions about our services' : 'हाम्रा सेवाहरूको बारेमा सामान्यतया सोधिने प्रश्नहरूको जवाफ पाउनुहोस्'; ?></p>
        </div>

        <div class="row justify-content-center">
            <div class="col-lg-10">
                <?php if (!empty($faqs)): ?>
                <div class="accordion faq-accordion" id="faqAccordion">
                    <?php foreach ($faqs as $index => $faq): ?>
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button <?php echo $index > 0 ? 'collapsed' : ''; ?>" type="button" data-bs-toggle="collapse" data-bs-target="#faq<?php echo $faq['id']; ?>">
                                <?php echo isEnglish() ? ($faq['question'] ?? $faq['question_np']) : ($faq['question_np'] ?? $faq['question']); ?>
                            </button>
                        </h2>
                        <div id="faq<?php echo $faq['id']; ?>" class="accordion-collapse collapse <?php echo $index === 0 ? 'show' : ''; ?>" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                <?php echo nl2br(e(isEnglish() ? ($faq['answer'] ?? $faq['answer_np']) : ($faq['answer_np'] ?? $faq['answer']))); ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <!-- Default FAQs if database is empty -->
                <div class="accordion faq-accordion" id="faqAccordion">
                    <!-- FAQ 1 -->
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                                <?php echo isEnglish() ? 'How can I become a member of the cooperative?' : 'म कसरी सहकारीको सदस्य बन्न सक्छु?'; ?>
                            </button>
                        </h2>
                        <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                <?php echo isEnglish()
                                    ? 'To become a member, you need to visit our nearest branch with valid citizenship documents, 2 passport-size photos, and fill out the membership form. A minimum share capital is required which varies by membership type.'
                                    : 'सदस्य बन्नको लागि, तपाईंले हाम्रो नजिकको शाखामा वैध नागरिकताको कागजात, २ पासपोर्ट साइजको फोटो लिएर आउनु पर्छ र सदस्यता फारम भर्नुपर्छ।'; ?>
                            </div>
                        </div>
                    </div>

                    <!-- FAQ 2 -->
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                                <?php echo isEnglish() ? 'What types of savings accounts do you offer?' : 'तपाईंहरूले कस्ता प्रकारका बचत खाताहरू प्रदान गर्नुहुन्छ?'; ?>
                            </button>
                        </h2>
                        <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                <?php echo isEnglish()
                                    ? 'We offer various savings accounts including Regular Savings, Fixed Deposit (FD), Recurring Deposit (RD), Children\'s Savings, and Special Savings schemes.'
                                    : 'हामी विभिन्न बचत खाताहरू प्रदान गर्दछौं जसमा नियमित बचत, स्थिर निक्षेप (FD), आवधिक निक्षेप (RD), बाल बचत, र विशेष बचत योजनाहरू समावेश छन्।'; ?>
                            </div>
                        </div>
                    </div>

                    <!-- FAQ 3 -->
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                                <?php echo isEnglish() ? 'What are the loan products available?' : 'कस्ता ऋण उत्पादनहरू उपलब्ध छन्?'; ?>
                            </button>
                        </h2>
                        <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                <?php echo isEnglish()
                                    ? 'We provide various loan products including Home Loan, Vehicle Loan, Business Loan, Personal Loan, Educational Loan, and Agricultural Loan.'
                                    : 'हामी विभिन्न ऋण उत्पादनहरू प्रदान गर्दछौं जसमा घर ऋण, सवारी ऋण, व्यवसाय ऋण, व्यक्तिगत ऋण, शैक्षिक ऋण, र कृषि ऋण समावेश छन्।'; ?>
                            </div>
                        </div>
                    </div>

                    <!-- FAQ 4 -->
                    <div class="accordion-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq4">
                                <?php echo isEnglish() ? 'What are your operating hours?' : 'तपाईंहरूको कार्य समय के हो?'; ?>
                            </button>
                        </h2>
                        <div id="faq4" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                            <div class="accordion-body">
                                <?php echo isEnglish()
                                    ? 'Our branches are open Sunday to Friday, 10:00 AM to 5:00 PM. Some branches may have extended hours.'
                                    : 'हाम्रा शाखाहरू आइतबारदेखि शुक्रबारसम्म, बिहान १०:०० बजेदेखि साँझ ५:०० बजेसम्म खुल्छन्।'; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Contact CTA -->
                <div class="text-center mt-5">
                    <p class="mb-3">
                        <?php echo isEnglish() ? 'Didn\'t find what you\'re looking for?' : 'तपाईंले खोजिरहेको भेट्नुभएन?'; ?>
                    </p>
                    <a href="contact.php" class="btn btn-primary">
                        <i class="fas fa-envelope"></i> <?php echo $L['contact_us']; ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>


<?php require_once 'includes/footer.php'; ?>
