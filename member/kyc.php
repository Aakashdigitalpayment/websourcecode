<?php
/**
 * KYC दर्ता / अपडेट — सदस्य पोर्टल URL
 * ब्राउजर ठेगाना: /member/kyc.php?kyc_id=... (online-kyc.php होइन)
 * भित्रको फारम उही online-kyc.php (iframe, same session)।
 */
$GLOBALS['member_frame_extra_query'] = $_SERVER['QUERY_STRING'] ?? '';
$_GET['p'] = 'kyc';
require __DIR__ . '/apply-frame.php';
