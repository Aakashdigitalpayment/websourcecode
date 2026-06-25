<?php
/**
 * KYC Import Sample (CSV)
 * --------------------------------------------
 * Clean CSV response for Excel import template.
 */
require_once __DIR__ . '/../includes/config.php';

if (!isAdminLoggedIn()) {
    header('Location: ' . ADMIN_URL . 'index.php');
    exit;
}

$filename = 'kyc-import-sample.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel Nepali text support
$out = fopen('php://output', 'w');
fputcsv($out, ['member_id','full_name','mobile','email','citizenship_no','national_id_number','dob_bs','gender','permanent_address','occupation','account_type','branch','status','remarks','want_id_card']);
fputcsv($out, ['1234','राम प्रसाद शर्मा','9812345678','ram@example.com','12-01-77-12345','1234-5678-9012-34','2080-01-15','male','पोखरा-८, कास्की','शिक्षक','saving','head_office','pending','Bulk import sample','0']);
fputcsv($out, ['5678','सीता अधिकारी','9800001122','sita@example.com','45-02-77-98765','5678-1234-9090-88','2078-11-05','female','लेखनाथ-१२, कास्की','व्यवसाय','current','lakeside','incomplete','नागरिकता फोटो बाँकी','1']);
fclose($out);
exit;
