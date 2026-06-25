<?php
require_once __DIR__ . '/_bootstrap.php';
requireMemberLogin();
memberSecurityHeaders();
$_t = static function (string $np, string $en): string {
    return isEnglish() ? $en : $np;
};

$db = getDB();
$mem = currentMember();
$kycRow = null;
$aml = [];
$familyRows = [];
$incomeItems = [];
$expenseItems = [];

try {
    $kycId = (int)($mem['kyc_application_id'] ?? 0);
    if ($kycId > 0) {
        $s = $db->prepare("SELECT * FROM kyc_applications WHERE id=? LIMIT 1");
        $s->execute([$kycId]);
        $kycRow = $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$kycRow) {
        $conds = [];
        $vals = [];
        if (!empty($mem['email'])) {
            $conds[] = 'LOWER(email)=?';
            $vals[] = strtolower(trim((string)$mem['email']));
        }
        if (!empty($mem['phone'])) {
            $conds[] = 'mobile=?';
            $vals[] = preg_replace('/[^0-9]/', '', (string)$mem['phone']);
        }
        if (!empty($conds)) {
            $s = $db->prepare("SELECT * FROM kyc_applications WHERE (" . implode(' OR ', $conds) . ") ORDER BY id DESC LIMIT 1");
            $s->execute($vals);
            $kycRow = $s->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    }
    if ($kycRow) {
        $amlRaw = trim((string)($kycRow['aml_details_json'] ?? ''));
        if ($amlRaw !== '') {
            $d = json_decode($amlRaw, true);
            if (is_array($d)) $aml = $d;
        }
        if (!empty($aml['income_items']) && is_array($aml['income_items'])) $incomeItems = $aml['income_items'];
        if (!empty($aml['expense_items']) && is_array($aml['expense_items'])) $expenseItems = $aml['expense_items'];
        $famRaw = trim((string)($kycRow['family_details_json'] ?? ''));
        if ($famRaw !== '') {
            $fd = json_decode($famRaw, true);
            if (is_array($fd)) $familyRows = $fd;
        }
    }
} catch (Throwable $e) {
    $kycRow = null;
}

if (!$kycRow) {
    http_response_code(404);
}
?>
<!doctype html>
<html lang="<?php echo isEnglish() ? 'en' : 'ne'; ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars($_t('KYC प्रिन्ट', 'KYC Print')); ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; color: #111827; background: #f3f4f6; }
        .wrap { max-width: 980px; margin: 12px auto; background: #fff; border: 1px solid #d1d5db; }
        .toolbar { padding: 10px 12px; border-bottom: 1px solid #e5e7eb; background: #f9fafb; display: flex; gap: 8px; }
        .btn { border: 1px solid var(--primary-color); color: var(--primary-color); background: #ecfdf5; padding: 6px 10px; border-radius: 6px; text-decoration: none; font-size: 13px; font-weight: 700; }
        .btn:hover { background: #dcfce7; }
        .page { padding: 14px 16px; }
        .hdr { text-align: center; border-bottom: 2px solid var(--primary-color); padding-bottom: 8px; margin-bottom: 10px; }
        .hdr h2 { margin: 0; font-size: 18px; color: #14532d; }
        .hdr .sub { font-size: 12px; color: #475569; margin-top: 4px; }
        .meta { display: grid; grid-template-columns: repeat(3, minmax(0,1fr)); gap: 8px; margin: 10px 0; font-size: 12px; }
        .meta div { background: #f8fafc; border: 1px solid #e2e8f0; padding: 6px 8px; border-radius: 6px; }
        .sec { margin-top: 10px; border: 1px solid #fecaca; border-radius: 8px; overflow: hidden; }
        .sec h3 { margin: 0; font-size: 14px; padding: 7px 10px; background: #fef2f2; color: var(--secondary-dark,#922b21); }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        td, th { border-bottom: 1px solid #e5e7eb; padding: 6px 8px; vertical-align: top; }
        th { width: 32%; text-align: left; color: #475569; background: #f8fafc; }
        .small { font-size: 11px; color: #6b7280; }
        .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .empty { padding: 10px; color: #6b7280; font-size: 12px; }
        @media print {
            body { background: #fff; }
            .wrap { border: none; margin: 0; max-width: none; }
            .toolbar { display: none; }
            .page { padding: 0; }
            .sec { break-inside: avoid; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="toolbar">
        <a href="#" class="btn" onclick="window.print();return false;"><?php echo $_t('प्रिन्ट', 'Print'); ?></a>
        <a href="<?php echo SITE_URL; ?>member/profile.php" class="btn"><?php echo $_t('फिर्ता', 'Back'); ?></a>
    </div>
    <div class="page">
        <?php if (!$kycRow): ?>
            <div class="empty"><?php echo $_t('KYC रेकर्ड भेटिएन।', 'KYC record not found.'); ?></div>
        <?php else: ?>
        <div class="hdr">
            <h2>व्यक्तिगत सदस्य पहिचान फारम (KYC/KYM)</h2>
            <div class="sub">मुद्रण ढाँचा — सदस्य पोर्टल</div>
        </div>
        <div class="meta">
            <div><b>सदस्यता नं.</b><br><?php echo htmlspecialchars($kycRow['member_id'] ?? '—'); ?></div>
            <div><b>Tracking ID</b><br><?php echo htmlspecialchars($kycRow['tracking_id'] ?? '—'); ?></div>
            <div><b>मिति</b><br><?php echo htmlspecialchars(formatNepaliDate($kycRow['created_at'] ?? '')); ?></div>
        </div>

        <div class="sec">
            <h3>क. व्यक्तिगत विवरण</h3>
            <table>
                <tr><th>पुरा नाम</th><td><?php echo htmlspecialchars($kycRow['full_name'] ?? '—'); ?></td></tr>
                <tr><th>Full Name (EN)</th><td><?php echo htmlspecialchars($kycRow['full_name_en'] ?? '—'); ?></td></tr>
                <tr><th>जन्म मिति (BS/AD)</th><td><?php echo htmlspecialchars(($kycRow['dob_bs'] ?? '—') . ' / ' . ($kycRow['dob_ad'] ?? '—')); ?></td></tr>
                <tr><th>लिङ्ग / वैवाहिक स्थिति</th><td><?php echo htmlspecialchars(($kycRow['gender'] ?? '—') . ' / ' . ($kycRow['marital_status'] ?? '—')); ?></td></tr>
                <tr><th>राष्ट्रियता</th><td><?php echo htmlspecialchars($kycRow['nationality'] ?? 'नेपाली'); ?></td></tr>
                <tr><th>नागरिकता नं.</th><td><?php echo htmlspecialchars($kycRow['citizenship_no'] ?? '—'); ?></td></tr>
                <tr><th>जारी जिल्ला/मिति</th><td><?php echo htmlspecialchars(($kycRow['citizenship_issued_place'] ?? '—') . ' / ' . ($kycRow['citizenship_issued_date'] ?? '—')); ?></td></tr>
                <tr><th>National ID नं.</th><td><?php echo htmlspecialchars($kycRow['national_id_number'] ?? '—'); ?></td></tr>
                <tr><th>Passport / PAN / License</th><td><?php echo htmlspecialchars(($aml['passport_no'] ?? '—') . ' / ' . ($aml['pan_no'] ?? '—') . ' / ' . ($aml['driving_license_no'] ?? '—')); ?></td></tr>
                <tr><th>शैक्षिक योग्यता / धर्म / जात</th><td><?php echo htmlspecialchars(($aml['education_qualification'] ?? '—') . ' / ' . ($aml['religion'] ?? '—') . ' / ' . ($aml['caste'] ?? '—')); ?></td></tr>
                <tr><th>मोबाइल / इमेल</th><td><?php echo htmlspecialchars(($kycRow['mobile'] ?? '—') . ' / ' . ($kycRow['email'] ?? '—')); ?></td></tr>
            </table>
        </div>

        <div class="sec">
            <h3>ख. पारिवारिक विवरण</h3>
            <?php if (empty($familyRows)): ?>
                <div class="empty">पारिवारिक विवरण उपलब्ध छैन।</div>
            <?php else: ?>
                <table>
                    <tr><th>सम्बन्ध</th><th>नाम</th><th>फोन</th></tr>
                    <?php foreach ($familyRows as $fr): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($fr['relation'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($fr['name'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($fr['phone'] ?? '—'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endif; ?>
        </div>

        <div class="sec">
            <h3>ग. पेशा/व्यवसाय विवरण</h3>
            <table>
                <tr><th>पेशा</th><td><?php echo htmlspecialchars($kycRow['occupation'] ?? '—'); ?></td></tr>
                <tr><th>संस्थाको नाम</th><td><?php echo htmlspecialchars($kycRow['organization_name'] ?? '—'); ?></td></tr>
                <tr><th>मासिक आय</th><td><?php echo htmlspecialchars($kycRow['monthly_income'] ?? '—'); ?></td></tr>
                <tr><th>पेशा/व्यवसाय स्थान</th><td><?php echo htmlspecialchars($aml['occupation_location'] ?? '—'); ?></td></tr>
                <tr><th>व्यवसाय नाम</th><td><?php echo htmlspecialchars($aml['occupation_business_name'] ?? '—'); ?></td></tr>
                <tr><th>Business PAN नं.</th><td><?php echo htmlspecialchars($aml['business_pan_no'] ?? '—'); ?></td></tr>
                <tr><th>Business दर्ता प्रकार/नं.</th><td><?php echo htmlspecialchars(($aml['business_registration_type'] ?? '—') . ' / ' . ($aml['business_registration_no'] ?? '—')); ?></td></tr>
                <tr><th>दर्ता निकाय/मिति</th><td><?php echo htmlspecialchars(($aml['business_registration_office'] ?? '—') . ' / ' . ($aml['business_registration_date_bs'] ?? '—')); ?></td></tr>
                <tr><th>व्यवसाय प्रकृति / वार्षिक आय</th><td><?php echo htmlspecialchars(($aml['business_nature'] ?? '—') . ' / ' . ($aml['estimated_annual_income'] ?? '—')); ?></td></tr>
            </table>
        </div>

        <div class="sec">
            <h3>घ. बसाइ र सहकारी सदस्यता विवरण</h3>
            <table>
                <tr><th>स्थायी ठेगाना</th><td><?php echo htmlspecialchars($kycRow['permanent_address'] ?? '—'); ?></td></tr>
                <tr><th>अस्थायी ठेगाना</th><td><?php echo htmlspecialchars($kycRow['temporary_address'] ?? '—'); ?></td></tr>
                <tr><th>घरधनी नाम/सम्पर्क</th><td><?php echo htmlspecialchars(($aml['landlord_name'] ?? '—') . ' / ' . ($aml['landlord_contact'] ?? '—')); ?></td></tr>
                <tr><th>मतदाता परिचयपत्र / मतदान स्थल</th><td><?php echo htmlspecialchars(($aml['voter_id_card_no'] ?? '—') . ' / ' . ($aml['polling_station'] ?? '—')); ?></td></tr>
                <tr><th>सदस्यता उद्देश्य</th><td><?php echo htmlspecialchars($aml['member_purpose'] ?? '—'); ?></td></tr>
                <tr><th>आफू अन्य सहकारी सदस्य</th><td><?php echo htmlspecialchars(($aml['self_other_coop_member'] ?? '—') . ' / ' . ($aml['self_other_coop_details'] ?? '—')); ?></td></tr>
                <tr><th>परिवार यसै सहकारीमा</th><td><?php echo htmlspecialchars(($aml['family_same_coop_member'] ?? '—') . ' / ' . ($aml['family_same_coop_details'] ?? '—')); ?></td></tr>
                <tr><th>PEP / अपराध घोषणा</th><td><?php echo htmlspecialchars(($aml['politically_exposed'] ?? '—') . ' / ' . ($aml['past_crime_declared'] ?? '—')); ?></td></tr>
            </table>
        </div>

        <div class="sec">
            <h3>ङ. वित्तीय कारोबार, नजिकको व्यक्ति र हकवाला</h3>
            <div class="grid2">
                <table>
                    <tr><th>वार्षिक डेबिट/क्रेडिट</th><td><?php echo htmlspecialchars($aml['annual_debit_credit_estimate'] ?? '—'); ?></td></tr>
                    <tr><th>वार्षिक कारोबार संख्या</th><td><?php echo htmlspecialchars($aml['annual_turnover_numbers'] ?? '—'); ?></td></tr>
                    <tr><th>वार्षिक जम्मा अनुमान</th><td><?php echo htmlspecialchars($aml['annual_deposit_estimate'] ?? '—'); ?></td></tr>
                    <tr><th>संस्थासँग ऋणधन अनुमान</th><td><?php echo htmlspecialchars($aml['institution_debt_estimate'] ?? '—'); ?></td></tr>
                    <tr><th>वार्षिक पारिवारिक आम्दानी</th><td><?php echo htmlspecialchars($aml['annual_family_income'] ?? '—'); ?></td></tr>
                    <tr><th>सम्पत्ति/Net Worth</th><td><?php echo htmlspecialchars($aml['net_worth_details'] ?? '—'); ?></td></tr>
                    <tr><th>देशान्तर/अक्षांश</th><td><?php echo htmlspecialchars($aml['longitude_latitude'] ?? '—'); ?></td></tr>
                    <tr><th>Map बाट प्राप्त ठेगाना</th><td><?php echo htmlspecialchars($aml['map_resolved_address'] ?? '—'); ?></td></tr>
                </table>
                <table>
                    <tr><th>नजिकको व्यक्ति</th><td><?php echo htmlspecialchars(($aml['nearest_person_name'] ?? '—') . ' (' . ($aml['nearest_person_relation'] ?? '—') . ')'); ?></td></tr>
                    <tr><th>हकवाला नाम</th><td><?php echo htmlspecialchars($aml['nominee_name'] ?? '—'); ?></td></tr>
                    <tr><th>हकवाला DOB / नागरिकता</th><td><?php echo htmlspecialchars(($aml['nominee_dob'] ?? '—') . ' / ' . ($aml['nominee_citizenship_no'] ?? '—')); ?></td></tr>
                    <tr><th>हकवालासँग नाता</th><td><?php echo htmlspecialchars($aml['nominee_relation'] ?? '—'); ?></td></tr>
                    <tr><th>हकवाला जारी जिल्ला/मिति</th><td><?php echo htmlspecialchars(($aml['nominee_issue_district'] ?? '—') . ' / ' . ($aml['nominee_issue_date'] ?? '—')); ?></td></tr>
                    <tr><th>हकवाला ठेगाना</th><td><?php echo htmlspecialchars(($aml['nominee_permanent_address'] ?? '—') . ' / ' . ($aml['nominee_temporary_address'] ?? '—')); ?></td></tr>
                </table>
            </div>
            <?php if (!empty($incomeItems) || !empty($expenseItems)): ?>
            <div class="grid2" style="margin-top:8px;">
                <table>
                    <tr><th colspan="2">आय स्रोतहरू</th></tr>
                    <?php if (empty($incomeItems)): ?>
                    <tr><td colspan="2">—</td></tr>
                    <?php else: foreach ($incomeItems as $it): ?>
                    <tr><td><?php echo htmlspecialchars($it['name'] ?? '—'); ?></td><td>Rs. <?php echo number_format((float)($it['amount'] ?? 0), 2); ?></td></tr>
                    <?php endforeach; endif; ?>
                    <tr><th>जम्मा आय</th><th>Rs. <?php echo number_format((float)($aml['income_total'] ?? 0), 2); ?></th></tr>
                </table>
                <table>
                    <tr><th colspan="2">खर्च स्रोतहरू</th></tr>
                    <?php if (empty($expenseItems)): ?>
                    <tr><td colspan="2">—</td></tr>
                    <?php else: foreach ($expenseItems as $it): ?>
                    <tr><td><?php echo htmlspecialchars($it['name'] ?? '—'); ?></td><td>Rs. <?php echo number_format((float)($it['amount'] ?? 0), 2); ?></td></tr>
                    <?php endforeach; endif; ?>
                    <tr><th>जम्मा खर्च</th><th>Rs. <?php echo number_format((float)($aml['expense_total'] ?? 0), 2); ?></th></tr>
                    <tr><th>अन्तर (आय-खर्च)</th><th>Rs. <?php echo number_format((float)($aml['net_saving_estimate'] ?? ((float)($aml['income_total'] ?? 0) - (float)($aml['expense_total'] ?? 0))), 2); ?></th></tr>
                </table>
            </div>
            <?php endif; ?>
            <div class="small" style="padding:8px 10px;">अन्य संलग्न कागजात: <?php echo htmlspecialchars($aml['other_attached_docs'] ?? '—'); ?></div>
        </div>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
