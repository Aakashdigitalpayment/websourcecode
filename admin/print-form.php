<?php
/**
 * ═══════════════════════════════════════════════════════════
 *  UNIVERSAL PRINT FORM — Bank-style printable/PDF form
 *  Supports: kyc | loan | welfare | digital | account | honor
 *            | appointment | grievance | job
 *  URL: admin/print-form.php?type=kyc&id=5
 * ═══════════════════════════════════════════════════════════
 */
define('SECURE_ACCESS', true);
require_once __DIR__ . '/includes/admin-page-boot.php';

$db   = getDB();
$type = trim($_GET['type'] ?? '');
$id   = (int)($_GET['id'] ?? 0);

$allowedTypes = ['kyc', 'loan', 'welfare', 'digital', 'account', 'honor', 'appointment', 'grievance', 'job'];
if (!in_array($type, $allowedTypes, true) || $id <= 0) {
    http_response_code(400);
    echo '<p style="font-family:sans-serif;padding:2rem;color:red;">Invalid request. Use ?type=kyc|loan|welfare|digital|account|honor|appointment|grievance|job&amp;id=N</p>';
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
$pfPrimary = function_exists('coopThemeColorHex') ? coopThemeColorHex() : trim((string) getSetting('primary_color', '#1a5f2a'));
if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $pfPrimary)) {
    $pfPrimary = '#1a5f2a';
}
$pfPrimaryDark = trim((string) getSetting('primary_dark', ''));
if (!preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $pfPrimaryDark)) {
    $pfPrimaryDark = '#0d3d1a';
}

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
function pf_url(?string $path): string {
    $path = trim((string)$path);
    if ($path === '') return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    return rtrim(SITE_URL, '/') . '/' . ltrim($path, '/');
}
/** Add a print row; skip when $onlyFilled and value empty. */
function pf_add_row(array &$rows, string $np, string $en, $val, bool $onlyFilled = false): void {
    $s = trim((string)($val ?? ''));
    if ($onlyFilled && $s === '') return;
    $rows[] = [$np, $en, $s === '' ? '—' : pf_e($s)];
}
/** Compose address from structured columns, fall back to flat text. */
function pf_addr_line(array $data, string $prefix, string $flatKey = ''): string {
    $flatKey = $flatKey !== '' ? $flatKey : ($prefix . '_address');
    $flat = trim((string)($data[$flatKey] ?? ''));
    $parts = [];
    foreach (['tole', 'ward', 'municipality', 'district', 'province'] as $k) {
        $v = trim((string)($data[$prefix . '_' . $k] ?? ''));
        if ($v === '') continue;
        $parts[] = ($k === 'ward') ? ('वडा ' . $v) : $v;
    }
    $composed = implode(', ', $parts);
    if ($flat !== '' && $composed !== '' && mb_stripos($flat, $composed) === false) {
        return $flat . ' · ' . $composed;
    }
    return $flat !== '' ? $flat : $composed;
}
/** Push image/file docs into $extraDocs from [np, en, path] defs (path may be CSV). */
function pf_collect_docs(array &$extraDocs, array $defs): void {
    foreach ($defs as $def) {
        $lnp = (string)($def[0] ?? '');
        $len = (string)($def[1] ?? '');
        $raw = trim((string)($def[2] ?? ''));
        if ($raw === '') continue;
        $paths = preg_split('/\s*,\s*/', $raw) ?: [$raw];
        $i = 0;
        foreach ($paths as $p) {
            $p = trim((string)$p);
            if ($p === '') continue;
            $u = pf_url($p);
            if ($u === '') continue;
            $i++;
            $extraDocs[] = [
                'label_np' => $i > 1 ? ($lnp . ' #' . $i) : $lnp,
                'label_en' => $i > 1 ? ($len . ' #' . $i) : $len,
                'url' => $u,
                'is_file' => !preg_match('/\.(jpe?g|png|gif|webp)$/i', $p),
            ];
        }
    }
}

/* ── Data fetch & section builder ── */
$data        = null;
$formTitle   = '';
$formTitleEn = '';
$trackId     = '';
$statusLabel = '';
$photoPath   = '';
$sigPath     = '';
$leftThumbPath = '';
$rightThumbPath = '';
$sections    = [];   // [ ['title'=>'…', 'rows'=>… ] ] or kind=family|income_expense|docs
$extraDocs   = [];

switch ($type) {

/* ════════════════════════════ KYC ════════════════════════════ */
case 'kyc':
    $st = $db->prepare("SELECT * FROM kyc_applications WHERE id=?");
    $st->execute([$id]);  $data = $st->fetch(PDO::FETCH_ASSOC);
    if (!$data) goto NOT_FOUND;
    $formTitle   = 'व्यक्तिगत सदस्य पहिचान फारम (केवाइएम / KYM)';
    $formTitleEn = 'Know Your Member (KYM) Application Form';
    $trackId     = $data['tracking_id'] ?? 'KYC-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
    $slMap       = ['pending'=>'पेन्डिङ','approved'=>'स्वीकृत','rejected'=>'अस्वीकृत','incomplete'=>'अपूर्ण','partial'=>'आंशिक'];
    $statusLabel = $slMap[$data['status'] ?? ''] ?? (string)($data['status'] ?? '');
    if (!empty($data['photo'])) $photoPath = pf_url($data['photo']);

    $aml = [];
    $amlRaw = trim((string)($data['aml_details_json'] ?? ''));
    if ($amlRaw !== '') {
        $decoded = json_decode($amlRaw, true);
        if (is_array($decoded)) $aml = $decoded;
    }
    $familyRows = [];
    $famRaw = trim((string)($data['family_details_json'] ?? ''));
    if ($famRaw !== '') {
        $fd = json_decode($famRaw, true);
        if (is_array($fd)) {
            foreach ($fd as $fr) {
                if (!is_array($fr)) continue;
                $familyRows[] = [
                    'relation' => trim((string)($fr['relation'] ?? '')),
                    'name'     => trim((string)($fr['name'] ?? '')),
                    'phone'    => trim((string)($fr['phone'] ?? '')),
                ];
            }
        }
    }
    $incomeItems  = (isset($aml['income_items']) && is_array($aml['income_items'])) ? $aml['income_items'] : [];
    $expenseItems = (isset($aml['expense_items']) && is_array($aml['expense_items'])) ? $aml['expense_items'] : [];

    $personal = [];
    pf_add_row($personal, 'पूरा नाम (नेपाली)', 'Full Name (Nepali)', $data['full_name'] ?? '');
    pf_add_row($personal, 'पूरा नाम (अंग्रेजी)', 'Full Name (English)', $data['full_name_en'] ?? '');
    pf_add_row($personal, 'सदस्यता नं.', 'Member ID', $data['member_id'] ?? '');
    pf_add_row($personal, 'Tracking ID', 'Tracking ID', $trackId);
    pf_add_row($personal, 'जन्म मिति (BS)', 'Date of Birth (BS)', $data['dob_bs'] ?? '');
    pf_add_row($personal, 'जन्म मिति (AD)', 'Date of Birth (AD)', $data['dob_ad'] ?? '');
    pf_add_row($personal, 'लिङ्ग', 'Gender', $data['gender'] ?? '');
    pf_add_row($personal, 'वैवाहिक अवस्था', 'Marital Status', $data['marital_status'] ?? '');
    pf_add_row($personal, 'राष्ट्रियता', 'Nationality', $data['nationality'] ?? 'नेपाली');
    pf_add_row($personal, 'मोबाइल', 'Mobile', $data['mobile'] ?? '');
    pf_add_row($personal, 'इमेल', 'Email', $data['email'] ?? '');
    pf_add_row($personal, 'आवेदन मिति', 'Application Date', pf_d($data['created_at'] ?? ''));
    if (!empty($data['want_id_card'])) {
        pf_add_row($personal, 'परिचय पत्र चाहियो', 'Want ID Card', 'हो / Yes');
    }

    $idRows = [];
    pf_add_row($idRows, 'नागरिकता नं.', 'Citizenship No.', $data['citizenship_no'] ?? '');
    pf_add_row($idRows, 'जारी मिति', 'Issued Date', $data['citizenship_issued_date'] ?? '');
    pf_add_row($idRows, 'जारी जिल्ला', 'Issued District', $data['citizenship_issued_place'] ?? '');
    pf_add_row($idRows, 'National ID नं.', 'National ID No.', $data['national_id_number'] ?? '');
    pf_add_row($idRows, 'Passport नं.', 'Passport No.', $aml['passport_no'] ?? '', true);
    pf_add_row($idRows, 'PAN नं.', 'PAN No.', $aml['pan_no'] ?? '', true);
    pf_add_row($idRows, 'Driving License नं.', 'Driving License', $aml['driving_license_no'] ?? '', true);
    pf_add_row($idRows, 'शैक्षिक योग्यता', 'Education', $aml['education_qualification'] ?? '', true);
    pf_add_row($idRows, 'धर्म', 'Religion', $aml['religion'] ?? '', true);
    pf_add_row($idRows, 'जात', 'Caste', $aml['caste'] ?? '', true);

    $familyCol = [];
    pf_add_row($familyCol, 'बुबाको नाम', "Father's Name", $data['father_name'] ?? '');
    pf_add_row($familyCol, 'आमाको नाम', "Mother's Name", $data['mother_name'] ?? '');
    pf_add_row($familyCol, 'हजुरबुबाको नाम', "Grandfather's Name", $data['grandfather_name'] ?? '');
    pf_add_row($familyCol, 'पति/पत्नीको नाम', "Spouse's Name", $data['spouse_name'] ?? '');

    $addrRows = [];
    $permLine = pf_addr_line($data, 'permanent', 'permanent_address');
    if ($permLine === '') {
        $permLine = trim((string)($data['address'] ?? ''));
    }
    pf_add_row($addrRows, 'स्थायी ठेगाना', 'Permanent Address', $permLine);
    pf_add_row($addrRows, 'अस्थायी ठेगाना', 'Temporary Address', pf_addr_line($data, 'temporary', 'temporary_address'));
    pf_add_row($addrRows, 'स्थायी प्रदेश', 'Permanent Province', $data['permanent_province'] ?? '', true);
    pf_add_row($addrRows, 'स्थायी जिल्ला', 'Permanent District', $data['permanent_district'] ?? '', true);
    pf_add_row($addrRows, 'स्थायी न.पा./गा.पा.', 'Permanent Municipality', $data['permanent_municipality'] ?? '', true);
    pf_add_row($addrRows, 'स्थायी वडा', 'Permanent Ward', $data['permanent_ward'] ?? '', true);
    pf_add_row($addrRows, 'स्थायी टोल', 'Permanent Tole', $data['permanent_tole'] ?? '', true);
    pf_add_row($addrRows, 'अस्थायी प्रदेश', 'Temporary Province', $data['temporary_province'] ?? '', true);
    pf_add_row($addrRows, 'अस्थायी जिल्ला', 'Temporary District', $data['temporary_district'] ?? '', true);
    pf_add_row($addrRows, 'अस्थायी न.पा./गा.पा.', 'Temporary Municipality', $data['temporary_municipality'] ?? '', true);
    pf_add_row($addrRows, 'अस्थायी वडा', 'Temporary Ward', $data['temporary_ward'] ?? '', true);
    pf_add_row($addrRows, 'अस्थायी टोल', 'Temporary Tole', $data['temporary_tole'] ?? '', true);
    pf_add_row($addrRows, 'घरधनीको नाम', 'Landlord Name', $aml['landlord_name'] ?? '', true);
    pf_add_row($addrRows, 'घरधनी सम्पर्क', 'Landlord Contact', $aml['landlord_contact'] ?? '', true);
    pf_add_row($addrRows, 'भाडामा बस्ने', 'Is Rented', $aml['is_rented'] ?? '', true);
    pf_add_row($addrRows, 'मतदाता परिचयपत्र नं.', 'Voter ID', $aml['voter_id_card_no'] ?? '', true);
    pf_add_row($addrRows, 'मतदान स्थल', 'Polling Station', $aml['polling_station'] ?? '', true);
    pf_add_row($addrRows, 'Map ठेगाना', 'Map Address', $aml['map_resolved_address'] ?? '', true);
    pf_add_row($addrRows, 'देशान्तर/अक्षांश', 'Lat/Lng', $aml['longitude_latitude'] ?? '', true);

    $occRows = [];
    pf_add_row($occRows, 'पेशा', 'Occupation', $data['occupation'] ?? '');
    pf_add_row($occRows, 'संस्था / संगठन', 'Organization', $data['organization_name'] ?? $data['organization'] ?? '');
    pf_add_row($occRows, 'मासिक आय', 'Monthly Income', $data['monthly_income'] ?? '');
    pf_add_row($occRows, 'पेशा/व्यवसाय स्थान', 'Occupation Location', $aml['occupation_location'] ?? '', true);
    pf_add_row($occRows, 'व्यवसाय नाम', 'Business Name', $aml['occupation_business_name'] ?? '', true);
    pf_add_row($occRows, 'Business PAN नं.', 'Business PAN', $aml['business_pan_no'] ?? '', true);
    pf_add_row($occRows, 'Business दर्ता प्रकार', 'Reg. Type', $aml['business_registration_type'] ?? '', true);
    pf_add_row($occRows, 'Business दर्ता नं.', 'Reg. No.', $aml['business_registration_no'] ?? '', true);
    pf_add_row($occRows, 'दर्ता निकाय', 'Reg. Office', $aml['business_registration_office'] ?? '', true);
    pf_add_row($occRows, 'Business दर्ता मिति (BS)', 'Reg. Date (BS)', $aml['business_registration_date_bs'] ?? '', true);
    pf_add_row($occRows, 'व्यवसाय प्रकृति', 'Business Nature', $aml['business_nature'] ?? '', true);
    pf_add_row($occRows, 'अनुमानित वार्षिक आय', 'Est. Annual Income', $aml['estimated_annual_income'] ?? '', true);

    $coopRows = [];
    pf_add_row($coopRows, 'खाता प्रकार', 'Account Type', $data['account_type'] ?? '');
    pf_add_row($coopRows, 'सेवा कार्यालय', 'Service Office', isset($data['branch']) ? str_replace('_', ' ', (string)$data['branch']) : '');
    pf_add_row($coopRows, 'सदस्यता उद्देश्य', 'Membership Purpose', $aml['member_purpose'] ?? '', true);
    pf_add_row($coopRows, 'आफू अन्य सहकारी सदस्य', 'Other Coop Member', $aml['self_other_coop_member'] ?? '', true);
    pf_add_row($coopRows, 'अन्य सहकारी विवरण', 'Other Coop Details', $aml['self_other_coop_details'] ?? '', true);
    pf_add_row($coopRows, 'परिवार यसै सहकारीमा', 'Family in Same Coop', $aml['family_same_coop_member'] ?? '', true);
    pf_add_row($coopRows, 'परिवार सदस्य विवरण', 'Family Coop Details', $aml['family_same_coop_details'] ?? '', true);
    pf_add_row($coopRows, 'परिवार सदस्य नाम', 'Family Member Name', $aml['family_same_member_name'] ?? '', true);
    pf_add_row($coopRows, 'परिवार सदस्य ID', 'Family Member ID', $aml['family_same_member_id'] ?? '', true);
    pf_add_row($coopRows, 'PEP स्थिति', 'Politically Exposed', $aml['politically_exposed'] ?? '', true);
    pf_add_row($coopRows, 'अपराध घोषणा', 'Past Crime Declared', $aml['past_crime_declared'] ?? '', true);

    $finRows = [];
    pf_add_row($finRows, 'वार्षिक डेबिट/क्रेडिट', 'Annual Debit/Credit', $aml['annual_debit_credit_estimate'] ?? '', true);
    pf_add_row($finRows, 'वार्षिक कारोबार संख्या', 'Annual Turnover Count', $aml['annual_turnover_numbers'] ?? '', true);
    pf_add_row($finRows, 'वार्षिक जम्मा अनुमान', 'Annual Deposit Est.', $aml['annual_deposit_estimate'] ?? '', true);
    pf_add_row($finRows, 'संस्थासँग ऋणधन अनुमान', 'Institution Debt Est.', $aml['institution_debt_estimate'] ?? '', true);
    pf_add_row($finRows, 'वार्षिक पारिवारिक आम्दानी', 'Annual Family Income', $aml['annual_family_income'] ?? '', true);
    pf_add_row($finRows, 'सम्पत्ति / Net Worth', 'Net Worth', $aml['net_worth_details'] ?? '', true);
    pf_add_row($finRows, 'नजिकको व्यक्ति', 'Nearest Person', $aml['nearest_person_name'] ?? '', true);
    pf_add_row($finRows, 'नजिकको व्यक्ति नाता', 'Nearest Relation', $aml['nearest_person_relation'] ?? '', true);
    pf_add_row($finRows, 'अन्य संलग्न कागजात', 'Other Attached Docs', $aml['other_attached_docs'] ?? '', true);

    $nomRows = [];
    pf_add_row($nomRows, 'हकवाला नाम', 'Nominee Name', $aml['nominee_name'] ?? '', true);
    pf_add_row($nomRows, 'हकवाला जन्म मिति', 'Nominee DOB', $aml['nominee_dob'] ?? '', true);
    pf_add_row($nomRows, 'हकवाला नागरिकता नं.', 'Nominee Citizenship', $aml['nominee_citizenship_no'] ?? '', true);
    pf_add_row($nomRows, 'हकवालासँग नाता', 'Nominee Relation', $aml['nominee_relation'] ?? '', true);
    pf_add_row($nomRows, 'हकवाला जारी जिल्ला', 'Nominee Issue District', $aml['nominee_issue_district'] ?? '', true);
    pf_add_row($nomRows, 'हकवाला जारी मिति', 'Nominee Issue Date', $aml['nominee_issue_date'] ?? '', true);
    pf_add_row($nomRows, 'हकवाला स्थायी ठेगाना', 'Nominee Permanent Addr.', $aml['nominee_permanent_address'] ?? '', true);
    pf_add_row($nomRows, 'हकवाला अस्थायी ठेगाना', 'Nominee Temporary Addr.', $aml['nominee_temporary_address'] ?? '', true);

    $riskRows = [];
    pf_add_row($riskRows, 'जोखिम श्रेणी', 'Risk Category', $data['risk_category'] ?? '', true);
    pf_add_row($riskRows, 'KYC verified at', 'KYC Verified At', $data['kyc_verified_at'] ?? '', true);
    pf_add_row($riskRows, 'Risk review due', 'Risk Review Due', $data['risk_review_due_at'] ?? '', true);
    pf_add_row($riskRows, 'Risk review status', 'Risk Review Status', $data['risk_review_status'] ?? '', true);
    if (isset($data['photo_quality_score']) && (int)$data['photo_quality_score'] > 0) {
        pf_add_row($riskRows, 'Photo quality score', 'Photo Quality', (string)(int)$data['photo_quality_score']);
    }
    pf_add_row($riskRows, 'Admin टिप्पणी', 'Admin Remarks', $data['remarks'] ?? '', true);

    $sections = [
        ['title' => 'क. व्यक्तिगत विवरण / Personal Information', 'rows' => $personal],
        ['title' => 'ख. परिचय पत्र विवरण / Identity Documents', 'rows' => $idRows],
        ['title' => 'ग. परिवार (स्तम्भ) / Family Columns', 'rows' => $familyCol],
    ];
    if (!empty($familyRows)) {
        $sections[] = ['title' => 'घ. पारिवारिक विवरण (तालिका) / Family Details', 'kind' => 'family', 'family' => $familyRows];
    }
    $sections[] = ['title' => 'ङ. ठेगाना / Address & Residence', 'rows' => $addrRows];
    $sections[] = ['title' => 'च. पेशा / व्यवसाय / Occupation & Business', 'rows' => $occRows];
    $sections[] = ['title' => 'छ. सहकारी सदस्यता / Cooperative Membership', 'rows' => $coopRows];
    if (!empty($finRows)) {
        $sections[] = ['title' => 'ज. वित्तीय कारोबार / Financial Activity', 'rows' => $finRows];
    }
    if (!empty($nomRows)) {
        $sections[] = ['title' => 'झ. हकवाला / Nominee Details', 'rows' => $nomRows];
    }
    if (!empty($incomeItems) || !empty($expenseItems)) {
        $sections[] = [
            'title'  => 'ञ. आय र खर्च विवरण / Income & Expense',
            'kind'   => 'income_expense',
            'income' => $incomeItems,
            'expense'=> $expenseItems,
            'income_total'  => (float)($aml['income_total'] ?? 0),
            'expense_total' => (float)($aml['expense_total'] ?? 0),
            'net'           => (float)($aml['net_saving_estimate'] ?? ((float)($aml['income_total'] ?? 0) - (float)($aml['expense_total'] ?? 0))),
        ];
    }
    if (!empty($riskRows)) {
        $sections[] = ['title' => 'ट. जोखिम / Admin Notes', 'rows' => $riskRows];
    }

    $docDefs = [
        ['नागरिकता अगाडि', 'Citizenship Front', $data['citizenship_front'] ?? ''],
        ['नागरिकता पछाडि', 'Citizenship Back', $data['citizenship_back'] ?? ''],
        ['National ID कार्ड', 'National ID Card', $data['national_id_card'] ?? ''],
        ['दस्तखत', 'Signature', $data['signature'] ?? ''],
        ['बायाँ औंठाछाप', 'Left Thumb', $data['left_thumb'] ?? ''],
        ['दायाँ औंठाछाप', 'Right Thumb', $data['right_thumb'] ?? ''],
        ['फोटो', 'Photo', $data['photo'] ?? ''],
    ];
    pf_collect_docs($extraDocs, $docDefs);
    if (!empty($data['admin_attachment'])) {
        $extraDocs[] = [
            'label_np' => 'Admin संलग्न',
            'label_en' => 'Admin Attachment',
            'url' => pf_url($data['admin_attachment']),
            'is_file' => true,
        ];
    }
    if (!empty($extraDocs)) {
        $sections[] = ['title' => 'ठ. संलग्न कागजात / Attached Documents', 'kind' => 'docs', 'docs' => $extraDocs];
    }
    $sigPath = pf_url($data['signature'] ?? '');
    $leftThumbPath = pf_url($data['left_thumb'] ?? '');
    $rightThumbPath = pf_url($data['right_thumb'] ?? '');
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
            ['अन्य आय',            'Other Income',          pf_e($data['other_income'] ?? '')],
            ['सेवा कार्यालय',       'Service Office',                pf_e($data['branch'] ?? '')],
        ]],
        ['title'=>'धितो जानकारी / Collateral Details', 'rows'=>[
            ['धितो प्रकार',        'Collateral Type',       pf_e($data['collateral_type'])],
            ['धितो मूल्य',         'Collateral Value',      pf_cur($data['collateral_value'])],
            ['धितो विवरण',         'Description',           pf_e($data['collateral_description'])],
        ]],
        ['title'=>'जमानी विवरण / Guarantor Details', 'rows'=>[
            ['जमानीको नाम',        'Guarantor Name',        pf_e($data['guarantor_name'] ?? '')],
            ['सम्बन्ध',            'Relation',              pf_e($data['guarantor_relation'] ?? '')],
            ['फोन',               'Phone',                 pf_e($data['guarantor_phone'] ?? '')],
            ['ठेगाना',            'Address',               pf_e($data['guarantor_address'] ?? '')],
        ]],
    ];
    if (!empty($data['remarks'])) {
        $sections[] = ['title'=>'टिप्पणी / Remarks', 'rows'=>[
            ['Admin टिप्पणी', 'Admin Remarks', pf_e($data['remarks'])],
        ]];
    }
    $loanDocs = [];
    pf_collect_docs($loanDocs, [
        ['आवेदक कागजात', 'Applicant Documents', $data['documents'] ?? ''],
        ['Admin संलग्न', 'Admin Attachment', $data['admin_attachment'] ?? ''],
    ]);
    if (!empty($loanDocs)) {
        $sections[] = ['title'=>'संलग्न कागजात / Attached Documents', 'kind'=>'docs', 'docs'=>$loanDocs];
    }
    break;
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
    if (!empty($data['admin_remarks'])) {
        $sections[] = ['title'=>'टिप्पणी / Remarks', 'rows'=>[
            ['Admin टिप्पणी', 'Admin Remarks', pf_e($data['admin_remarks'])],
        ]];
    }
    $wlfDocs = [];
    pf_collect_docs($wlfDocs, [
        ['समर्थन कागजात', 'Supporting Documents', $data['supporting_documents'] ?? ''],
        ['मृत्यु प्रमाणपत्र', 'Death Certificate', $data['death_certificate'] ?? ''],
        ['संलग्न', 'Attachment', $data['attachment_path'] ?? ($data['attachment'] ?? '')],
    ]);
    if (!empty($wlfDocs)) {
        $sections[] = ['title'=>'संलग्न कागजात / Attached Documents', 'kind'=>'docs', 'docs'=>$wlfDocs];
    }
    break;

/* ════════════════════════════ DIGITAL ════════════════════════════ */
case 'digital':
    $st = $db->prepare("SELECT * FROM digital_service_requests WHERE id=?");
    $st->execute([$id]);  $data = $st->fetch(PDO::FETCH_ASSOC);
    if (!$data) goto NOT_FOUND;
    $svcMap = [
        'missed_call_banking'=>'मिस्ड कल बैंकिङ','statement_request'=>'स्टेटमेन्ट अनुरोध',
        'bill_payment'=>'बिल भुक्तानी','mobile_recharge'=>'मोबाइल रिचार्ज',
        'internet_banking'=>'इन्टरनेट/मोबाइल बैंकिङ','sms_alert'=>'SMS अलर्ट',
        'card_service'=>'कार्ड सेवा','qr_payment'=>'QR/डिजिटल भुक्तानी',
        'share_refund'=>'शेयर फिर्ता','share_increase'=>'शेयर वृद्धि',
        'statement'=>'बैंक स्टेटमेन्ट','atm_card'=>'ATM कार्ड','cheque_book'=>'चेकबुक',
        'mobile_banking'=>'मोबाइल बैंकिङ','fund_transfer'=>'फण्ड ट्रान्सफर',
        'recharge'=>'रिचार्ज','other'=>'अन्य सेवा',
    ];
    $svcLabel    = $data['service_type_np'] ?? ($svcMap[$data['service_type'] ?? ''] ?? ($data['service_type'] ?? ''));
    $formTitle   = 'डिजिटल सेवा अनुरोध फारम — ' . $svcLabel;
    $formTitleEn = 'Digital Service Request Form — ' . ($data['service_type'] ?? '');
    $trackId     = $data['tracking_id'] ?? 'DSR-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
    $slMap       = ['pending'=>'पेन्डिङ','processing'=>'प्रक्रियामा','approved'=>'स्वीकृत','completed'=>'सम्पन्न','rejected'=>'अस्वीकृत'];
    $statusLabel = $slMap[$data['status'] ?? ''] ?? (string)($data['status'] ?? '');
    $reqRows = [];
    pf_add_row($reqRows, 'नाम', 'Requester Name', $data['requester_name'] ?? '');
    pf_add_row($reqRows, 'सदस्य नं.', 'Member ID', $data['member_id'] ?? '');
    pf_add_row($reqRows, 'फोन', 'Phone', $data['phone'] ?? '');
    pf_add_row($reqRows, 'इमेल', 'Email', $data['email'] ?? '');
    pf_add_row($reqRows, 'आवेदन मिति', 'Application Date', pf_d($data['created_at'] ?? ''));
    $svcRows = [];
    pf_add_row($svcRows, 'सेवाको प्रकार', 'Service Type', $svcLabel);
    pf_add_row($svcRows, 'खाता नं.', 'Account No.', $data['account_number'] ?? '');
    pf_add_row($svcRows, 'सम्पर्क माध्यम', 'Preferred Contact', $data['preferred_contact'] ?? '', true);
    if (!empty($data['statement_from']) || !empty($data['statement_to'])) {
        pf_add_row($svcRows, 'स्टेटमेन्ट अवधि', 'Statement Period',
            trim(($data['statement_from'] ?? '') . ' देखि / to ' . ($data['statement_to'] ?? '')));
    }
    if (!empty($data['biller_name']) || !empty($data['bill_reference'])) {
        pf_add_row($svcRows, 'बिल / बिलर', 'Biller',
            trim(($data['biller_name'] ?? '') . ' — ' . ($data['bill_reference'] ?? '')));
    }
    if (!empty($data['recharge_number']) || !empty($data['recharge_amount'])) {
        pf_add_row($svcRows, 'रिचार्ज नं. / रकम', 'Recharge',
            trim(($data['recharge_number'] ?? '') . ' — ' . (isset($data['recharge_amount']) ? pf_cur($data['recharge_amount']) : '')));
    }
    if (!empty($data['service_amount'])) {
        pf_add_row($svcRows, 'सेवा रकम', 'Service Amount', pf_cur($data['service_amount']));
    }
    pf_add_row($svcRows, 'थप विवरण', 'Additional Details', $data['request_details'] ?? '', true);
    pf_add_row($svcRows, 'Admin टिप्पणी', 'Admin Remarks', $data['admin_remarks'] ?? '', true);
    $sections = [
        ['title'=>'अनुरोधकर्ताको जानकारी / Requester Information', 'rows'=>$reqRows],
        ['title'=>'सेवा विवरण / Service Details', 'rows'=>$svcRows],
    ];
    if (!empty($data['admin_attachment']) || !empty($data['attachment'])) {
        $dsrDocs = [];
        pf_collect_docs($dsrDocs, [
            ['आवेदक संलग्न', 'Requester Attachment', $data['attachment'] ?? ''],
            ['Admin संलग्न', 'Admin Attachment', $data['admin_attachment'] ?? ''],
        ]);
        if (!empty($dsrDocs)) {
            $sections[] = ['title'=>'संलग्न / Attachment', 'kind'=>'docs', 'docs'=>$dsrDocs];
        }
    }
    break;

/* ════════════════════════════ HONOR ════════════════════════════ */
case 'honor':
    require_once __DIR__ . '/../includes/honor-tables.php';
    ensureHonorTables($db);
    $st = $db->prepare("SELECT a.*, p.title_np AS program_title, c.name_np AS category_name
        FROM honor_applications a
        LEFT JOIN honor_programs p ON p.id = a.program_id
        LEFT JOIN honor_categories c ON c.id = a.category_id
        WHERE a.id=?");
    $st->execute([$id]);
    $data = $st->fetch();
    if (!$data) goto NOT_FOUND;
    $formTitle   = 'सम्मान आवेदन फारम — ' . ($data['category_name'] ?: 'Honor');
    $formTitleEn = 'Honor Application Form';
    $trackId     = $data['tracking_id'] ?? 'HNR-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT);
    $slMap       = ['pending'=>'पेन्डिङ','under_review'=>'समीक्षामा','shortlisted'=>'छनोट सूची','selected'=>'चयनित','rejected'=>'अस्वीकृत','closed'=>'बन्द'];
    $statusLabel = $slMap[$data['status']] ?? $data['status'];
    $sections = [
        ['title'=>'आवेदक जानकारी / Applicant Information', 'rows'=>[
            ['नाम', 'Applicant Name', pf_e($data['applicant_name'])],
            ['सदस्य', 'Member', !empty($data['is_member']) ? 'हो / Yes' : 'होइन / No'],
            ['सदस्य नं.', 'Member ID', pf_e($data['member_id'])],
            ['फोन', 'Phone', pf_e($data['phone'])],
            ['इमेल', 'Email', pf_e($data['email'])],
            ['ठेगाना', 'Address', pf_e($data['address'])],
            ['आवेदन मिति', 'Application Date', pf_d($data['created_at'])],
        ]],
        ['title'=>'कार्यक्रम / कोटि / Program & Category', 'rows'=>[
            ['कार्यक्रम', 'Program', pf_e($data['program_title'])],
            ['कोटि', 'Category', pf_e($data['category_name'])],
            ['नामांकित', 'Nominee', pf_e($data['nominee_name'])],
            ['नाता', 'Relation', pf_e($data['nominee_relation'])],
            ['परीक्षा वर्ष', 'Exam Year', pf_e($data['exam_year'])],
            ['संस्था', 'Institution', pf_e($data['institution'])],
            ['कारोबार नोट', 'Business Note', pf_e($data['business_note'])],
            ['विवरण', 'Description', pf_e($data['description'])],
        ]],
    ];
    if (!empty($data['admin_remarks'])) {
        $sections[] = ['title'=>'टिप्पणी / Remarks', 'rows'=>[
            ['Admin टिप्पणी', 'Admin Remarks', pf_e($data['admin_remarks'])],
        ]];
    }
    $hnrDocs = [];
    pf_collect_docs($hnrDocs, [
        ['संलग्न', 'Attachment', $data['attachment'] ?? ''],
    ]);
    if (!empty($hnrDocs)) {
        $sections[] = ['title'=>'संलग्न कागजात / Attached Documents', 'kind'=>'docs', 'docs'=>$hnrDocs];
    }
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
            ['जन्म मिति (BS)',     'Date of Birth (BS)',    pf_e($data['dob_bs'])],
            ['जन्म मिति (AD)',     'Date of Birth (AD)',    pf_e($data['dob_ad'] ?? '')],
            ['लिङ्ग',             'Gender',                pf_e($data['gender'])],
            ['वैवाहिक अवस्था',    'Marital Status',        pf_e($data['marital_status'])],
            ['पेशा',              'Occupation',            pf_e($data['occupation'])],
            ['मासिक आय',          'Monthly Income',        pf_e($data['monthly_income'] ?? '')],
        ]],
        ['title'=>'सम्पर्क / ठेगाना / Contact & Address', 'rows'=>[
            ['मोबाइल',            'Mobile',                pf_e($data['mobile'])],
            ['इमेल',              'Email',                 pf_e($data['email'])],
            ['स्थायी ठेगाना',     'Permanent Address',     pf_e($data['permanent_address'])],
            ['अस्थायी ठेगाना',    'Temporary Address',     pf_e($data['temporary_address'])],
            ['सेवा कार्यालय',      'Service Office',                pf_e($data['branch'])],
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
        $sections[] = ['title'=>'नामांकित व्यक्ति / Nominee Details', 'rows'=>[
            ['नामांकितको नाम',    'Nominee Name',        pf_e($data['nominee_name'])],
            ['सम्बन्ध',            'Relation',              pf_e($data['nominee_relation'])],
            ['फोन',               'Phone',                 pf_e($data['nominee_phone'])],
        ]];
    }
    if (!empty($data['remarks'])) {
        $sections[] = ['title'=>'टिप्पणी / Remarks', 'rows'=>[
            ['Admin टिप्पणी', 'Admin Remarks', pf_e($data['remarks'])],
        ]];
    }
    if (!empty($data['photo'])) {
        $photoPath = pf_url($data['photo']);
    }
    $accDocs = [];
    pf_collect_docs($accDocs, [
        ['फोटो', 'Photo', $data['photo'] ?? ''],
        ['नागरिकता अगाडि', 'Citizenship Front', $data['citizenship_front'] ?? ''],
        ['नागरिकता पछाडि', 'Citizenship Back', $data['citizenship_back'] ?? ''],
        ['दस्तखत', 'Signature', $data['signature'] ?? ''],
        ['Admin संलग्न', 'Admin Attachment', $data['admin_attachment'] ?? ''],
    ]);
    if (!empty($accDocs)) {
        $sections[] = ['title'=>'संलग्न कागजात / Attached Documents', 'kind'=>'docs', 'docs'=>$accDocs];
    }
    $sigPath = pf_url($data['signature'] ?? '');
    break;

/* ════════════════════════════ APPOINTMENT ════════════════════════════ */
case 'appointment':
    $st = $db->prepare('SELECT * FROM appointments WHERE id=?');
    $st->execute([$id]);
    $data = $st->fetch(PDO::FETCH_ASSOC);
    if (!$data) goto NOT_FOUND;
    $purposeMap = [
        'account_inquiry' => 'खाता जानकारी', 'loan_inquiry' => 'ऋण जानकारी',
        'kyc_update' => 'केवाइएम अपडेट', 'loan_repayment' => 'ऋण भुक्तानी',
        'account_opening' => 'खाता खोल्ने', 'other' => 'अन्य',
    ];
    $purposeTxt = $purposeMap[$data['purpose'] ?? ''] ?? ($data['purpose'] ?? '');
    $isCoop = (($data['visit_kind'] ?? 'member') === 'cooperative');
    $formTitle   = $isCoop ? 'सहकारी भ्रमण फारम' : 'भेटघाट आवेदन फारम';
    $formTitleEn = $isCoop ? 'Cooperative Visit Form' : 'Appointment Request Form';
    $trackId     = $data['tracking_id'] ?? ('APT-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT));
    $slMap       = ['pending'=>'पेन्डिङ','confirmed'=>'पुष्टि भएको','completed'=>'सम्पन्न','cancelled'=>'रद्द'];
    $statusLabel = $slMap[$data['status'] ?? ''] ?? (string)($data['status'] ?? '');
    $pers = [];
    pf_add_row($pers, 'नाम', 'Name', $data['name'] ?? '');
    pf_add_row($pers, 'सम्पर्क व्यक्ति', 'Contact Person', $data['contact_person'] ?? '', true);
    pf_add_row($pers, 'सदस्य नं.', 'Member ID', $data['member_id'] ?? '');
    pf_add_row($pers, 'फोन', 'Phone', $data['phone'] ?? '');
    pf_add_row($pers, 'इमेल', 'Email', $data['email'] ?? '');
    pf_add_row($pers, 'संस्था ठेगाना', 'Org Address', $data['organization_address'] ?? '', true);
    pf_add_row($pers, 'वेबसाइट', 'Website', $data['organization_website'] ?? '', true);
    pf_add_row($pers, 'भ्रमण प्रकार', 'Visit Kind', $isCoop ? 'सहकारी भ्रमण' : 'सदस्य भेटघाट');
    $apt = [];
    pf_add_row($apt, 'उद्देश्य', 'Purpose', $purposeTxt);
    pf_add_row($apt, 'विवरण / सन्देश', 'Details', $data['purpose_detail'] ?? $data['message'] ?? '');
    pf_add_row($apt, 'मनपर्ने मिति', 'Preferred Date', !empty($data['preferred_date']) ? pf_d($data['preferred_date']) : '');
    pf_add_row($apt, 'समय', 'Preferred Time', $data['preferred_time'] ?? '');
    pf_add_row($apt, 'सेवा कार्यालय', 'Service Office', $data['branch'] ?? '');
    pf_add_row($apt, 'Tracking ID', 'Tracking ID', $trackId);
    pf_add_row($apt, 'दर्ता मिति', 'Created At', pf_d($data['created_at'] ?? ''));
    pf_add_row($apt, 'Admin टिप्पणी', 'Admin Remarks', $data['remarks'] ?? '', true);
    $sections = [
        ['title' => 'आवेदक / संस्था जानकारी / Applicant', 'rows' => $pers],
        ['title' => 'भेटघाट विवरण / Appointment Details', 'rows' => $apt],
    ];
    break;

/* ════════════════════════════ GRIEVANCE ════════════════════════════ */
case 'grievance':
    $st = $db->prepare('SELECT * FROM grievances WHERE id=?');
    $st->execute([$id]);
    $data = $st->fetch(PDO::FETCH_ASSOC);
    if (!$data) goto NOT_FOUND;
    $catMap = ['service'=>'सेवा','staff'=>'कर्मचारी','loan'=>'ऋण','account'=>'खाता','branch'=>'सेवा कार्यालय','other'=>'अन्य'];
    $formTitle   = 'गुनासो फारम';
    $formTitleEn = 'Grievance / Complaint Form';
    $trackId     = $data['tracking_id'] ?? ('GRV-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT));
    $slMap       = ['pending'=>'पेन्डिङ','in_progress'=>'प्रक्रियामा','resolved'=>'समाधान','closed'=>'बन्द'];
    $statusLabel = $slMap[$data['status'] ?? ''] ?? (string)($data['status'] ?? '');
    $anon = !empty($data['is_anonymous']);
    $gPers = [];
    if ($anon) {
        pf_add_row($gPers, 'गुमनाम', 'Anonymous', 'हो / Yes');
    } else {
        pf_add_row($gPers, 'नाम', 'Name', $data['name'] ?? '');
        pf_add_row($gPers, 'फोन', 'Phone', $data['phone'] ?? '');
        pf_add_row($gPers, 'इमेल', 'Email', $data['email'] ?? '');
        pf_add_row($gPers, 'सदस्य नं.', 'Member ID', $data['member_id'] ?? '');
    }
    $gBody = [];
    pf_add_row($gBody, 'श्रेणी', 'Category', $catMap[$data['category'] ?? ''] ?? ($data['category'] ?? ''));
    pf_add_row($gBody, 'विषय', 'Subject', $data['subject'] ?? '');
    pf_add_row($gBody, 'विवरण', 'Description', $data['description'] ?? '');
    pf_add_row($gBody, 'Tracking ID', 'Tracking ID', $trackId);
    pf_add_row($gBody, 'दर्ता मिति', 'Created At', pf_d($data['created_at'] ?? ''));
    pf_add_row($gBody, 'समाधान मिति', 'Resolved At', !empty($data['resolved_at']) ? pf_d($data['resolved_at']) : '', true);
    pf_add_row($gBody, 'Admin प्रतिक्रिया', 'Admin Response', $data['admin_response'] ?? '', true);
    pf_add_row($gBody, 'Admin नोट', 'Admin Note', $data['admin_note'] ?? '', true);
    $sections = [
        ['title' => 'निवेदक जानकारी / Complainant', 'rows' => $gPers],
        ['title' => 'गुनासो विवरण / Grievance Details', 'rows' => $gBody],
    ];
    if (!empty($data['attachment']) || !empty($data['admin_attachment'])) {
        $docs = [];
        if (!empty($data['attachment'])) {
            $docs[] = ['label_np' => 'संलग्न', 'label_en' => 'Attachment', 'url' => pf_url($data['attachment']), 'is_file' => true];
        }
        if (!empty($data['admin_attachment'])) {
            $docs[] = ['label_np' => 'Admin संलग्न', 'label_en' => 'Admin Attachment', 'url' => pf_url($data['admin_attachment']), 'is_file' => true];
        }
        $sections[] = ['title' => 'कागजात / Documents', 'kind' => 'docs', 'docs' => $docs];
    }
    break;

/* ════════════════════════════ JOB ════════════════════════════ */
case 'job':
    $st = $db->prepare('SELECT ja.*, c.title AS job_title, c.department AS department
        FROM job_applications ja
        LEFT JOIN careers c ON c.id = ja.career_id
        WHERE ja.id=?');
    $st->execute([$id]);
    $data = $st->fetch(PDO::FETCH_ASSOC);
    if (!$data) goto NOT_FOUND;
    $formTitle   = 'जागिर आवेदन फारम — ' . ($data['job_title'] ?: 'Job');
    $formTitleEn = 'Job Application Form';
    $trackId     = $data['tracking_id'] ?? ('JOB-' . str_pad((string)$id, 6, '0', STR_PAD_LEFT));
    $slMap       = ['pending'=>'पेन्डिङ','shortlisted'=>'छनोट','interviewed'=>'अन्तर्वार्ता','selected'=>'चयन','rejected'=>'अस्वीकृत'];
    $statusLabel = $slMap[$data['status'] ?? ''] ?? (string)($data['status'] ?? '');
    if (!empty($data['photo_path'])) {
        $photoPath = pf_url($data['photo_path']);
    }
    $jPers = [];
    pf_add_row($jPers, 'पूरा नाम', 'Full Name', $data['full_name'] ?? '');
    pf_add_row($jPers, 'इमेल', 'Email', $data['email'] ?? '');
    pf_add_row($jPers, 'फोन', 'Phone', $data['phone'] ?? '');
    pf_add_row($jPers, 'ठेगाना', 'Address', $data['address'] ?? '');
    pf_add_row($jPers, 'जन्म मिति', 'Date of Birth', $data['date_of_birth'] ?? '');
    pf_add_row($jPers, 'लिङ्ग', 'Gender', $data['gender'] ?? '');
    pf_add_row($jPers, 'Tracking ID', 'Tracking ID', $trackId);
    pf_add_row($jPers, 'आवेदन मिति', 'Applied At', pf_d($data['created_at'] ?? ''));
    $jJob = [];
    pf_add_row($jJob, 'पद', 'Job Title', $data['job_title'] ?? '');
    pf_add_row($jJob, 'विभाग', 'Department', $data['department'] ?? '', true);
    pf_add_row($jJob, 'शिक्षा', 'Education', $data['education'] ?? '');
    pf_add_row($jJob, 'अनुभव', 'Experience', $data['experience'] ?? '');
    pf_add_row($jJob, 'हालको रोजगारदाता', 'Current Employer', $data['current_employer'] ?? '');
    pf_add_row($jJob, 'अपेक्षित तलब', 'Expected Salary', $data['expected_salary'] ?? '');
    pf_add_row($jJob, 'कभर लेटर', 'Cover Letter', $data['cover_letter'] ?? '', true);
    pf_add_row($jJob, 'Admin नोट', 'Admin Notes', $data['admin_notes'] ?? '', true);
    $sections = [
        ['title' => 'आवेदक जानकारी / Applicant', 'rows' => $jPers],
        ['title' => 'पद / योग्यता / Position & Qualifications', 'rows' => $jJob],
    ];
    $jobDocs = [];
    foreach ([
        ['Resume / CV', 'Resume', $data['resume_path'] ?? ''],
        ['फोटो', 'Photo', $data['photo_path'] ?? ''],
        ['नागरिकता', 'Citizenship', $data['citizenship_path'] ?? ''],
        ['प्रमाणपत्र', 'Certificates', $data['certificates_path'] ?? ''],
    ] as [$lnp, $len, $p]) {
        $u = pf_url($p);
        if ($u !== '') {
            $ext = strtolower(pathinfo(parse_url($u, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
            $jobDocs[] = [
                'label_np' => $lnp,
                'label_en' => $len,
                'url' => $u,
                'is_file' => !in_array($ext, ['jpg','jpeg','png','gif','webp'], true),
            ];
        }
    }
    if (!empty($jobDocs)) {
        $sections[] = ['title' => 'संलग्न कागजात / Attached Documents', 'kind' => 'docs', 'docs' => $jobDocs];
    }
    break;
}

/* ── Not found (goto landing — if(false) prevents fall-through) ── */
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
    'kyc'     => [
        'नागरिकताको फोटोकपी (अगाडि/पछाडि)',
        'National ID कार्ड',
        'फोटो (पासपोर्ट साइज ×२)',
        'दस्तखत',
        'सदस्यता कार्ड प्रतिलिपि',
        'आय/व्यवसाय प्रमाण (आवश्यक परे)',
    ],
    'loan'    => ['नागरिकताको फोटोकपी','आय प्रमाण / तलब स्लिप','धितो सम्बन्धी कागजात','जमानीको नागरिकताको प्रति'],
    'welfare' => ['नागरिकताको फोटोकपी','सम्बन्धित प्रमाण कागजात','बैंक खाता विवरण'],
    'digital' => ['नागरिकताको फोटोकपी','खाता नम्बर प्रमाण'],
    'honor'   => ['प्रमाण पत्र / मार्कसीट','नागरिकताको फोटोकपी (आवश्यक परे)','सदस्यता कार्ड प्रतिलिपि'],
    'account' => ['नागरिकताको फोटोकपी','फोटो (पासपोर्ट साइज ×२)','ठेगाना प्रमाण'],
    'appointment' => ['सदस्यता कार्ड (आवश्यक परे)','परिचय पत्र'],
    'grievance'   => ['सम्बन्धित प्रमाण कागजात','संलग्न फाइल'],
    'job'         => ['Resume / CV','नागरिकता','फोटो','शैक्षिक प्रमाणपत्र'],
];
$checklist = $checklists[$type] ?? [];
?>
<!DOCTYPE html>
<html lang="ne">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo pf_e($formTitle); ?> — <?php echo pf_e($trackId); ?></title>
<?php if (function_exists('coopThemeGoogleFonts')) { coopThemeGoogleFonts(); } ?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(rtrim(SITE_URL, '/') . '/assets/vendor/fontawesome/css/all.min.css', ENT_QUOTES, 'UTF-8'); ?>">

<style id="pf-brand-vars">:root{--pf-primary:<?php echo pf_e($pfPrimary); ?>;--pf-primary-dark:<?php echo pf_e($pfPrimaryDark); ?>;}</style>
<?php
if (function_exists('coopThemeLink')) {
    coopThemeLink('assets/css/admin-print-form-page.css');
} elseif (function_exists('coopThemeLinkHtml')) {
    echo coopThemeLinkHtml('assets/css/admin-print-form-page.css');
}
?>
</head>
<body>

<div class="pf-wrap">

    <!-- ── Top action bar ── -->
    <div class="pf-topbar">
        <span class="pf-topbar-id"><i class="lucide-icon" data-lucide="file-text" aria-hidden="true" style="margin-right:5px;"></i><?php echo pf_e($trackId); ?> &mdash; <?php echo pf_e($formTitle); ?></span>
        <div class="pf-btn-row">
            <button type="button" onclick="history.back()" class="pf-btn pf-btn-ghost">
                <i class="lucide-icon" data-lucide="arrow-left" aria-hidden="true"></i> फिर्ता
            </button>
            <button type="button" onclick="window.print()" class="pf-btn pf-btn-green">
                <i class="lucide-icon" data-lucide="printer" aria-hidden="true"></i> Print / PDF डाउनलोड
            </button>
        </div>
    </div>

    <div class="pf-body">

        <!-- ── Org Header ── -->
        <div class="pf-org-header">
            <div class="pf-logo-box">
                <?php if ($siteLogo): ?>
                <img src="<?php echo pf_e($siteLogo); ?>" alt="Logo"
                     onerror="this.style.display='none';this.parentElement.innerHTML='<i class=\'lucide-icon pf-logo-icon\' data-lucide=\'landmark\' aria-hidden=\'true\'></i>';if(window.lucide&&lucide.createIcons)lucide.createIcons();">
                <?php else: ?>
                <i class="lucide-icon pf-logo-icon" data-lucide="landmark" aria-hidden="true"></i>
                <?php endif; ?>
            </div>
            <div>
                <div class="pf-org-name"><?php echo pf_e($siteName); ?></div>
                <div class="pf-org-meta">
                    <?php if ($siteAddress): ?><span><i class="lucide-icon" data-lucide="map-pin" aria-hidden="true"></i> <?php echo pf_e($siteAddress); ?></span><?php endif; ?>
                    <?php if ($sitePhone): ?><span><i class="lucide-icon" data-lucide="phone" aria-hidden="true"></i> <?php echo pf_e($sitePhone); ?></span><?php endif; ?>
                    <?php if ($siteEmail): ?><span><i class="lucide-icon" data-lucide="mail" aria-hidden="true"></i> <?php echo pf_e($siteEmail); ?></span><?php endif; ?>
                    <?php if ($siteRegNo): ?><span>दर्ता नं.: <?php echo pf_e($siteRegNo); ?></span><?php endif; ?>
                </div>
            </div>
            <div class="pf-photo-box">
                <?php if ($photoPath): ?>
                <img src="<?php echo pf_e($photoPath); ?>" alt="Photo">
                <?php elseif ($type === 'kyc' || $type === 'account'): ?>
                <span>फोटो<br>Photo<br><small>(Passport Size)</small></span>
                <?php else: ?>
                <span style="font-size:22px;opacity:.3;"><i class="lucide-icon" data-lucide="building-2" aria-hidden="true"></i></span>
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
        <?php foreach ($sections as $sec):
            $kind = $sec['kind'] ?? 'rows';
        ?>
        <div class="pf-section">
            <div class="pf-section-head"><?php echo pf_e($sec['title']); ?></div>

            <?php if ($kind === 'family'): ?>
            <table class="pf-subtbl">
                <thead>
                    <tr>
                        <th>सम्बन्ध / Relation</th>
                        <th>नाम / Name</th>
                        <th>फोन / Phone</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (($sec['family'] ?? []) as $fr): ?>
                    <tr>
                        <td><?php echo pf_e(($fr['relation'] ?? '') !== '' ? $fr['relation'] : '—'); ?></td>
                        <td><?php echo pf_e(($fr['name'] ?? '') !== '' ? $fr['name'] : '—'); ?></td>
                        <td><?php echo pf_e(($fr['phone'] ?? '') !== '' ? $fr['phone'] : '—'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php elseif ($kind === 'income_expense'): ?>
            <div class="pf-money-grid">
                <div>
                    <table class="pf-subtbl">
                        <thead><tr><th colspan="2">आय स्रोतहरू / Income Sources</th></tr></thead>
                        <tbody>
                            <?php if (empty($sec['income'])): ?>
                            <tr><td colspan="2" class="empty">—</td></tr>
                            <?php else: foreach ($sec['income'] as $it): ?>
                            <tr>
                                <td><?php echo pf_e($it['name'] ?? '—'); ?></td>
                                <td><?php echo pf_cur($it['amount'] ?? 0); ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                            <tr>
                                <th>जम्मा आय / Total Income</th>
                                <th><?php echo pf_cur($sec['income_total'] ?? 0); ?></th>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div>
                    <table class="pf-subtbl">
                        <thead><tr><th colspan="2">खर्च स्रोतहरू / Expense Sources</th></tr></thead>
                        <tbody>
                            <?php if (empty($sec['expense'])): ?>
                            <tr><td colspan="2" class="empty">—</td></tr>
                            <?php else: foreach ($sec['expense'] as $it): ?>
                            <tr>
                                <td><?php echo pf_e($it['name'] ?? '—'); ?></td>
                                <td><?php echo pf_cur($it['amount'] ?? 0); ?></td>
                            </tr>
                            <?php endforeach; endif; ?>
                            <tr>
                                <th>जम्मा खर्च / Total Expense</th>
                                <th><?php echo pf_cur($sec['expense_total'] ?? 0); ?></th>
                            </tr>
                            <tr>
                                <th>अन्तर (आय−खर्च) / Net</th>
                                <th><?php echo pf_cur($sec['net'] ?? 0); ?></th>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php elseif ($kind === 'docs'): ?>
            <div class="pf-docs-grid">
                <?php foreach (($sec['docs'] ?? []) as $doc):
                    $isFile = !empty($doc['is_file']);
                    $url = (string)($doc['url'] ?? '');
                    $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
                    $isImg = in_array($ext, ['jpg','jpeg','png','gif','webp'], true);
                ?>
                <div class="pf-doc-card">
                    <?php if (!$isFile && $isImg): ?>
                    <a href="<?php echo pf_e($url); ?>" target="_blank" rel="noopener noreferrer">
                        <img src="<?php echo pf_e($url); ?>" alt="<?php echo pf_e($doc['label_np'] ?? ''); ?>"
                             onerror="this.parentElement.innerHTML='<div class=\'pf-doc-file\'>Image unavailable</div>'">
                    </a>
                    <?php else: ?>
                    <div class="pf-doc-file">
                        <i class="lucide-icon" data-lucide="file-text" aria-hidden="true" style="font-size:22px;opacity:.55;display:block;margin-bottom:6px;"></i>
                        <a href="<?php echo pf_e($url); ?>" target="_blank" rel="noopener noreferrer">खोल्नुहोस् / Open</a>
                    </div>
                    <?php endif; ?>
                    <div class="pf-doc-label">
                        <?php echo pf_e($doc['label_np'] ?? ''); ?>
                        <?php if (!empty($doc['label_en'])): ?>
                        <span class="pf-doc-en"><?php echo pf_e($doc['label_en']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php else: ?>
            <table class="pf-tbl">
                <?php foreach (($sec['rows'] ?? []) as $row):
                    [$labelNp, $labelEn, $val] = count($row) === 3 ? $row : [$row[0], '', $row[1] ?? '—'];
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
            <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <!-- ── Declaration ── -->
        <div class="pf-decl">
            <div class="pf-decl-title"><i class="lucide-icon" data-lucide="pen" aria-hidden="true" style="margin-right:6px;"></i>आवेदकको घोषणा / Applicant's Declaration</div>
            <p>मैले माथि भरेको सम्पूर्ण जानकारी सत्य, सही र पूर्ण छ भनी म घोषणा गर्दछु। यो आवेदन डिजिटल माध्यम मार्फत पेश गरिएको हो एवं सहकारी ऐन, नियमावली तथा संस्थाका सम्पूर्ण नियम र शर्तहरू मैले स्वीकार गरेको छु। कुनै पनि जानकारी गलत भएमा संस्थाले कारवाही गर्ने अधिकार राख्छ।<br>
            <small>I hereby declare that all information provided above is true, correct and complete. This application was submitted through digital medium and I accept all applicable rules, regulations and terms of the cooperative act and institution. I understand that any false information may result in rejection or legal action by the institution.</small></p>
            <div class="pf-sig-row">
                <div class="pf-sig-box">
                    <?php if ($sigPath): ?>
                    <img class="pf-sig-img" src="<?php echo pf_e($sigPath); ?>" alt="Signature">
                    <?php else: ?>
                    <div class="pf-sig-line"></div>
                    <?php endif; ?>
                    <div class="pf-sig-label">आवेदकको दस्तखत / Applicant's Signature</div>
                </div>
                <div class="pf-sig-box">
                    <?php if ($leftThumbPath): ?>
                    <img class="pf-sig-img" src="<?php echo pf_e($leftThumbPath); ?>" alt="Left Thumb">
                    <?php else: ?>
                    <div class="pf-sig-line"></div>
                    <?php endif; ?>
                    <div class="pf-sig-label">औंठाछाप (बायाँ) / Left Thumb Print</div>
                </div>
                <div class="pf-sig-box">
                    <?php if ($rightThumbPath): ?>
                    <img class="pf-sig-img" src="<?php echo pf_e($rightThumbPath); ?>" alt="Right Thumb">
                    <div class="pf-sig-label">औंठाछाप (दायाँ) / Right Thumb Print</div>
                    <?php elseif ($type === 'kyc'): ?>
                    <div class="pf-sig-line"></div>
                    <div class="pf-sig-label">औंठाछाप (दायाँ) / Right Thumb Print</div>
                    <?php else: ?>
                    <div class="pf-sig-line"></div>
                    <div class="pf-sig-label">मिति / Date</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── For Office Use Only ── -->
        <div class="pf-office">
            <div class="pf-office-head">
                <span><i class="lucide-icon" data-lucide="landmark" aria-hidden="true" style="margin-right:8px;"></i>कार्यालय प्रयोगको लागि मात्र / For Office Use Only</span>
                <span style="font-size:11px;opacity:.75;">Print Date: <?php echo $today; ?></span>
            </div>
            <div class="pf-office-body">
                <div class="pf-officers">

                    <div>
                        <div class="pf-officer-role"><i class="lucide-icon" data-lucide="clipboard-check" aria-hidden="true" style="margin-right:5px;"></i>जाँच गर्ने / Verified By</div>
                        <div class="pf-officer-field"><div class="pf-field-line"></div><div class="pf-field-label">नाम / Name</div></div>
                        <div class="pf-officer-field"><div class="pf-field-line"></div><div class="pf-field-label">पद / Designation</div></div>
                        <div class="pf-officer-field"><div class="pf-field-line"></div><div class="pf-field-label">दस्तखत / Signature &amp; मिति / Date</div></div>
                    </div>

                    <div>
                        <div class="pf-officer-role"><i class="lucide-icon" data-lucide="user-check" aria-hidden="true" style="margin-right:5px;"></i>समीक्षा गर्ने / Reviewed By</div>
                        <div class="pf-officer-field"><div class="pf-field-line"></div><div class="pf-field-label">नाम / Name</div></div>
                        <div class="pf-officer-field"><div class="pf-field-line"></div><div class="pf-field-label">पद / Designation</div></div>
                        <div class="pf-officer-field"><div class="pf-field-line"></div><div class="pf-field-label">दस्तखत / Signature &amp; मिति / Date</div></div>
                    </div>

                    <div>
                        <div class="pf-officer-role"><i class="lucide-icon" data-lucide="stamp" aria-hidden="true" style="margin-right:5px;"></i>स्वीकृत गर्ने / Approved By</div>
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
