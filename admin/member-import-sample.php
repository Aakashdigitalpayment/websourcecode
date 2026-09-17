<?php
/**
 * Member Bulk Import Sample (CSV)
 * Excel-compatible UTF-8 BOM — CBS → Members.
 *
 * SSOT key = member_id (= sadasyata_number).
 * Required: member_id, full_name, mobile
 * Optional empty cells = keep existing value on re-import (update).
 */
require_once __DIR__ . '/includes/admin-page-boot.php';

$filename = 'member-import-sample.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
$out = fopen('php://output', 'w');

/* Canonical columns that write into `members` (+ KYM stub soft-fill).
 * Aliases also accepted: sadasyata_number, name, phone, etc. */
fputcsv($out, [
    'member_id',
    'full_name',
    'mobile',
    'email',
    'address',
    'dob',
    'gender',
]);

/* Row 1: full optional data filled */
fputcsv($out, [
    '2081-00123',
    'Ram Prasad Sharma',
    '9812345678',
    'ram@example.com',
    'Pokhara-8, Kaski',
    '1990-05-12',
    'male',
]);

/* Row 2: only compulsory + some blanks (empty optional = OK / keep old on update) */
fputcsv($out, [
    '2081-00124',
    'Sita Adhikari',
    '9800001122',
    '',
    'Lekhnath-12, Kaski',
    '',
    'female',
]);

/* Row 3: compulsory only */
fputcsv($out, [
    '2081-00125',
    'Hari Bahadur Thapa',
    '9841112233',
    '',
    '',
    '',
    '',
]);

fclose($out);
exit;
