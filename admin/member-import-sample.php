<?php
/**
 * Member Bulk Import Sample (CSV)
 * Excel-compatible UTF-8 BOM template for existing members.
 */
require_once __DIR__ . '/../includes/config.php';

if (!isAdminLoggedIn()) {
    header('Location: ' . ADMIN_URL . 'index.php');
    exit;
}

$filename = 'member-import-sample.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
$out = fopen('php://output', 'w');
fputcsv($out, [
    'sadasyata_number',
    'full_name',
    'mobile',
    'email',
    'address',
    'dob',
    'gender',
    'branch',
    'remarks',
]);
/* full_name = English (Latin) — CVV = first 3 of name + last 4 of Member ID */
fputcsv($out, [
    '2081-00123',
    'Ram Prasad Sharma',
    '9812345678',
    'ram@example.com',
    'Pokhara-8, Kaski',
    '1990-05-12',
    'male',
    'head_office',
    'Existing member — bulk import sample (use English name for CVV)',
]);
fputcsv($out, [
    '2081-00124',
    'Sita Adhikari',
    '9800001122',
    '',
    'Lekhnath-12, Kaski',
    '1992-01-01',
    'female',
    'lakeside',
    '',
]);
fclose($out);
exit;
