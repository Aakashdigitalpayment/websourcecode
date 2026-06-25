<?php
require_once __DIR__ . '/_bootstrap.php'; // bootstrap → config auto-loaded
require_once 'includes/ensure-tables.php';
$pageTitle = isEnglish() ? 'Vendor Enlistment' : 'भेन्डर सूचीकरण';
require_once 'includes/header.php';
$L = getLangStrings();

$success = false;
$error = '';
$vndTrackingId = '';

// Form submit CSRF सुरक्षा जाँच
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken()) {
        $error = isEnglish() ? 'Security check failed. Please try again.' : 'सुरक्षा जाँच असफल। कृपया पुन: प्रयास गर्नुहोस्।';
    } elseif (!checkRateLimit('vendor_enlistment', 5, 3600)) {
        $error = isEnglish() ? 'Too many requests. Please try again after 1 hour.' : 'धेरै अनुरोधहरू भए। कृपया १ घण्टापछि पुनः प्रयास गर्नुहोस्।';
    } else {
        try {
            $db = getDB();

            // Plain text for DB (output मा e())
            $companyName  = clean_text($_POST['company_name'] ?? '', 255);
            $ownerName    = clean_text($_POST['owner_name'] ?? '', 100);
            $address      = clean_text($_POST['address'] ?? '', 255);
            $phone        = preg_replace('/[^0-9]/', '', clean_text($_POST['phone'] ?? '', 20));
            $email        = strtolower(clean_text($_POST['email'] ?? '', 254));
            $panNo        = clean_text($_POST['pan_no'] ?? '', 50);
            $businessType = clean_text($_POST['business_type'] ?? '', 80);
            $description  = clean_text($_POST['description'] ?? '', 2000);

            // Basic validation
            if (empty($companyName)) {
                $error = isEnglish() ? 'Company name is required.' : 'कम्पनीको नाम अनिवार्य छ।';
            } elseif (empty($ownerName)) {
                $error = isEnglish() ? 'Owner name is required.' : 'मालिकको नाम अनिवार्य छ।';
            } elseif (empty($phone) || !preg_match('/^[0-9]{6,15}$/', preg_replace('/[^0-9]/', '', $phone))) {
                $error = isEnglish() ? 'Please enter a valid phone number.' : 'कृपया सही फोन नम्बर राख्नुहोस्।';
            } elseif (empty($panNo)) {
                $error = isEnglish() ? 'PAN/VAT number is required.' : 'PAN/VAT नम्बर अनिवार्य छ।';
            } elseif (empty($businessType)) {
                $error = isEnglish() ? 'Please select a business type.' : 'कृपया व्यवसायको प्रकार छान्नुहोस्।';
            } else {
                require_once __DIR__ . '/includes/vendors-tables.php';
                ensureVendorsTables($db);

                $vndTrackingId = 'VND-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 6));

                $stmt = $db->prepare("INSERT INTO vendors (tracking_id, company_name, owner_name, address, phone, email, pan_no, business_type, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$vndTrackingId, $companyName, $ownerName, $address, $phone, $email, $panNo, $businessType, $description]);

                $success = true;
                logSecurityEvent('vendor_enlistment', 'Vendor enlisted: ' . $companyName . ' (Tracking: ' . $vndTrackingId . ')');

                require_once 'includes/notifications.php';

                // Member/vendor confirmation SMS
                $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
                if (strlen($cleanPhone) >= 10) {
                    try {
                        $smsToken = getSetting('notify_sms_token', '');
                        $smsSender = getSetting('notify_sms_sender_id', 'COOP');
                        if (getSetting('notify_sms_enabled', '0') === '1' && $smsToken) {
                            $smsTxt = 'आकाश सहकारी: ' . $companyName . ' को भेन्डर आवेदन दर्ता भयो। Tracking ID: ' . $vndTrackingId . '. हामी छिट्टै सम्पर्क गर्नेछौं।';
                            $ch = curl_init('http://api.sparrowsms.com/v2/sms/');
                            curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query(['token'=>$smsToken,'from'=>$smsSender,'to'=>$cleanPhone,'text'=>mb_substr($smsTxt,0,160)]),CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_SSL_VERIFYPEER=>true]);
                            curl_exec($ch); curl_close($ch);
                        }
                    } catch (Throwable $ignored) {}
                }

                // Admin notification
                sendAdminNotification('vendor_enlistment', [
                    'कम्पनी'       => $companyName,
                    'मालिक'        => $ownerName,
                    'फोन'          => $phone,
                    'इमेल'         => $email ?: 'N/A',
                    'PAN/VAT'      => $panNo,
                    'व्यवसाय प्रकार'=> $businessType,
                    'Tracking ID'  => $vndTrackingId,
                    'मिति'         => date('Y-m-d H:i'),
                ], $vndTrackingId);
            }
        } catch (Throwable $e) {
            $error = isEnglish() ? 'Failed to submit application. Please try again.' : 'आवेदन पेश गर्न असफल भयो। कृपया पुन: प्रयास गर्नुहोस्।';
        }
    }
}
?>

<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><?php echo $L['vendor']; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="index.php"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item active"><?php echo $L['vendor']; ?></li>
            </ol>
        </nav>
    </div>
</section>

<!-- Vendor Enlistment Section -->
<section class="section-padding">
    <div class="container">
        <div class="row">
            <div class="col-lg-8 mx-auto">
                <div class="vendor-info-card mb-4">
                    <h4><i class="fas fa-info-circle"></i> <?php echo isEnglish() ? 'Vendor Enlistment Notice' : 'भेन्डर सूचीकरण सम्बन्धी सूचना'; ?></h4>
                    <p>
                        <?php echo isEnglish()
                            ? 'We invite all interested vendors to register with our cooperative for the supply of goods and services. Please fill out the form below with accurate information.'
                            : 'हामी सबै इच्छुक भेन्डरहरूलाई हाम्रो सहकारीमा सामान तथा सेवा आपूर्तिको लागि दर्ता हुन आमन्त्रित गर्दछौं। कृपया तलको फारम सही जानकारीसहित भर्नुहोस्।'; ?>
                    </p>
                </div>

                <?php if ($success): ?>
                <div class="text-center py-5 px-4 rounded-4 shadow-sm mb-4" style="background:linear-gradient(135deg,#e8f5e9,#f1f8e9);border:2px solid #c8e6c9;">
                    <div style="font-size:4rem;color:var(--primary-light);"><i class="fas fa-store"></i></div>
                    <h3 class="mt-3 fw-bold text-success"><?php echo isEnglish() ? 'Vendor Application Submitted!' : 'भेन्डर आवेदन सफलतापूर्वक पेश भयो!'; ?></h3>
                    <p class="text-muted mb-3"><?php echo isEnglish() ? 'We will review your application and contact you soon.' : 'हामी तपाईंको आवेदन समीक्षा गरी छिट्टै सम्पर्क गर्नेछौं।'; ?></p>
                    <?php if ($vndTrackingId): ?>
                    <div class="d-inline-block px-4 py-3 rounded-3 mb-3" style="background:#f0fff4;border:2px dashed var(--primary-light);">
                        <div class="text-muted small mb-2"><?php echo isEnglish() ? 'Your Tracking ID — save this!' : 'तपाईंको Tracking ID — सुरक्षित राख्नुहोस्!'; ?></div>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <div class="fw-bold fs-5 text-success font-monospace" id="vndTrkId"><?php echo e($vndTrackingId); ?></div>
                            <button type="button" onclick="copyTrk('vndTrkId',this)" class="btn btn-sm btn-outline-success py-0 px-2" title="Copy" style="font-size:11px;line-height:1.8;"><i class="fas fa-copy"></i></button>
                        </div>
                        <div class="small text-muted"><a href="application-tracker.php" class="text-success text-decoration-none fw-semibold">यहाँ बाट</a> Application Tracker मा स्थिति हेर्नुहोस्।</div>
                    </div>
                    <?php endif; ?>
                    <div class="mt-3">
                        <a href="<?php echo SITE_URL; ?>" class="btn btn-success px-4 me-2"><i class="fas fa-home me-1"></i><?php echo $L['home']; ?></a>
                        <a href="vendor-enlistment.php" class="btn btn-outline-secondary px-4"><i class="fas fa-plus me-1"></i><?php echo isEnglish() ? 'New Application' : 'नयाँ आवेदन'; ?></a>
                    </div>
                </div>
                <?php else: ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-1"></i><?php echo e($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <script>document.addEventListener('DOMContentLoaded',function(){var e=document.querySelector('.alert-danger');if(e)e.scrollIntoView({behavior:'smooth',block:'center'});});</script>
                <?php endif; ?>

                <div class="vendor-form-card">
                    <div class="form-header text-center mb-4">
                        <div class="form-icon"><i class="fas fa-store"></i></div>
                        <h3><?php echo isEnglish() ? 'Vendor Registration Form' : 'भेन्डर दर्ता फारम'; ?></h3>
                        <p><?php echo isEnglish() ? 'Fill the form to register as an authorized vendor' : 'अधिकृत भेन्डरको रूपमा दर्ता हुन फारम भर्नुहोस्'; ?></p>
                    </div>

                    <form method="POST" action="" class="vendor-form needs-validation" novalidate>
    <?php echo csrfField(); ?>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo isEnglish() ? 'Company/Firm Name' : 'कम्पनी/फर्मको नाम'; ?> <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="company_name" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo isEnglish() ? 'Owner/Proprietor Name' : 'मालिक/प्रोप्राइटरको नाम'; ?> <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="owner_name" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-12 mb-3">
                                <label class="form-label"><?php echo isEnglish() ? 'Address' : 'ठेगाना'; ?> <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="address" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo isEnglish() ? 'Phone Number' : 'फोन नम्बर'; ?> <span class="text-danger">*</span></label>
                                <input type="tel" class="form-control" name="phone" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo isEnglish() ? 'Email Address' : 'इमेल ठेगाना'; ?></label>
                                <input type="email" class="form-control" name="email">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo isEnglish() ? 'PAN/VAT Number' : 'प्यान/भ्याट नम्बर'; ?> <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="pan_no" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label"><?php echo isEnglish() ? 'Business Type' : 'व्यवसायको प्रकार'; ?> <span class="text-danger">*</span></label>
                                <select class="form-select" name="business_type" required>
                                    <option value=""><?php echo isEnglish() ? 'Select...' : 'छान्नुहोस्...'; ?></option>
                                    <option value="stationery"><?php echo isEnglish() ? 'Stationery & Office Supplies' : 'स्टेशनरी र कार्यालय सामग्री'; ?></option>
                                    <option value="electronics"><?php echo isEnglish() ? 'Electronics & IT Equipment' : 'इलेक्ट्रोनिक्स र IT उपकरण'; ?></option>
                                    <option value="furniture"><?php echo isEnglish() ? 'Furniture' : 'फर्निचर'; ?></option>
                                    <option value="printing"><?php echo isEnglish() ? 'Printing & Publishing' : 'छपाई र प्रकाशन'; ?></option>
                                    <option value="software"><?php echo isEnglish() ? 'Software & IT Services' : 'सफ्टवेयर र IT सेवाहरू'; ?></option>
                                    <option value="security"><?php echo isEnglish() ? 'Security Services' : 'सुरक्षा सेवाहरू'; ?></option>
                                    <option value="maintenance"><?php echo isEnglish() ? 'Maintenance Services' : 'मर्मत सम्भार सेवाहरू'; ?></option>
                                    <option value="other"><?php echo isEnglish() ? 'Other' : 'अन्य'; ?></option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label"><?php echo isEnglish() ? 'Description of Goods/Services' : 'सामान/सेवाहरूको विवरण'; ?></label>
                            <textarea class="form-control" name="description" rows="4" placeholder="<?php echo isEnglish() ? 'Describe the goods or services you can provide...' : 'तपाईंले प्रदान गर्न सक्ने सामान वा सेवाहरूको विवरण दिनुहोस्...'; ?>"></textarea>
                        </div>

                        <div class="form-check mb-4">
                            <input class="form-check-input" type="checkbox" id="termsCheck" required>
                            <label class="form-check-label" for="termsCheck">
                                <?php echo isEnglish()
                                    ? 'I confirm that all information provided is accurate and I agree to the terms and conditions.'
                                    : 'मैले प्रदान गरेको सबै जानकारी सही छ भनी पुष्टि गर्दछु र नियम र सर्तहरू मान्य गर्दछु।'; ?>
                            </label>
                        </div>

                        <div class="text-center">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <span class="spinner-border spinner-border-sm d-none me-1" role="status" aria-hidden="true"></span><i class="fas fa-paper-plane me-1"></i>
                                <?php echo isEnglish() ? 'Submit Application' : 'आवेदन पेश गर्नुहोस्'; ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>

<?php require_once 'includes/footer.php'; ?>
