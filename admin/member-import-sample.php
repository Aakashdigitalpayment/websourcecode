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

echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel Nepali support
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
fputcsv($out, [
    '2081-00123',
    'राम प्रसाद शर्मा',
    '9812345678',
    'ram@example.com',
    'पोखरा-८, कास्की',
    '1990-05-12',
    'male',
    'head_office',
    'पुरानो सदस्य — bulk import sample',
]);
fputcsv($out, [
    '2081-00124',
    'सीता अधिकारी',
    '9800001122',
    '',
    'लेखनाथ-१२, कास्की',
    '1992-01-01',
    'female',
    'lakeside',
    '',
]);
fclose($out);
exit;
