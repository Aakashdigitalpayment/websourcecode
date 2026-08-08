#!/usr/bin/env php
<?php
/**
 * Smoke test: public/member form accessibility — labels must expose for= when controls have id=.
 * Run: php scripts/smoke-form-a11y.php
 * Exit 0 = pass.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$failed = 0;
$passed = 0;

function ok(string $msg): void {
    global $passed;
    $passed++;
    echo "OK  {$msg}\n";
}
function fail(string $msg): void {
    global $failed;
    $failed++;
    echo "FAIL {$msg}\n";
}

function assertContains(string $file, string $needle, string $why): void {
    global $root;
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        fail("{$file}: missing file ({$why})");
        return;
    }
    $t = file_get_contents($path);
    if ($t === false || strpos($t, $needle) === false) {
        fail("{$file}: missing `{$needle}` ({$why})");
        return;
    }
    ok("{$file}: {$why}");
}

function countBareFormLabels(string $file): int {
    global $root;
    $t = file_get_contents($root . '/' . $file);
    if ($t === false) {
        return -1;
    }
    // form-label / mem-form-label / sp-form-label without for=
    // Labels that already have id= are treated as group legends (aria-labelledby).
    if (!preg_match_all('/<label\b[^>]*>/i', $t, $m)) {
        return 0;
    }
    $n = 0;
    foreach ($m[0] as $label) {
        if (!preg_match('/\b(?:form-label|mem-form-label|sp-form-label)\b/', $label)) {
            continue;
        }
        if (preg_match('/\bfor\s*=/', $label)) {
            continue;
        }
        if (preg_match('/\bid\s*=/', $label)) {
            continue;
        }
        $n++;
    }
    return $n;
}

$filesExpectZeroBare = [
    'appointment.php',
    'loan-apply.php',
    'online-account.php',
    'application-tracker.php',
    'member/password-reset-request.php',
    'member/honor-apply.php',
    'emi-calculator.php',
    'honor-apply.php',
    'member-survey.php',
    'online-kyc.php',
    'member/digital-service.php',
    'member/loan-apply.php',
    'member/account-apply.php',
    'member/service-request.php',
    'member/appointment.php',
    'member/grievance.php',
    'sahakari-patro.php',
];

foreach ($filesExpectZeroBare as $f) {
    $n = countBareFormLabels($f);
    if ($n < 0) {
        fail("{$f}: unreadable");
    } elseif ($n === 0) {
        ok("{$f}: 0 bare form-label");
    } else {
        fail("{$f}: {$n} bare form-label(s) remaining");
    }
}

// Critical control pairs
$pairs = [
    ['appointment.php', 'for="apptDate"', 'member preferred date'],
    ['appointment.php', 'for="apptDateCoop"', 'coop preferred date'],
    ['appointment.php', 'for="appt_org_name"', 'coop organization'],
    ['appointment.php', 'aria-labelledby="appt_coop_member_label"', 'member radio group'],
    ['loan-apply.php', 'for="loan_purpose"', 'loan purpose'],
    ['loan-apply.php', 'for="loan_guarantor_name"', 'guarantor'],
    ['loan-apply.php', 'for="loan_organization_name"', 'organization name'],
    ['online-account.php', 'for="acc_photo"', 'passport photo'],
    ['online-account.php', 'for="acc_branch"', 'branch'],
    ['online-account.php', 'aria-labelledby="acc_coop_member_label"', 'member radio group'],
    ['application-tracker.php', 'for="secPhone"', 'verify phone'],
    ['application-tracker.php', 'for="securityCode"', 'security code'],
    ['member/password-reset-request.php', 'for="mpr_identifier"', 'identifier'],
    ['member/password-reset-request.php', 'for="pw1"', 'new password'],
    ['member/password-reset-request.php', 'aria-labelledby="mpr_channel_label"', 'OTP channel group'],
    ['member/honor-apply.php', 'for="memberHonorCategory"', 'honor category'],
    ['member/honor-apply.php', 'for="mha_attachment"', 'honor attachment'],
    ['member/honor-apply.php', 'for="mha_name"', 'readonly name'],
    ['emi-calculator.php', 'for="amountSlider"', 'amount slider'],
    ['emi-calculator.php', 'for="rateSlider"', 'rate slider'],
    ['emi-calculator.php', 'for="tenureSlider"', 'tenure slider'],
    ['emi-calculator.php', 'aria-labelledby="emi_loan_type_label"', 'loan type group'],
    ['honor-apply.php', 'aria-labelledby="honor_coop_member_label"', 'honor coop group'],
    ['member-survey.php', 'aria-labelledby="svy_coop_member_label"', 'survey coop group'],
    ['online-kyc.php', 'id="fullKymForm"', 'main KYC form'],
    ['online-kyc.php', 'for="familyRelation"', 'family relation'],
    ['online-kyc.php', 'for="familyMemberName"', 'family name'],
    ['online-kyc.php', 'for="incomeSourceName"', 'income source'],
    ['online-kyc.php', 'for="netSavingDisplay"', 'net saving display'],
    ['online-kyc.php', 'id="kyc_full_name"', 'main full name id'],
    ['online-kyc.php', 'for="kyc_full_name"', 'main full name label'],
    ['online-kyc.php', 'id="dob_ad_picker"', 'dob picker preserved'],
    ['member/digital-service.php', 'for="dsServiceType"', 'service type'],
    ['member/digital-service.php', 'for="mds_account_number"', 'account number'],
    ['member/loan-apply.php', 'for="mla_loan_type"', 'loan type'],
    ['member/loan-apply.php', 'for="mla_loan_amount"', 'loan amount'],
    ['member/account-apply.php', 'for="maa_account_type"', 'account type'],
    ['member/account-apply.php', 'for="maa_nominee_name"', 'guarantor name'],
    ['member/service-request.php', 'for="msr_service_type"', 'service type'],
    ['member/service-request.php', 'for="msr_message"', 'message'],
    ['member/appointment.php', 'for="mapt_purpose"', 'purpose'],
    ['member/appointment.php', 'for="mapt_preferred_date"', 'preferred date'],
    ['member/grievance.php', 'for="mgr_category"', 'category'],
    ['member/grievance.php', 'for="mgr_subject"', 'subject'],
    ['sahakari-patro.php', 'for="sp_birth_date"', 'birth date'],
    ['sahakari-patro.php', 'for="sp_birth_time"', 'birth time'],
    ['sahakari-patro.php', 'for="sp_nakshatra"', 'nakshatra'],
    ['sahakari-patro.php', 'for="sp_rashi"', 'rashi'],
];

foreach ($pairs as [$file, $needle, $why]) {
    assertContains($file, $needle, $why);
}

// Syntax lint on touched files
foreach ($filesExpectZeroBare as $f) {
    $cmd = 'php -l ' . escapeshellarg($root . '/' . $f) . ' 2>&1';
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    if ($code !== 0) {
        fail("{$f}: php -l failed — " . implode(' ', $out));
    } else {
        ok("{$f}: php -l");
    }
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
