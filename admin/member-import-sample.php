<?php
/**
 * Member Bulk Import Sample (CSV)
 * Excel-compatible UTF-8 BOM — CBS → Members.
 *
 * SSOT key = member_id (= sadasyata_number).
 * Required: member_id, full_name (English)
 * Optional: name_np, mobile, email, address, dob, gender,
 *           father_name, citizenship_no, membership_date (→ KYM soft-fill + members.membership_date)
 * Dates: वि.सं. YYYY-MM-DD सिफारिस (DB मा AD); membership_date_ad / dob_ad = ई.सं.
 */
require_once __DIR__ . '/includes/admin-page-boot.php';

$filename = 'member-import-sample.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
$out = fopen('php://output', 'w');

fputcsv($out, [
    'member_id',
    'full_name',
    'name_np',
    'mobile',
    'email',
    'address',
    'dob',
    'gender',
    'father_name',
    'citizenship_no',
    'membership_date',
]);

/* Row 1: full optional profile; dates = बि.सं. */
fputcsv($out, [
    '2081-00123',
    'Ram Prasad Sharma',
    'राम प्रसाद शर्मा',
    '9812345678',
    'ram@example.com',
    'Pokhara-8, Kaski',
    '2047-01-29',
    'male',
    'Hari Sharma',
    '40-01-70-01234',
    '2075-04-15',
]);

/* Row 2: Nepali digits; father/citizenship/date optional blank OK */
fputcsv($out, [
    '२०८१-००१२४',
    'Sita Adhikari',
    'सीता अधिकारी',
    '९८००००११२२',
    '',
    'Lekhnath-12, Kaski',
    '',
    'female',
    'कृष्ण अधिकारी',
    '४१-०२-७१-०५६७८',
    '',
]);

/* Row 3: compulsory EN name only */
fputcsv($out, [
    '2081-00125',
    'Hari Bahadur Thapa',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
    '',
]);

fclose($out);
exit;
