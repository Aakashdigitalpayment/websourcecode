<?php
/**
 * ═══════════════════════════════════════════════════════════
 *  UNIVERSAL PRINT FORM — Bank-style printable/PDF form
 *  Supports: kyc | loan | welfare | digital | account
 *  URL: admin/print-form.php?type=kyc&id=5
 * ═══════════════════════════════════════════════════════════
 */
define('SECURE_ACCESS', true);
require_once __DIR__ . '/../includes/config.php';

/* ── Auth check ── */
if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['admin_id']) && empty($_SESSION['admin_logged_in'])) {
    http_response_code(403);
    echo '<p style="font-family:sans-serif;padding:2rem;color:red;">Access denied. Please login first.</p>';
    exit;
}

$db   = getDB();
$type = trim($_GET['type'] ?? '');
$id   = (int)($_GET['id'] ?? 0);

$allowedTypes = ['kyc', 'loan', 'welfare', 'digital', 'account'];
if (!in_array($type, $allowedTypes, true) || $id <= 0) {
    http_response_code(400);
    echo '<p style="font-family:sans-serif;padding:2rem;color:red;">Invalid request. Use ?type=kyc|loan|welfare|digital|account&amp;id=N</p>';
    exit;
}

/* ── Site settings ── */
$siteName    = getSetting('site_name',           'सहकारी संस्था');
$siteAddress = getSetting('office_address',      '');
$sitePhone   = getSetting('office_phone',        '');
$siteEmail   = getSetting('contact_email',       '');
$siteRegNo   = getSetting('registration_number', '');
$siteLogo    = getSetting('site_logo', getSetting('logo', ''));
if ($siteLogo) $siteLogo = rtrim(SITE_URL, '/') . '/' . ltrim($siteLogo, '/');
$today = function_exists('formatNepaliDate') ? formatNepaliDate(date('Y-m-d')) : date('Y-m-d');

/* ── Helpers ── */
function pf_e($v): string  { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function pf_d($v): string  {
    if (!$v || $v === '—') return '—';
    return function_exists('formatNepaliDate') ? formatNepaliDate($v) : pf_e($v);
}
function pf_cur($v): string {
    $n = (float)($v ?? 0);
    return $n > 0 ? 'रू. ' . number_format($n, 2) : '—';
}

/* ── Data fetch & section builder ── */
$data        = null;
$formTitle   = '';
$formTitleEn = '';
$trackId     = '';
$statusLabel = '';
$photoPath   = '';
$sections    = [];   // [ ['title'=>'…', 'rows'=>[ [np, en, value], … ]] ]

switch ($type) {

/* ════════════════════════════ KYC ════════════════════════════ */
case 'kyc':
    $st = $db->prepare("SELECT * FROM kyc_applications WHERE id=?");
    $st->execute([$id]);  $data = $st->fetch();
    if (!$data) goto NOT_FOUND;
    $formTitle   = 'केवाइसी (KYC) आवेदन फारम';
    $formTitleEn = 'Know Your Customer (KYC) Application Form';
    $trackId     = $data['tracking_id'] ?? 'KYC-' . str_pad($id, 6, '0', STR_PAD_LEFT);
    $slMap       = ['pending'=>'पेन्डिङ','approved'=>'स्वीकृत','rejected'=>'अस्वीकृत','incomplete'=>'अपूर्ण','partial'=>'आंशिक'];
    $statusLabel = $slMap[$data['status']] ?? $data['status'];
    if (!empty($data['photo'])) $photoPath = rtrim(SITE_URL,'/').'/'.ltrim($data['photo'],'/');
    $sections = [
        ['title'=>'व्यक्तिगत जानकारी / Personal Information', 'rows'=>[
            ['पूरा नाम (नेपाली)',   'Full Name (Nepali)',    pf_e($data['full_name'])],
            ['पूरा नाम (अंग्रेजी)','Full Name (English)',   pf_e($data['full_name_en'])],
            ['सदस्यता नं.',         'Member ID',             pf_e($data['member_id'])],
            ['जन्म मिति (BS)',      'Date of Birth (BS)',    pf_e($data['dob_bs'])],
            ['लिङ्ग',              'Gender',                pf_e($data['gender'])],
            ['वैवाहिक अवस्था',     'Marital Status',        pf_e($data['marital_status'] ?? '')],
        ]],
        ['title'=>'सम्पर्क विवरण / Contact Details', 'rows'=>[
            ['मोबाइल',             'Mobile',                pf_e($data['mobile'])],
            ['इमेल',               'Email',                 pf_e($data['email'])],
            ['स्थायी ठेगाना',      'Permanent Address',     pf_e($data['permanent_address'] ?? $data['address'] ?? '')],
            ['अस्थायी ठेगाना',     'Temporary Address',     pf_e($data['temporary_address'] ?? '')],
        ]],
        ['title'=>'नागरिकता विवरण / Citizenship Details', 'rows'=>[
            ['नागरिकता नं.',        'Citizenship No.',       pf_e($data['citizenship_no'])],
            ['जारी मिति',           'Issued Date',           pf_e($data['citizenship_issued_date'])],
            ['जारी जिल्ला',        'Issued Place',          pf_e($data['citizenship_issued_place'])],
            ['बुबाको नाम',          "Father's Name",         pf_e($data['father_name'] ?? '')],
            ['आमाको नाम',          "Mother's Name",         pf_e($data['mother_name'] ?? '')],
        ]],
        ['title'=>'पेशागत / आर्थिक जानकारी / Professional & Financial', 'rows'=>[
            ['पेशा',               'Occupation',            pf_e($data['occupation'] ?? '')],
            ['संस्था',             'Organization',          pf_e($data['organization'] ?? $data['organization_name'] ?? '')],
            ['आय स्रोत',           'Income Source',         pf_e($data['income_source'] ?? '')],
            ['वार्षिक आय',         'Annual Income',         pf_cur($data['annual_income'] ?? 0)],
            ['आवेदन मिति',         'Application Date',      pf_d($data['created_at'])],
        ]],
    ];
    break;

/* ════════════════════════════ LOAN ════════════════════════════ */
case 'loan':
    $st = $db->prepare("SELECT * FROM loan_applications WHERE id=?");
    $st->execute([$id]);  $data = $st->fetch();
    if (!$data) goto NOT_FOUND;
    $formTitle   = 'ऋण आवेदन फारम';
    $formTitleEn = 'Loan Application Form';
    $trackId     = $data['tracking_id'] ?? 'LOAN-' . str_pad($id, 6, '0', STR_PAD_LEFT);
    $slMap       = ['pending'=>'पेन्डिङ','processing'=>'प्रक्रियामा','approved'=>'स्वीकृत','rejected'=>'अस्वीकृत','disbursed'=>'वितरित'];
    $statusLabel = $slMap[$data['status']] ?? $data['status'];
    $sections = [
        ['title'=>'आवेदकको जानकारी / Applicant Information', 'rows'=>[
            ['पूरा नाम',           'Full Name',             pf_e($data['full_name'])],
            ['सदस्य नं.',          'Member ID',             pf_e($data['member_id'])],
            ['मोबाइल',             'Mobile',                pf_e($data['mobile'])],
            ['इमेल',               'Email',                 pf_e($data['email'])],
            ['नागरिकता नं.',        'Citizenship No.',       pf_e($data['citizenship_no'])],
            ['ठेगाना',             'Address',               pf_e($data['address'])],
            ['आवेदन मिति',         'Application Date',      pf_d($data['created_at'])],
        ]],
        ['title'=>'ऋण विवरण / Loan Details', 'rows'=>[
            ['ऋणको प्रकार',        'Loan Type',             pf_e($data['loan_type'])],
            ['ऋण रकम',             'Loan Amount',           pf_cur($data['loan_amount'])],
            ['ऋण अवधि',           'Loan Tenure',           $data['loan_tenure'] ? pf_e($data['loan_tenure']).' महिना' : '—'],
            ['भुक्तानी विधि',       'Repayment Method',      pf_e($data['repayment_method'])],
            ['ऋण उद्देश्य',        'Loan Purpose',          pf_e($data['loan_purpose'])],
        ]],
        ['title'=>'आय / पेशा / Income & Occupation', 'rows'=>[
            ['पेशा',               'Occupation',            pf_e($data['occupation'])],
            ['संस्था/व्यवसाय',    'Organization/Business', pf_e($data['organization_name'])],
            ['मासिक आय',          'Monthly Income',        pf_cur($data['monthly_income'])],
        ]],
        ['title'=>'धितो जानकारी / Collateral Details', 'rows'=>[
            ['धितो प्रकार',        'Collateral Type',       pf_e($data['collateral_type'])],
            ['धितो मूल्य',         'Collateral Value',      pf_cur($data['collateral_value'])],
            ['धितो विवरण',         'Description',           pf_e($data['collateral_description'])],
        ]],
    ];
    if (!empty($data['guarantor_name'])) {
        $sections[] = ['title'=>'जमानी विवरण / Guarantor Details', 'rows'=>[
            ['जमानीको नाम',        'Guarantor Name',        pf_e($data['guarantor_name'])],
            ['सम्बन्ध',            'Relation',              pf_e($data['guarantor_relation'])],
            ['फोन',               'Phone',                 pf_e($data['guarantor_phone'])],
            ['ठेगाना',            'Address',               pf_e($data['guarantor_address'])],
        ]];
    }
    break;

/* ════════════════════════════ WELFARE ════════════════════════════ */
case 'welfare':
    $st = $db->prepare("SELECT * FROM member_welfare_claims WHERE id=?");
    $st->execute([$id]);  $data = $st->fetch();
    if (!$data) goto NOT_FOUND;
    $ctLabels    = ['maternity'=>'सुत्केरी सुविधा','death'=>'मृत्यु सुविधा','insurance'=>'बीमा दाबी','medical'=>'उपचार खर्च','accident'=>'दुर्घटना सुविधा','other'=>'अन्य सुविधा'];
    $ctLabel     = $ctLabels[$data['claim_type']] ?? $data['claim_type'];
    $formTitle   = 'कल्याण दाबी फारम — ' . $ctLabel;
    $formTitleEn = 'Welfare Claim Form — ' . ($data['claim_type'] ?? '');
    $trackId     = $data['tracking_id'] ?? 'WLF-' . str_pad($id, 6, '0', STR_PAD_LEFT);
    $slMap       = ['pending'=>'पेन्डिङ','under_review'=>'समीक्षाधीन','approved'=>'स्वीकृत','rejected'=>'अस्वीकृत','paid'=>'भुक्तान'];
    $statusLabel = $slMap[$data['status']] ?? $data['status'];
    $sections = [
        ['title'=>'सदस्य जानकारी / Member Information', 'rows'=>[
            ['सदस्यको नाम',        'Member Name',           pf_e($data['member_name'] ?? $data['full_name'] ?? '')],
            ['सदस्य नं.',          'Member ID',             pf_e($data['member_id'])],
            ['फोन',               'Phone',                 pf_e($data['phone'])],
            ['इमेल',              'Email',                 pf_e($data['email'])],
            ['ठेगाना',            'Address',               pf_e($data['address'])],
            ['आवेदन मिति',        'Application Date',      pf_d($data['created_at'])],
        ]],
        ['title'=>'दाबी विवरण / Claim Details', 'rows'=>[
            ['दाबीको प्रकार',     'Claim Type',            pf_e($ctLabel)],
            ['दाबी रकम',          'Claim Amount',          pf_cur($data['claim_amount'])],
            ['स्वीकृत रकम',       'Approved Amount',       !empty($data['approved_amount']) ? pf_cur($data['approved_amount']) : '—'],
            ['विवरण',             'Description',           pf_e($data['description'])],
        ]],
    ];
    if ($data['claim_type'] === 'death') {
        $sections[] = ['title'=>'मृत्यु दाबी विवरण / Death Claim Details', 'rows'=>[
            ['मृतकको नाम',        'Deceased Name',         pf_e($data['deceased_name'])],
            ['नाता',              'Relation',              pf_e($data['deceased_relation'])],
            ['मृत्यु मिति',       'Death Date',            pf_d($data['death_date'])],
            ['लाभग्राही',        'Beneficiary',           pf_e($data['beneficiary_name'])],
            ['लाभग्राही नाता',    'Beneficiary Relation',  pf_e($data['beneficiary_relation'])],
        ]];
    }
    if ($data['claim_type'] === 'maternity') {
        $sections[] = ['title'=>'सुत्केरी विवरण / Maternity Details', 'rows'=>[
            ['प्रसूति मिति',      'Delivery Date',         pf_d($data['delivery_date'])],
            ['अस्पताल',          'Hospital',              pf_e($data['hospital_name'])],
        ]];
    }
    if (in_array($data['claim_type'], ['medical','accident'])) {
        $sections[] = ['title'=>'उपचार विवरण / Treatment Details', 'rows'=>[
            ['रोग/चोट विवरण',    'Disease/Injury',        pf_e($data['disease_illness'])],
            ['उपचार मिति',       'Treatment Date',        pf_d($data['treatment_date'])],
            ['अस्पताल/क्लिनिक', 'Hospital/Clinic',       pf_e($data['hospital_clinic'])],
        ]];
    }
    if ($data['claim_type'] === 'insurance') {
        $sections[] = ['title'=>'बीमा विवरण / Insurance Details', 'rows'=>[
            ['पोलिसी नं.',       'Policy No.',            pf_e($data['policy_number'])],
            ['बीमा कम्पनी',      'Insurer',               pf_e($data['insurer_name'])],
        ]];
    }
    break;

/* ════════════════════════════ DIGITAL ════════════════════════════ */
case 'digital':
    $st = $db->prepare("SELECT * FROM digital_service_requests WHERE id=?");
    $st->execute([$id]);  $data = $st->fetch();
    if (!$data) goto NOT_FOUND;
    $svcMap      = ['statement'=>'बैंक स्टेटमेन्ट','atm_card'=>'ATM कार्ड','cheque_book'=>'चेकबुक','mobile_banking'=>'मोबाइल बैंकिङ','internet_banking'=>'इन्टरनेट बैंकिङ','fund_transfer'=>'फण्ड ट्रान्सफर','bill_payment'=>'बिल भुक्तानी','recharge'=>'रिचार्ज','other'=>'अन्य सेवा'];
    $svcLabel    = $data['service_type_np'] ?? ($svcMap[$data['service_type']] ?? $data['service_type']);
    $formTitle   = 'डिजिटल सेवा अनुरोध फारम — ' . $svcLabel;
    $formTitleEn = 'Digital Service Request Form — ' . ($data['service_type'] ?? '');
    $trackId     = $data['tracking_id'] ?? 'DSR-' . str_pad($id, 6, '0', STR_PAD_LEFT);
    $slMap       = ['pending'=>'पेन्डिङ','processing'=>'प्रक्रियामा','completed'=>'सम्पन्न','rejected'=>'अस्वीकृत'];
    $statusLabel = $slMap[$data['status']] ?? $data['status'];
    $svcRows = [
        ['सेवाको प्रकार',     'Service Type',      pf_e($svcLabel)],
        ['खाता नं.',          'Account No.',       pf_e($data['account_number'])],
        ['सम्पर्क माध्यम',   'Preferred Contact', pf_e($data['preferred_contact'])],
    ];
    if ($data['statement_from'] || $data['statement_to'])
        $svcRows[] = ['स्टेटमेन्ट अवधि','Statement Period', pf_e($data['statement_from']).' देखि / to '.pf_e($data['statement_to'])];
    if ($data['biller_name'] || $data['bill_reference'])
        $svcRows[] = ['बिल / बिलर','Biller', pf_e($data['biller_name']).' — '.pf_e($data['bill_reference'])];
    if ($data['recharge_number'] || $data['recharge_amount'])
        $svcRows[] = ['रिचार्ज नं. / रकम','Recharge No./Amount', pf_e($data['recharge_number']).' — '.pf_cur($data['recharge_amount'])];
    if (!empty($data['service_amount']))
        $svcRows[] = ['सेवा रकम','Service Amount', pf_cur($data['service_amount'])];
    if (!empty($data['request_details']))
        $svcRows[] = ['थप विवरण','Additional Details', pf_e($data['request_details'])];
    $sections = [
        ['title'=>'अनुरोधकर्ताको जानकारी / Requester Information', 'rows'=>[
            ['नाम',               'Requester Name',    pf_e($data['requester_name'])],
            ['सदस्य नं.',          'Member ID',         pf_e($data['member_id'])],
            ['फोन',               'Phone',             pf_e($data['phone'])],
            ['इमेल',              'Email',             pf_e($data['email'])],
            ['आवेदन मिति',        'Application Date',  pf_d($data['created_at'])],
        ]],
        ['title'=>'सेवा विवरण / Service Details', 'rows'=>$svcRows],
    ];
    break;

/* ════════════════════════════ ACCOUNT ════════════════════════════ */
case 'account':
    $st = $db->prepare("SELECT * FROM account_applications WHERE id=?");
    $st->execute([$id]);  $data = $st->fetch();
    if (!$data) goto NOT_FOUND;
    $accMap      = ['saving'=>'बचत','current'=>'चल्ती','fixed'=>'मुद्दती','recurring'=>'आवधिक','child'=>'बाल बचत'];
    $accLabel    = $accMap[$data['account_type']] ?? $data['account_type'];
    $formTitle   = 'नयाँ खाता आवेदन फारम — ' . $accLabel . ' खाता';
    $formTitleEn = 'New Account Application Form — ' . ucfirst($data['account_type'] ?? '') . ' Account';
    $trackId     = $data['tracking_id'] ?? 'ACC-' . str_pad($id, 6, '0', STR_PAD_LEFT);
    $slMap       = ['pending'=>'पेन्डिङ','approved'=>'स्वीकृत','rejected'=>'अस्वीकृत'];
    $statusLabel = $slMap[$data['status']] ?? $data['status'];
    $sections = [
        ['title'=>'व्यक्तिगत जानकारी / Personal Information', 'rows'=>[
            ['पूरा नाम (नेपाली)',  'Full Name (Nepali)',    pf_e($data['full_name'])],
            ['पूरा नाम (EN)',      'Full Name (English)',   pf_e($data['full_name_en'])],
            ['जन्म मिति',         'Date of Birth',         pf_e($data['dob_bs'])],
            ['लिङ्ग',             'Gender',                pf_e($data['gender'])],
            ['वैवाहिक अवस्था',    'Marital Status',        pf_e($data['marital_status'])],
            ['पेशा',              'Occupation',            pf_e($data['occupation'])],
        ]],
        ['title'=>'सम्पर्क / ठेगाना / Contact & Address', 'rows'=>[
            ['मोबाइल',            'Mobile',                pf_e($data['mobile'])],
            ['इमेल',              'Email',                 pf_e($data['email'])],
            ['स्थायी ठेगाना',     'Permanent Address',     pf_e($data['permanent_address'])],
            ['अस्थायी ठेगाना',    'Temporary Address',     pf_e($data['temporary_address'])],
            ['शाखा',              'Branch',                pf_e($data['branch'])],
        ]],
        ['title'=>'नागरिकता विवरण / Citizenship Details', 'rows'=>[
            ['नागरिकता नं.',       'Citizenship No.',       pf_e($data['citizenship_no'])],
            ['जारी मिति',          'Issued Date',           pf_e($data['citizenship_issued_date'])],
            ['जारी स्थान',         'Issued Place',          pf_e($data['citizenship_issued_place'])],
            ['बुबाको नाम',         "Father's Name",         pf_e($data['father_name'])],
            ['आमाको नाम',         "Mother's Name",         pf_e($data['mother_name'])],
        ]],
        ['title'=>'खाता विवरण / Account Details', 'rows'=>[
            ['खाता प्रकार',        'Account Type',          pf_e($accLabel)],
            ['प्रारम्भिक निक्षेप','Initial Deposit',        pf_cur($data['initial_deposit'] ?? 0)],
            ['आवेदन मिति',         'Application Date',      pf_d($data['created_at'])],
        ]],
    ];
    if (!empty($data['nominee_name'])) {
        $sections[] = ['title'=>'नामिनी विवरण / Nominee Details', 'rows'=>[
            ['नामिनीको नाम',       'Nominee Name',          pf_e($data['nominee_name'])],
            ['सम्बन्ध',            'Relation',              pf_e($data['nominee_relation'])],
            ['फोन',               'Phone',                 pf_e($data['nominee_phone'])],
        ]];
    }
    break;
}

/* ── Not found ── */
if (false) { NOT_FOUND:
    http_response_code(404);
    echo '<p style="font-family:sans-serif;padding:2rem;color:red;">Record not found (id='.$id.').</p>';
    exit;
}
if (!$data) {
    http_response_code(404);
    echo '<p style="font-family:sans-serif;padding:2rem;color:red;">Record not found.</p>';
    exit;
}

/* ── Document checklist per type ── */
$checklists = [
    'kyc'     => ['नागरिकताको फोटोकपी','फोटो (पासपोर्ट साइज ×२)','सदस्यता कार्ड प्रतिलिपि'],
    'loan'    => ['नागरिकताको फोटोकपी','आय प्रमाण / तलब स्लिप','धितो सम्बन्धी कागजात','जमानीको नागरिकताको प्रति'],
    'welfare' => ['नागरिकताको फोटोकपी','सम्बन्धित प्रमाण कागजात','बैंक खाता विवरण'],
    'digital' => ['नागरिकताको फोटोकपी','खाता नम्बर प्रमाण'],
    'account' => ['नागरिकताको फोटोकपी','फोटो (पासपोर्ट साइज ×२)','ठेगाना प्रमाण'],
];
$checklist = $checklists[$type];
?>
<!DOCTYPE html>
<html lang="ne">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo pf_e($formTitle); ?> — <?php echo pf_e($trackId); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@300;400;500;600;700;800&family=Inter:wght@300;400;500;600;700&display=swap">

<style>
/* ═══ RESET & BASE ═══ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --c-primary:   #1a5f2a;
    --c-dark:      #0d3d1a;
    --c-border:    #c5dace;
    --c-section:   #edf6f0;
    --c-muted:     #6b7280;
    --c-text:      #111827;
    --c-zebra:     #f8fcfa;
    --c-warn-bg:   #fffbeb;
    --c-warn-text: #78350f;
}

body {
    font-family: 'Noto Sans Devanagari','Inter',sans-serif;
    font-size: 13px;
    color: var(--c-text);
    background: #dce8df;
    line-height: 1.55;
}

/* ── Screen wrapper ── */
.pf-wrap {
    max-width: 860px;
    margin: 24px auto 40px;
    background: #fff;
    border: 1px solid var(--c-border);
    box-shadow: 0 6px 32px rgba(0,0,0,.14);
    border-radius: 4px;
    overflow: hidden;
}

/* ── Top action bar (screen only) ── */
.pf-topbar {
    background: #1e293b;
    color: #f1f5f9;
    padding: 10px 20px;
    display: flex; align-items: center; justify-content: space-between; gap: 10px; flex-wrap: wrap;
}
.pf-topbar-id { font-size: 11.5px; opacity: .75; font-family: monospace; }
.pf-btn-row  { display: flex; gap: 8px; }
.pf-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 16px; border-radius: 6px; font-size: 12px; font-weight: 600;
    cursor: pointer; border: none; text-decoration: none; font-family: inherit; transition: opacity .15s;
}
.pf-btn:hover { opacity: .85; }
.pf-btn-green { background: var(--c-primary); color: #fff; }
.pf-btn-ghost { background: transparent; color: #f1f5f9; border: 1px solid #475569; }

/* ── Body ── */
.pf-body { padding: 26px 30px 30px; }

/* ── Org header ── */
.pf-org-header {
    display: grid;
    grid-template-columns: 76px 1fr 92px;
    align-items: center;
    gap: 14px;
    border-bottom: 3px solid var(--c-primary);
    padding-bottom: 14px;
    margin-bottom: 16px;
}
.pf-logo-box {
    width: 76px; height: 76px;
    border: 1.5px solid var(--c-border); border-radius: 8px;
    background: #fff; display: flex; align-items: center; justify-content: center; overflow: hidden;
}
.pf-logo-box img { max-width: 100%; max-height: 100%; object-fit: contain; }
.pf-logo-icon { font-size: 30px; color: var(--c-primary); opacity: .65; }
.pf-org-name { font-size: 16.5px; font-weight: 800; color: var(--c-primary); line-height: 1.25; }
.pf-org-meta { font-size: 11px; color: var(--c-muted); margin-top: 3px; }
.pf-org-meta span { display: inline-block; margin-right: 10px; }
.pf-photo-box {
    width: 92px; height: 112px;
    border: 1.5px solid var(--c-border); border-radius: 4px;
    background: #f9fafb; display: flex; align-items: center; justify-content: center;
    font-size: 10px; color: var(--c-muted); text-align: center; overflow: hidden;
}
.pf-photo-box img { width: 100%; height: 100%; object-fit: cover; }

/* ── Title banner ── */
.pf-banner {
    background: var(--c-primary);
    color: #fff;
    padding: 10px 16px;
    border-radius: 5px;
    margin-bottom: 18px;
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;
}
.pf-banner-title    { font-size: 14px; font-weight: 800; line-height: 1.3; }
.pf-banner-subtitle { font-size: 11px; opacity: .85; margin-top: 2px; }
.pf-pills           { display: flex; gap: 7px; flex-wrap: wrap; align-items: center; }
.pf-pill {
    background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.3);
    border-radius: 999px; padding: 3px 11px; font-size: 11px; font-weight: 600; white-space: nowrap;
}
.pf-pill-status { background: rgba(255,255,255,.92); color: var(--c-dark); }

/* ── Sections ── */
.pf-section { margin-bottom: 15px; border: 1px solid var(--c-border); border-radius: 4px; overflow: hidden; }
.pf-section-head {
    background: var(--c-section);
    border-left: 4px solid var(--c-primary);
    padding: 7px 12px;
    font-size: 11.5px; font-weight: 700; color: var(--c-primary);
    text-transform: uppercase; letter-spacing: .35px;
}
.pf-tbl { width: 100%; border-collapse: collapse; }
.pf-tbl th, .pf-tbl td { padding: 6px 11px; border-bottom: 1px solid var(--c-border); vertical-align: top; }
.pf-tbl tr:last-child th, .pf-tbl tr:last-child td { border-bottom: none; }
.pf-tbl tr:nth-child(even) td { background: var(--c-zebra); }
.pf-tbl th { width: 32%; background: #f3f9f5; font-weight: 600; color: #374151; }
.pf-tbl .lnp { display: block; font-size: 12px; font-weight: 700; color: #1f2937; }
.pf-tbl .len { display: block; font-size: 10.5px; color: var(--c-muted); }
.pf-tbl td.empty { color: #9ca3af; font-style: italic; font-size: 12px; }

/* ── Declaration ── */
.pf-decl {
    border: 1px solid #fde68a; border-radius: 5px;
    background: var(--c-warn-bg); padding: 12px 15px;
    margin: 18px 0 16px; font-size: 12px;
}
.pf-decl-title { font-weight: 700; color: #92400e; margin-bottom: 6px; font-size: 12.5px; }
.pf-decl p { color: var(--c-warn-text); line-height: 1.65; }
.pf-sig-row {
    display: flex; gap: 24px; margin-top: 14px; flex-wrap: wrap;
}
.pf-sig-box { flex: 1; min-width: 140px; }
.pf-sig-line { border-bottom: 1.5px solid #374151; height: 38px; margin-bottom: 4px; }
.pf-sig-label { font-size: 10.5px; color: var(--c-muted); }

/* ── Office section ── */
.pf-office { border: 2px solid var(--c-primary); border-radius: 5px; overflow: hidden; margin-top: 6px; }
.pf-office-head {
    background: var(--c-primary); color: #fff;
    padding: 8px 15px; font-size: 12.5px; font-weight: 700;
    display: flex; align-items: center; justify-content: space-between;
}
.pf-office-body { padding: 14px 15px 12px; }
.pf-officers { display: grid; grid-template-columns: repeat(3,1fr); gap: 16px; margin-bottom: 14px; }
.pf-officer-role {
    font-weight: 700; font-size: 11.5px; color: var(--c-primary);
    text-transform: uppercase; letter-spacing: .3px; margin-bottom: 10px;
}
.pf-officer-field { margin-bottom: 9px; }
.pf-field-line { border-bottom: 1px solid #9ca3af; height: 30px; margin-bottom: 3px; }
.pf-field-label { font-size: 10.5px; color: var(--c-muted); }

/* Checklist */
.pf-checklist-head { font-size: 11.5px; font-weight: 700; color: #374151; margin-bottom: 7px; border-top: 1px solid #e5e7eb; padding-top: 10px; }
.pf-check-row { display: flex; flex-wrap: wrap; gap: 10px 20px; }
.pf-check-item { display: flex; align-items: center; gap: 7px; font-size: 12px; }
.pf-checkbox { width: 14px; height: 14px; border: 1.5px solid var(--c-primary); border-radius: 2px; flex-shrink: 0; display: inline-block; }
.pf-seal {
    width: 108px; height: 76px;
    border: 1.5px dashed #9ca3af; border-radius: 6px;
    display: flex; align-items: center; justify-content: center;
    font-size: 10.5px; color: #9ca3af; text-align: center; padding: 6px;
    float: right; margin-top: -38px;
}

/* ── Footer ── */
.pf-foot {
    display: flex; justify-content: space-between; align-items: center;
    flex-wrap: wrap; gap: 8px;
    margin-top: 14px; padding-top: 10px; border-top: 1px dashed var(--c-border);
}
.pf-foot-text { font-size: 10.5px; color: var(--c-muted); }
.pf-track-stamp {
    font-size: 11px; font-weight: 700; letter-spacing: 1px; color: var(--c-primary);
    font-family: monospace; border: 1px dashed var(--c-primary); padding: 3px 10px; border-radius: 4px;
}

/* ═══ PRINT ═══ */
@media print {
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
    body { background: #fff !important; }
    .pf-topbar { display: none !important; }
    .pf-wrap {
        max-width: 100% !important; margin: 0 !important;
        box-shadow: none !important; border: none !important; border-radius: 0 !important;
    }
    .pf-body { padding: 12px 16px 18px !important; }
    .pf-section, .pf-office, .pf-decl { page-break-inside: avoid; }
    @page { size: A4; margin: 13mm 11mm 15mm 11mm; }
}

/* ═══ MOBILE ═══ */
@media (max-width: 620px) {
    .pf-body { padding: 14px; }
    .pf-org-header { grid-template-columns: 60px 1fr; }
    .pf-photo-box { display: none; }
    .pf-officers { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<div class="pf-wrap">

    <!-- ── Top action bar ── -->
    <div class="pf-topbar">
        <span class="pf-topbar-id"><i class="fas fa-file-alt" style="margin-right:5px;"></i><?php echo pf_e($trackId); ?> &mdash; <?php echo pf_e($formTitle); ?></span>
        <div class="pf-btn-row">
            <a href="javascript:history.back()" class="pf-btn pf-btn-ghost">
                <i class="fas fa-arrow-left"></i> फिर्ता
            </a>
            <button onclick="window.print()" class="pf-btn pf-btn-green">
                <i class="fas fa-print"></i> Print / PDF डाउनलोड
            </button>
        </div>
    </div>

    <div class="pf-body">

        <!-- ── Org Header ── -->
        <div class="pf-org-header">
            <div class="pf-logo-box">
                <?php if ($siteLogo): ?>
                <img src="<?php echo pf_e($siteLogo); ?>" alt="Logo"
                     onerror="this.style.display='none';this.parentElement.innerHTML='<i class=\'fas fa-landmark pf-logo-icon\'></i>'">
                <?php else: ?>
                <i class="fas fa-landmark pf-logo-icon"></i>
                <?php endif; ?>
            </div>
            <div>
                <div class="pf-org-name"><?php echo pf_e($siteName); ?></div>
                <div class="pf-org-meta">
                    <?php if ($siteAddress): ?><span><i class="fas fa-location-dot"></i> <?php echo pf_e($siteAddress); ?></span><?php endif; ?>
                    <?php if ($sitePhone): ?><span><i class="fas fa-phone"></i> <?php echo pf_e($sitePhone); ?></span><?php endif; ?>
                    <?php if ($siteEmail): ?><span><i class="fas fa-envelope"></i> <?php echo pf_e($siteEmail); ?></span><?php endif; ?>
                    <?php if ($siteRegNo): ?><span>दर्ता नं.: <?php echo pf_e($siteRegNo); ?></span><?php endif; ?>
                </div>
            </div>
            <div class="pf-photo-box">
                <?php if ($photoPath): ?>
                <img src="<?php echo pf_e($photoPath); ?>" alt="Photo">
                <?php elseif ($type === 'kyc' || $type === 'account'): ?>
                <span>फोटो<br>Photo<br><small>(Passport Size)</small></span>
                <?php else: ?>
                <span style="font-size:22px;opacity:.3;"><i class="fas fa-building-user"></i></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Form Title Banner ── -->
        <div class="pf-banner">
            <div>
                <div class="pf-banner-title"><?php echo pf_e($formTitle); ?></div>
                <div class="pf-banner-subtitle"><?php echo pf_e($formTitleEn); ?></div>
            </div>
            <div class="pf-pills">
                <span class="pf-pill">Tracking: <?php echo pf_e($trackId); ?></span>
                <span class="pf-pill">Print Date: <?php echo $today; ?></span>
                <span class="pf-pill pf-pill-status"><?php echo pf_e($statusLabel); ?></span>
            </div>
        </div>

        <!-- ── Data Sections ── -->
        <?php foreach ($sections as $sec): ?>
        <div class="pf-section">
            <div class="pf-section-head"><?php echo pf_e($sec['title']); ?></div>
            <table class="pf-tbl">
                <?php foreach ($sec['rows'] as $row):
                    [$labelNp, $labelEn, $val] = count($row) === 3 ? $row : [$row[0], '', $row[1]];
                    $empty = ($val === '' || $val === '—');
                ?>
                <tr>
                    <th>
                        <span class="lnp"><?php echo pf_e($labelNp); ?></span>
                        <?php if ($labelEn): ?><span class="len"><?php echo pf_e($labelEn); ?></span><?php endif; ?>
                    </th>
                    <td class="<?php echo $empty ? 'empty' : ''; ?>">
                        <?php echo $empty ? '—' : $val; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        <?php endforeach; ?>

        <!-- ── Declaration ── -->
        <div class="pf-decl">
            <div class="pf-decl-title"><i class="fas fa-pen-nib" style="margin-right:6px;"></i>आवेदकको घोषणा / Applicant's Declaration</div>
            <p>मैले माथि भरेको सम्पूर्ण जानकारी सत्य, सही र पूर्ण छ भनी म घोषणा गर्दछु। यो आवेदन डिजिटल माध्यम मार्फत पेश गरिएको हो एवं सहकारी ऐन, नियमावली तथा संस्थाका सम्पूर्ण नियम र शर्तहरू मैले स्वीकार गरेको छु। कुनै पनि जानकारी गलत भएमा संस्थाले कारवाही गर्ने अधिकार राख्छ।<br>
            <small>I hereby declare that all information provided above is true, correct and complete. This application was submitted through digital medium and I accept all applicable rules, regulations and terms of the cooperative act and institution. I understand that any false information may result in rejection or legal action by the institution.</small></p>
            <div class="pf-sig-row">
                <div class="pf-sig-box">
                    <div class="pf-sig-line"></div>
                    <div class="pf-sig-label">आवेदकको दस्तखत / Applicant's Signature</div>
                </div>
                <div class="pf-sig-box">
                    <div class="pf-sig-line"></div>
                    <div class="pf-sig-label">औंठाछाप (बायाँ बुढी औंला) / Left Thumb Print</div>
                </div>
                <div class="pf-sig-box">
                    <div class="pf-sig-line"></div>
                    <div class="pf-sig-label">मिति / Date</div>
                </div>
            </div>
        </div>

        <!-- ── For Office Use Only ── -->
        <div class="pf-office">
            <div class="pf-office-head">
                <span><i class="fas fa-building-columns" style="margin-right:8px;"></i>कार्यालय प्रयोगको लागि मात्र / For Office Use Only</span>
                <span style="font-size:11px;opacity:.75;">Print Date: <?php echo $today; ?></span>
            </div>
            <div class="pf-office-body">
                <div class="pf-officers">

                    <div>
                        <div class="pf-officer-role"><i class="fas fa-clipboard-check" style="margin-right:5px;"></i>जाँच गर्ने / Verified By</div>
                        <div class="pf-officer-field"><div class="pf-field-line"></div><div class="pf-field-label">नाम / Name</div></div>
                        <div class="pf-officer-field"><div class="pf-field-line"></div><div class="pf-field-label">पद / Designation</div></div>
                        <div class="pf-officer-field"><div class="pf-field-line"></div><div class="pf-field-label">दस्तखत / Signature &amp; मिति / Date</div></div>
                    </div>

                    <div>
                        <div class="pf-officer-role"><i class="fas fa-user-check" style="margin-right:5px;"></i>समीक्षा गर्ने / Reviewed By</div>
                        <div class="pf-officer-field"><div class="pf-field-line"></div><div class="pf-field-label">नाम / Name</div></div>
                        <div class="pf-officer-field"><div class="pf-field-line"></div><div class="pf-field-label">पद / Designation</div></div>
                        <div class="pf-officer-field"><div class="pf-field-line"></div><div class="pf-field-label">दस्तखत / Signature &amp; मिति / Date</div></div>
                    </div>

                    <div>
                        <div class="pf-officer-role"><i class="fas fa-stamp" style="margin-right:5px;"></i>स्वीकृत गर्ने / Approved By</div>
                        <div class="pf-officer-field"><div class="pf-field-line"></div><div class="pf-field-label">नाम / Name</div></div>
                        <div class="pf-officer-field"><div class="pf-field-line"></div><div class="pf-field-label">पद / Designation</div></div>
                        <div class="pf-officer-field"><div class="pf-field-line"></div><div class="pf-field-label">दस्तखत / Signature &amp; मिति / Date</div></div>
                    </div>

                </div>

                <!-- Document checklist -->
                <div class="pf-checklist-head">कागजात जाँच सूची / Document Checklist</div>
                <div class="pf-check-row">
                    <?php foreach ($checklist as $item): ?>
                    <div class="pf-check-item"><span class="pf-checkbox"></span><span><?php echo pf_e($item); ?></span></div>
                    <?php endforeach; ?>
                    <div class="pf-check-item"><span class="pf-checkbox"></span><span>अन्य / Other: _____________________</span></div>
                </div>

                <!-- Office seal -->
                <div class="pf-seal">कार्यालय छाप<br>Office Seal</div>
                <div style="clear:both;"></div>
            </div>
        </div>

        <!-- ── Page footer ── -->
        <div class="pf-foot">
            <div class="pf-foot-text">
                <?php echo pf_e($siteName); ?>
                <?php if ($siteAddress): ?> &nbsp;|&nbsp; <?php echo pf_e($siteAddress); ?><?php endif; ?>
                <?php if ($sitePhone): ?> &nbsp;|&nbsp; <?php echo pf_e($sitePhone); ?><?php endif; ?>
            </div>
            <div class="pf-track-stamp"><?php echo pf_e($trackId); ?></div>
        </div>

    </div><!-- /.pf-body -->
</div><!-- /.pf-wrap -->

<script>
if (new URLSearchParams(window.location.search).get('autoprint') === '1') {
    window.addEventListener('load', () => setTimeout(() => window.print(), 500));
}
</script>
</body>
</html>
