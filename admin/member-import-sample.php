<?php
/**
 * Member Bulk Import Sample (CSV)
 * Excel-compatible UTF-8 BOM — CBS → Members.
 *
 * SSOT key = member_id (= sadasyata_number).
 * Required: member_id, full_name (English)
 * Optional: name_np (Nepali), mobile, email, address, dob, gender
 * Nepali digits in member_id/mobile auto-convert to English 0–9.
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
]);

/* Row 1: EN + NP names filled */
fputcsv($out, [
    '2081-00123',
    'Ram Prasad Sharma',
    'राम प्रसाद शर्मा',
    '9812345678',
    'ram@example.com',
    'Pokhara-8, Kaski',
    '1990-05-12',
    'male',
]);

/* Row 2: Nepali digits in Member ID (auto → Latin) */
fputcsv($out, [
    '२०८१-००१२४',
    'Sita Adhikari',
    'सीता अधिकारी',
    '९८००००११२२',
    '',
    'Lekhnath-12, Kaski',
    '',
    'female',
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
]);

fclose($out);
exit;
