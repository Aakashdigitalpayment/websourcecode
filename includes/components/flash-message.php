<?php
/**
 * ════════════════════════════════════════════════════════════
 * FLASH MESSAGE — Universal Alert/Flash Component
 * ════════════════════════════════════════════════════════════
 *
 * Use गर्ने तरिका — page body मा एकपटक include गर्नुहोस्:
 *   include __DIR__ . '/../includes/components/flash-message.php';
 *
 * Session-based flash (set via setFlash()):
 *   setFlash('success', 'काम सफलतापूर्वक भयो!');
 *
 * Inline alert (include गर्नुअघि define गर्नुहोस्):
 *   $flashType    = 'error';   // success | error | warning | info
 *   $flashMessage = 'केही गलत भयो।';
 *
 * Constraint: PHP backend/session logic नछुने।
 * ════════════════════════════════════════════════════════════
 */

$_iconMap = [
    'success' => 'fa-check-circle',
    'error'   => 'fa-exclamation-circle',
    'danger'  => 'fa-exclamation-circle',
    'warning' => 'fa-exclamation-triangle',
    'info'    => 'fa-info-circle',
];
$_bsMap = [
    'success' => 'success',
    'error'   => 'danger',
    'danger'  => 'danger',
    'warning' => 'warning',
    'info'    => 'info',
];

/* Session flash — from setFlash() function (existing) */
if (function_exists('getFlash')) {
    $_f = getFlash();
    if ($_f && !empty($_f['message'])) {
        $_type = $_f['type']    ?? 'info';
        $_msg  = $_f['message'] ?? '';
        $_icon = $_iconMap[$_type] ?? 'fa-info-circle';
        $_bs   = $_bsMap[$_type]   ?? 'info';
        echo '<div class="alert alert-' . $_bs . ' alert-dismissible d-flex align-items-start gap-2 mb-3" role="alert">';
        echo '  <i class="fas ' . $_icon . ' mt-1 flex-shrink-0"></i>';
        echo '  <div class="flex-grow-1">' . htmlspecialchars($_msg, ENT_QUOTES, 'UTF-8') . '</div>';
        echo '  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="बन्द गर्नुहोस्"></button>';
        echo '</div>';
        unset($_f, $_type, $_msg, $_icon, $_bs);
    }
}

/* Query-string ?msg= flash (existing pattern in many pages) */
if (!empty($_GET['msg'])) {
    $_qMsgMap = [
        'saved'          => ['success', 'सफलतापूर्वक सुरक्षित भयो।'],
        'deleted'        => ['success', 'सफलतापूर्वक मेटाइयो।'],
        'updated'        => ['success', 'सफलतापूर्वक अपडेट भयो।'],
        'error'          => ['error',   'केही गलत भयो। पुनः प्रयास गर्नुहोस्।'],
        'session_expired'=> ['warning', 'तपाईंको सत्र समाप्त भयो। पुनः लग इन गर्नुहोस्।'],
        'unauthorized'   => ['error',   'अनुमति छैन।'],
        'db_unavailable' => ['error',   'डेटाबेस उपलब्ध छैन।'],
    ];
    $_qKey = htmlspecialchars($_GET['msg'], ENT_QUOTES, 'UTF-8');
    if (isset($_qMsgMap[$_GET['msg']])) {
        [$_qType, $_qText] = $_qMsgMap[$_GET['msg']];
        $_qIcon = $_iconMap[$_qType] ?? 'fa-info-circle';
        $_qBs   = $_bsMap[$_qType]   ?? 'info';
        echo '<div class="alert alert-' . $_qBs . ' alert-dismissible d-flex align-items-start gap-2 mb-3" role="alert">';
        echo '  <i class="fas ' . $_qIcon . ' mt-1 flex-shrink-0"></i>';
        echo '  <div class="flex-grow-1">' . $_qText . '</div>';
        echo '  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="बन्द गर्नुहोस्"></button>';
        echo '</div>';
    }
    unset($_qKey, $_qType, $_qText, $_qIcon, $_qBs, $_qMsgMap);
}

/* Inline flash — if $flashType + $flashMessage defined by caller */
if (!empty($flashMessage) && !empty($flashType)) {
    $_type = $flashType;
    $_icon = $_iconMap[$_type] ?? 'fa-info-circle';
    $_bs   = $_bsMap[$_type]   ?? 'info';
    echo '<div class="alert alert-' . $_bs . ' alert-dismissible d-flex align-items-start gap-2 mb-3" role="alert">';
    echo '  <i class="fas ' . $_icon . ' mt-1 flex-shrink-0"></i>';
    echo '  <div class="flex-grow-1">' . htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8') . '</div>';
    echo '  <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="बन्द गर्नुहोस्"></button>';
    echo '</div>';
}

unset($_iconMap, $_bsMap, $_type, $_msg, $_icon, $_bs, $_f);
?>
