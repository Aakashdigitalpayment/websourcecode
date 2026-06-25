<?php
require_once 'includes/config.php';
require_once __DIR__ . '/includes/request-status-history.php';
/* ensure-tables: silent fail — DB tables नभए पनि page crash नगर्ने */
try { require_once 'includes/ensure-tables.php'; } catch (\Throwable $e) { error_log('tracker ensure-tables: ' . $e->getMessage()); }
$pageTitle = isEnglish() ? 'Application Status Tracker' : 'आवेदन स्थिति ट्र्याकर';
require_once 'includes/header.php';

function trackerHistoryModuleKeyFromType(string $appType): ?string {
    return match ($appType) {
        'appointment' => 'appointment',
        'kyc' => 'kyc',
        'loan' => 'loan',
        'account' => 'account',
        'grievance' => 'grievance',
        'welfare_claim' => 'welfare',
        'job' => 'job_application',
        'feedback' => 'feedback',
        'digital_service' => 'digital_service',
        default => null,
    };
}

function trackerFetchHistoryEntries(array $app): array {
    static $dbRef = null;
    static $tableEnsured = false;
    $module = trackerHistoryModuleKeyFromType((string)($app['app_type'] ?? ''));
    $requestId = (int)($app['id'] ?? 0);
    if ($module === null || $requestId <= 0) {
        return [];
    }
    try {
        if (!($dbRef instanceof PDO)) {
            $dbRef = getDB();
        }
        if (!$tableEnsured) {
            ensureRequestStatusHistoryTable($dbRef);
            $tableEnsured = true;
        }
        return fetchRequestStatusHistory($dbRef, $module, $requestId, 20);
    } catch (Throwable $e) {
        return [];
    }
}

$allResults       = [];
$error            = '';
$success          = '';
/* सुरक्षा verification flag — phone/email खोज्दा phone+email pair जाँच हुन्छ */
$verificationOk   = false;
$needsVerify      = false;
$trackerAttemptWindowSec = 15 * 60; // 15 minutes
$trackerMaxAttempts = 7;
$trackerGuardKey = 'tracker_guard_' . hash('sha256', strtolower((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown')));

if (!isset($_SESSION['tracker_guard']) || !is_array($_SESSION['tracker_guard'])) {
    $_SESSION['tracker_guard'] = [];
}
if (!isset($_SESSION['tracker_guard'][$trackerGuardKey]) || !is_array($_SESSION['tracker_guard'][$trackerGuardKey])) {
    $_SESSION['tracker_guard'][$trackerGuardKey] = ['fails' => 0, 'blocked_until' => 0];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $searchType = $_POST['search_type'] ?? 'tracking_id';
    if (!in_array($searchType, ['tracking_id', 'phone', 'email'], true)) {
        $searchType = 'tracking_id';
    }

    /* Phone/Email search मा: search_value = verification section बाट नै आउँछ
       Tracking ID search मा: search_value = separate field बाट */
    if (in_array($searchType, ['phone', 'email'], true)) {
        $secPhone = mb_substr(trim((string) ($_POST['sec_phone'] ?? '')), 0, 24);
        $secEmail = mb_substr(trim((string) ($_POST['sec_email'] ?? '')), 0, 160);
        /* Search value directly verification fields बाट — duplicate entry छैन */
        $searchValue = ($searchType === 'phone') ? $secPhone : $secEmail;
    } else {
        $searchValue = trim((string) ($_POST['search_value'] ?? ''));
        $secPhone    = '';
        $secEmail    = '';
    }

    $maxSvLen = $searchType === 'tracking_id' ? 96 : 180;
    if (mb_strlen($searchValue) > $maxSvLen) {
        $searchValue = mb_substr($searchValue, 0, $maxSvLen);
    }

    if (in_array($searchType, ['phone', 'email'], true)
        && (int)($_SESSION['tracker_guard'][$trackerGuardKey]['blocked_until'] ?? 0) > time()) {
        $remainingMin = (int)ceil((((int)$_SESSION['tracker_guard'][$trackerGuardKey]['blocked_until']) - time()) / 60);
        $error = isEnglish()
            ? 'For your security, this search is temporarily paused. Please try again after ' . $remainingMin . ' minute(s).'
            : 'सुरक्षाका लागि यो खोज केही समयका लागि रोकिएको छ। कृपया ' . $remainingMin . ' मिनेटपछि फेरि प्रयास गर्नुहोस्।';
    } elseif (!verifyCSRFToken()) {
        $error = isEnglish() ? 'Security check failed. Please try again.' : 'सुरक्षा जाँच असफल भयो। कृपया पुनः प्रयास गर्नुहोस्।';

    /* ── Tracking ID search — directly verify ── */
    } elseif ($searchType === 'tracking_id' && empty($searchValue)) {
        $error = isEnglish() ? 'Please enter a Tracking ID.' : 'कृपया Tracking ID प्रविष्ट गर्नुहोस्।';

    /* ── Phone / Email खोज्दा security code verification ──
       code = phone को अन्तिम 4 अंक + email को @ अघिका पहिला 3 अक्षर */
    } elseif (in_array($searchType, ['phone', 'email'])) {
        $secCode      = trim((string)($_POST['security_code'] ?? ''));
        $needsVerify  = true;

        if (empty($secPhone) || empty($secEmail)) {
            $error = isEnglish()
                ? 'Please enter both your phone number and email address.'
                : 'कृपया आफ्नो फोन नम्बर र इमेल दुवै प्रविष्ट गर्नुहोस्।';
        } elseif (empty($secCode)) {
            $error = isEnglish()
                ? 'Please enter the security verification code.'
                : 'कृपया सुरक्षा प्रमाणीकरण कोड प्रविष्ट गर्नुहोस्।';
        } else {
            $phonePart = preg_replace('/\D/', '', $secPhone);
            $last4     = strlen($phonePart) >= 4 ? substr($phonePart, -4) : $phonePart;
            $emailPart = '';
            if ($secEmail && strpos($secEmail, '@') !== false) {
                $emailPart = strtolower(substr($secEmail, 0, strpos($secEmail, '@')));
                $emailPart = substr($emailPart, 0, 3);
            }
            $expectedCode = strtolower($last4 . $emailPart);

            if (strtolower($secCode) === $expectedCode) {
                $verificationOk = true;
            } else {
                $error = isEnglish()
                    ? 'Verification code does not match. Please check phone/email and code.'
                    : 'सुरक्षा कोड मिलेन। फोन/इमेल र कोड पुनः जाँच गर्नुहोस्।';
            }
        }
    } elseif ($searchType === 'tracking_id' && $searchValue !== '') {
        /* Tracking ID खोज्दा verification आवश्यक छैन (खाली = माथि नै error) */
        $verificationOk = true;
    }

    /* Verification pass भयो — database खोज्छु */
    if ($verificationOk) {
        try {
            $db = getDB();

            // रोजगारी आवेदन खोज्ने
            try {
                if ($searchType === 'tracking_id') {
                    $stmt = $db->prepare("SELECT ja.*, c.title as job_title, c.title_np as job_title_np, 'job' as app_type
                                          FROM job_applications ja
                                          LEFT JOIN careers c ON ja.career_id = c.id
                                          WHERE ja.tracking_id = ?");
                    $stmt->execute([$searchValue]);
                } elseif ($searchType === 'phone') {
                    $stmt = $db->prepare("SELECT ja.*, c.title as job_title, c.title_np as job_title_np, 'job' as app_type
                                          FROM job_applications ja
                                          LEFT JOIN careers c ON ja.career_id = c.id
                                          WHERE ja.phone = ? ORDER BY ja.created_at DESC");
                    $stmt->execute([$searchValue]);
                } else {
                    $stmt = $db->prepare("SELECT ja.*, c.title as job_title, c.title_np as job_title_np, 'job' as app_type
                                          FROM job_applications ja
                                          LEFT JOIN careers c ON ja.career_id = c.id
                                          WHERE ja.email = ? ORDER BY ja.created_at DESC");
                    $stmt->execute([$searchValue]);
                }
                $jobResults = $stmt->fetchAll();
                if ($jobResults) $allResults = array_merge($allResults, $jobResults);
            } catch (Exception $e) {}

            // ऋण आवेदन खोज्ने
            try {
                if ($searchType === 'tracking_id') {
                    $stmt = $db->prepare("SELECT *, 'loan' as app_type FROM loan_applications WHERE tracking_id = ?");
                    $stmt->execute([$searchValue]);
                } elseif ($searchType === 'phone') {
                    $stmt = $db->prepare("SELECT *, 'loan' as app_type FROM loan_applications WHERE mobile = ? ORDER BY created_at DESC");
                    $stmt->execute([$searchValue]);
                } else {
                    $stmt = $db->prepare("SELECT *, 'loan' as app_type FROM loan_applications WHERE email = ? ORDER BY created_at DESC");
                    $stmt->execute([$searchValue]);
                }
                $loanResults = $stmt->fetchAll();
                if ($loanResults) $allResults = array_merge($allResults, $loanResults);
            } catch (Exception $e) {}

            // खाता आवेदन खोज्ने
            try {
                if ($searchType === 'tracking_id') {
                    $stmt = $db->prepare("SELECT *, 'account' as app_type FROM account_applications WHERE tracking_id = ?");
                    $stmt->execute([$searchValue]);
                } elseif ($searchType === 'phone') {
                    $stmt = $db->prepare("SELECT *, 'account' as app_type FROM account_applications WHERE mobile = ? ORDER BY created_at DESC");
                    $stmt->execute([$searchValue]);
                } else {
                    $stmt = $db->prepare("SELECT *, 'account' as app_type FROM account_applications WHERE email = ? ORDER BY created_at DESC");
                    $stmt->execute([$searchValue]);
                }
                $accResults = $stmt->fetchAll();
                if ($accResults) $allResults = array_merge($allResults, $accResults);
            } catch (Exception $e) {}

            // गुनासो खोज्ने — tracking_id (GRV-YYYYMMDD-XXXXXX) वा legacy numeric id दुवै support
            try {
                if ($searchType === 'tracking_id') {
                    $rawSv = trim($searchValue);
                    // Match by full tracking_id (case-insensitive) OR legacy numeric id
                    $numericId = (int) preg_replace('/[^0-9]/', '', $rawSv);
                    $stmt = $db->prepare("SELECT *, 'grievance' as app_type FROM grievances WHERE UPPER(tracking_id) = UPPER(?) OR id = ?");
                    $stmt->execute([$rawSv, $numericId]);
                } elseif ($searchType === 'phone') {
                    $stmt = $db->prepare("SELECT *, 'grievance' as app_type FROM grievances WHERE phone = ? ORDER BY created_at DESC");
                    $stmt->execute([$searchValue]);
                } else {
                    $stmt = $db->prepare("SELECT *, 'grievance' as app_type FROM grievances WHERE email = ? ORDER BY created_at DESC");
                    $stmt->execute([$searchValue]);
                }
                $grvResults = $stmt->fetchAll();
                if ($grvResults) $allResults = array_merge($allResults, $grvResults);
            } catch (Exception $e) {}

            // KYC आवेदन खोज्ने — tracking_id वा legacy id दुवै support
            try {
                if ($searchType === 'tracking_id') {
                    $rawSv = trim($searchValue);
                    $numericId = (int) preg_replace('/[^0-9]/', '', $rawSv);
                    $stmt = $db->prepare("SELECT *, 'kyc' as app_type FROM kyc_applications WHERE UPPER(tracking_id) = UPPER(?) OR id = ?");
                    $stmt->execute([$rawSv, $numericId]);
                } elseif ($searchType === 'phone') {
                    $stmt = $db->prepare("SELECT *, 'kyc' as app_type FROM kyc_applications WHERE mobile = ? ORDER BY created_at DESC");
                    $stmt->execute([$searchValue]);
                } else {
                    $stmt = $db->prepare("SELECT *, 'kyc' as app_type FROM kyc_applications WHERE email = ? ORDER BY created_at DESC");
                    $stmt->execute([$searchValue]);
                }
                $kycResults = $stmt->fetchAll();
                if ($kycResults) $allResults = array_merge($allResults, $kycResults);
            } catch (Exception $e) {}

            // लिलामी बोलपत्र खोज्ने
            try {
                if ($searchType === 'tracking_id') {
                    $bidId = 0;
                    if (preg_match('/BID-?(\d+)/i', $searchValue, $matches)) {
                        $bidId = (int) $matches[1];
                    } elseif (ctype_digit(trim($searchValue))) {
                        $bidId = (int) trim($searchValue);
                    }
                    $stmt = $db->prepare("SELECT ab.*, an.title as auction_title, 'auction_bid' as app_type
                                          FROM auction_bids ab
                                          LEFT JOIN auction_notices an ON ab.auction_id = an.id
                                          WHERE ab.id = ?");
                    $stmt->execute([$bidId > 0 ? $bidId : 0]);
                } elseif ($searchType === 'phone') {
                    $stmt = $db->prepare("SELECT ab.*, an.title as auction_title, 'auction_bid' as app_type
                                          FROM auction_bids ab
                                          LEFT JOIN auction_notices an ON ab.auction_id = an.id
                                          WHERE ab.bidder_phone = ? ORDER BY ab.created_at DESC");
                    $stmt->execute([$searchValue]);
                } else {
                    $stmt = $db->prepare("SELECT ab.*, an.title as auction_title, 'auction_bid' as app_type
                                          FROM auction_bids ab
                                          LEFT JOIN auction_notices an ON ab.auction_id = an.id
                                          WHERE ab.bidder_email = ? ORDER BY ab.created_at DESC");
                    $stmt->execute([$searchValue]);
                }
                $bidResults = $stmt->fetchAll();
                if ($bidResults) $allResults = array_merge($allResults, $bidResults);
            } catch (Exception $e) {}

            // भेटघाट बुकिङ खोज्ने — APT-YYYYMMDD-XXXXXX (tracking_id) + legacy APT-###### / numeric id
            try {
                if ($searchType === 'tracking_id') {
                    $rawSv = trim($searchValue);
                    $stmt = $db->prepare(
                        "SELECT *, 'appointment' as app_type FROM appointments
                         WHERE UPPER(TRIM(COALESCE(tracking_id,''))) = UPPER(?)
                         LIMIT 10"
                    );
                    $stmt->execute([$rawSv]);
                    $apptResults = $stmt->fetchAll();
                    if (empty($apptResults)) {
                        $apptId = 0;
                        // Legacy: एउटै हाइफन पछि अङ्क मात्र (जस्तै APT-000655) — दुई हाइफन भएको APT-20260505-ABC123 बाट अङ्क निकाल्दैन
                        if (preg_match('/^APT-(\d{1,12})$/i', $rawSv, $m)) {
                            $apptId = (int) $m[1];
                        } elseif (ctype_digit($rawSv)) {
                            $apptId = (int) $rawSv;
                        }
                        if ($apptId > 0) {
                            $stmt = $db->prepare("SELECT *, 'appointment' as app_type FROM appointments WHERE id = ?");
                            $stmt->execute([$apptId]);
                            $apptResults = $stmt->fetchAll();
                        }
                    }
                } elseif ($searchType === 'phone') {
                    $stmt = $db->prepare("SELECT *, 'appointment' as app_type FROM appointments WHERE phone = ? ORDER BY created_at DESC");
                    $stmt->execute([$searchValue]);
                    $apptResults = $stmt->fetchAll();
                } else {
                    $stmt = $db->prepare("SELECT *, 'appointment' as app_type FROM appointments WHERE email = ? ORDER BY created_at DESC");
                    $stmt->execute([$searchValue]);
                    $apptResults = $stmt->fetchAll();
                }
                if (!empty($apptResults)) {
                    $allResults = array_merge($allResults, $apptResults);
                }
            } catch (Exception $e) {}

            /* सदस्य सर्वेक्षण खोज्ने — FBK-YYYY-XXXXXX format को tracking_id बाट खोज्छु */
            try {
                if ($searchType === 'tracking_id') {
                    $stmt = $db->prepare("SELECT *, 'feedback' as app_type FROM member_feedback WHERE tracking_id = ?");
                    $stmt->execute([$searchValue]);
                } elseif ($searchType === 'phone') {
                    $stmt = $db->prepare("SELECT *, 'feedback' as app_type FROM member_feedback WHERE phone = ? ORDER BY created_at DESC");
                    $stmt->execute([$searchValue]);
                } else {
                    $stmt = $db->prepare("SELECT *, 'feedback' as app_type FROM member_feedback WHERE email = ? ORDER BY created_at DESC");
                    $stmt->execute([$searchValue]);
                }
                $fbResults = $stmt->fetchAll();
                if ($fbResults) $allResults = array_merge($allResults, $fbResults);
            } catch (Exception $e) {}

            // सदस्य कल्याण दाबी खोज्ने
            try {
                if ($searchType === 'tracking_id') {
                    $wlfId = $searchValue;
                    if (preg_match('/WLF-?[\w-]+/i', $searchValue)) {
                        $wlfId = $searchValue;
                    }
                    $stmt = $db->prepare("SELECT *, 'welfare_claim' as app_type FROM member_welfare_claims WHERE tracking_id = ?");
                    $stmt->execute([$wlfId]);
                } elseif ($searchType === 'phone') {
                    $stmt = $db->prepare("SELECT *, 'welfare_claim' as app_type FROM member_welfare_claims WHERE phone = ? ORDER BY created_at DESC");
                    $stmt->execute([$searchValue]);
                } else {
                    $stmt = $db->prepare("SELECT *, 'welfare_claim' as app_type FROM member_welfare_claims WHERE email = ? ORDER BY created_at DESC");
                    $stmt->execute([$searchValue]);
                }
                $wlfResults = $stmt->fetchAll();
                if ($wlfResults) $allResults = array_merge($allResults, $wlfResults);
            } catch (Exception $e) {}

            try {
                if ($searchType === 'tracking_id') {
                    $stmt = $db->prepare("SELECT *, 'digital_service' as app_type FROM digital_service_requests WHERE tracking_id = ?");
                    $stmt->execute([$searchValue]);
                } elseif ($searchType === 'phone') {
                    $stmt = $db->prepare("SELECT *, 'digital_service' as app_type FROM digital_service_requests WHERE phone = ? ORDER BY created_at DESC");
                    $stmt->execute([$searchValue]);
                } else {
                    $stmt = $db->prepare("SELECT *, 'digital_service' as app_type FROM digital_service_requests WHERE email = ? ORDER BY created_at DESC");
                    $stmt->execute([$searchValue]);
                }
                $digitalResults = $stmt->fetchAll();
                if ($digitalResults) $allResults = array_merge($allResults, $digitalResults);
            } catch (Exception $e) {}

            // Vendor enlistment खोज्ने
            try {
                if ($searchType === 'tracking_id') {
                    $stmt = $db->prepare("SELECT *, 'vendor' as app_type FROM vendors WHERE tracking_id = ?");
                    $stmt->execute([$searchValue]);
                } elseif ($searchType === 'phone') {
                    $stmt = $db->prepare("SELECT *, 'vendor' as app_type FROM vendors WHERE phone = ? ORDER BY created_at DESC");
                    $stmt->execute([$searchValue]);
                } else {
                    $stmt = $db->prepare("SELECT *, 'vendor' as app_type FROM vendors WHERE email = ? ORDER BY created_at DESC");
                    $stmt->execute([$searchValue]);
                }
                $vendorResults = $stmt->fetchAll();
                if ($vendorResults) $allResults = array_merge($allResults, $vendorResults);
            } catch (Exception $e) {}

            if (empty($allResults)) {
                /* खोजिएको आवेदन नभेटिएमा — helpful message */
                if ($searchType === 'tracking_id') {
                    $error = isEnglish()
                        ? 'No application found with this Tracking ID. Please check the ID and try again.<br><small class="text-muted">Examples: JOB-20240101-XXXX, APT-20260505-XXXXXX, DSR-XXXX-XXXXXX, GRV-123</small>'
                        : 'यो Tracking ID मा कुनै आवेदन भेटिएन। कृपया ID सही छ भनी जाँच गर्नुहोस्।<br><small class="text-muted">उदाहरण: JOB-20240101-XXXX, APT-20260505-XXXXXX, DSR-XXXX-XXXXXX, GRV-123</small>';
                } elseif ($searchType === 'phone') {
                    $error = isEnglish()
                        ? 'No application found with this phone number. Please check the number.'
                        : 'यो फोन नम्बरमा कुनै आवेदन भेटिएन। आवेदन गर्दा प्रयोग गरेको फोन नम्बर राख्नुहोस्।';
                } else {
                    $error = isEnglish()
                        ? 'No application found with this email address.'
                        : 'यो email मा कुनै आवेदन भेटिएन। आवेदन गर्दा प्रयोग गरेको email राख्नुहोस्।';
                }
            } else {
                /* आवेदन भेटिएमा directly देखाउँछु — कुनै extra verification चाहिँदैन */
                $success = isEnglish()
                    ? count($allResults) . ' application(s) found.'
                    : count($allResults) . ' आवेदन भेटियो।';
            }
        } catch (Exception $e) {
            $error = isEnglish() ? 'An error occurred. Please try again.' : 'त्रुटि भयो। कृपया पुनः प्रयास गर्नुहोस्।';
        }
    }

    if (in_array($searchType, ['phone', 'email'], true)) {
        $hasResults = !empty($allResults);
        if ($verificationOk && $hasResults) {
            $_SESSION['tracker_guard'][$trackerGuardKey] = ['fails' => 0, 'blocked_until' => 0];
        } elseif (!empty($error)) {
            $fails = (int)($_SESSION['tracker_guard'][$trackerGuardKey]['fails'] ?? 0) + 1;
            $blockedUntil = 0;
            if ($fails >= $trackerMaxAttempts) {
                $blockedUntil = time() + $trackerAttemptWindowSec;
                $fails = 0;
                $error = isEnglish()
                    ? 'For your security, too many unsuccessful attempts were detected. Please try again after 15 minutes.'
                    : 'सुरक्षाका कारण धेरै पटक नमिलेको प्रयास भयो। कृपया १५ मिनेटपछि फेरि प्रयास गर्नुहोस्।';
            }
            $_SESSION['tracker_guard'][$trackerGuardKey] = ['fails' => $fails, 'blocked_until' => $blockedUntil];
        }
    }
}

/* ── v9.9: Public ID Card preview eligibility ──
   Phone/Email verification सफल भएपछि, यदि उक्त phone वा email सँग
   members table मा approved + id_card_generated=1 भएको row छ भने,
   एउटा signed token बनाएर "View Digital ID Card" लिङ्क देखाउने।
   यो tracker.php को verification reuse गर्छ — extra OTP नभई secured छ। */
$publicIdCardLink = '';
$publicIdCardName = '';
if (!empty($verificationOk) && !empty($needsVerify) && in_array(($_POST['search_type'] ?? ''), ['phone','email'], true)) {
    try {
        $_db = getDB();
        $_phoneNorm = preg_replace('/\D/', '', ($_POST['sec_phone'] ?? ''));
        $_emailNorm = strtolower(trim($_POST['sec_email'] ?? ''));
        if ($_db && $_phoneNorm && $_emailNorm) {
            $_stmt = $_db->prepare(
                "SELECT id, name, member_card_no FROM members
                 WHERE (REPLACE(REPLACE(phone,' ',''),'-','') = ? OR REPLACE(REPLACE(phone,' ',''),'-','') LIKE ?)
                   AND LOWER(email) = ?
                   AND approval_status = 'approved'
                   AND id_card_generated = 1
                   AND is_active = 1
                 LIMIT 1"
            );
            $_likePhone = '%' . substr($_phoneNorm, -10);
            $_stmt->execute([$_phoneNorm, $_likePhone, $_emailNorm]);
            $_pmem = $_stmt->fetch(PDO::FETCH_ASSOC);
            if ($_pmem) {
                /* HMAC-signed short-lived token (15 min) — replay-safe public preview */
                $_secret = defined('AUTH_SECRET') ? AUTH_SECRET : (defined('SECRET_KEY') ? SECRET_KEY : 'aakash-fallback-secret-2026');
                $_exp    = time() + 900;
                $_payload= $_pmem['id'] . '.' . $_exp;
                $_sig    = hash_hmac('sha256', $_payload, $_secret);
                $publicIdCardLink = SITE_URL . 'tracker-id-card.php?mid=' . urlencode($_pmem['id'])
                                  . '&exp=' . $_exp . '&sig=' . urlencode($_sig);
                $publicIdCardName = $_pmem['name'];
            }
        }
    } catch (\Throwable $e) { error_log('public id-card eligibility: ' . $e->getMessage()); }
}

function getStatusBadgeClass($status) {
    if ($status === 'pending') return 'tracker-status tracker-status-pending';
    if (in_array($status, ['shortlisted', 'in_progress', 'reviewed', 'under_review', 'processing'], true)) return 'tracker-status tracker-status-info';
    if (in_array($status, ['interviewed', 'confirmed'], true)) return 'tracker-status tracker-status-muted';
    if (in_array($status, ['selected', 'approved', 'accepted', 'resolved', 'completed', 'disbursed', 'paid'], true)) return 'tracker-status tracker-status-success';
    if (in_array($status, ['rejected', 'closed', 'cancelled'], true)) return 'tracker-status tracker-status-danger';
    return 'tracker-status tracker-status-muted';
}

function getStatusText($status, $type = 'job') {
    $statusTextByType = [
        'grievance' => [
            'pending' => 'समीक्षाधीन / Pending',
            'in_progress' => 'कार्यान्वयनमा / In Progress',
            'resolved' => 'समाधान भयो / Resolved',
            'closed' => 'बन्द गरियो / Closed',
        ],
        'loan' => [
            'pending' => 'पेन्डिङ / Pending',
            'processing' => 'प्रक्रियामा / Processing',
            'approved' => 'स्वीकृत / Approved',
            'rejected' => 'अस्वीकृत / Rejected',
            'disbursed' => 'वितरण भयो / Disbursed',
        ],
        'kyc' => [
            'pending' => 'समीक्षाधीन / Pending',
            'approved' => 'स्वीकृत / Approved',
            'rejected' => 'अस्वीकृत / Rejected',
        ],
        'account' => [
            'pending' => 'समीक्षाधीन / Pending',
            'approved' => 'स्वीकृत / Approved',
            'rejected' => 'अस्वीकृत / Rejected',
        ],
        'auction_bid' => [
            'pending' => 'समीक्षाधीन / Pending',
            'accepted' => 'स्वीकृत / Accepted',
            'rejected' => 'अस्वीकृत / Rejected',
        ],
        'appointment' => [
            'pending' => 'पेन्डिङ / Pending',
            'confirmed' => 'पुष्टि भयो / Confirmed',
            'completed' => 'सम्पन्न / Completed',
            'cancelled' => 'रद्द गरियो / Cancelled',
        ],
        'feedback' => [
            'pending' => 'समीक्षाधीन / Pending',
            'reviewed' => 'समीक्षा भयो / Reviewed',
            'resolved' => 'समाधान भयो / Resolved',
        ],
        'welfare_claim' => [
            'pending' => 'पेन्डिङ / Pending',
            'under_review' => 'समीक्षाधीन / Under Review',
            'approved' => 'स्वीकृत / Approved',
            'rejected' => 'अस्वीकृत / Rejected',
            'paid' => 'भुक्तान भयो / Paid',
            'completed' => 'सम्पन्न / Completed',
        ],
        'digital_service' => [
            'pending' => 'पेन्डिङ / Pending',
            'processing' => 'प्रक्रियामा / Processing',
            'approved' => 'स्वीकृत / Approved',
            'rejected' => 'अस्वीकृत / Rejected',
            'completed' => 'सम्पन्न / Completed',
        ],
        '__default' => [
            'pending' => 'पेन्डिङ / Pending',
            'shortlisted' => 'छनोट भयो / Shortlisted',
            'interviewed' => 'अन्तर्वार्ता भयो / Interviewed',
            'selected' => 'चयन भयो / Selected',
            'rejected' => 'अस्वीकृत / Rejected',
        ],
    ];
    $map = $statusTextByType[$type] ?? $statusTextByType['__default'];
    return $map[$status] ?? $status;
}

function getAppTypeLabel($type) {
    $typeMap = [
        'job' => ['icon' => 'fa-briefcase', 'label' => 'रोजगारी आवेदन', 'label_en' => 'Job Application', 'color' => 'primary'],
        'loan' => ['icon' => 'fa-hand-holding-usd', 'label' => 'ऋण आवेदन', 'label_en' => 'Loan Application', 'color' => 'success'],
        'account' => ['icon' => 'fa-user-plus', 'label' => 'खाता खोल्ने आवेदन', 'label_en' => 'Account Application', 'color' => 'info'],
        'grievance' => ['icon' => 'fa-exclamation-circle', 'label' => 'गुनासो', 'label_en' => 'Grievance', 'color' => 'warning'],
        'kyc' => ['icon' => 'fa-id-card', 'label' => 'KYC अद्यावधिक', 'label_en' => 'KYC Update', 'color' => 'secondary'],
        'auction_bid' => ['icon' => 'fa-gavel', 'label' => 'लिलामी बोलपत्र', 'label_en' => 'Auction Bid', 'color' => 'danger'],
        'appointment' => ['icon' => 'fa-calendar-check', 'label' => 'भेटघाट बुक', 'label_en' => 'Appointment', 'color' => 'teal'],
        'feedback' => ['icon' => 'fa-comments', 'label' => 'सदस्य सर्वेक्षण/गुनासो', 'label_en' => 'Feedback/Survey', 'color' => 'purple'],
        'welfare_claim' => ['icon' => 'fa-hand-holding-heart', 'label' => 'कल्याण दाबी', 'label_en' => 'Welfare Claim', 'color' => 'pink'],
        'digital_service' => ['icon' => 'fa-mobile-alt', 'label' => 'डिजिटल सेवा अनुरोध', 'label_en' => 'Digital Service Request', 'color' => 'info'],
        'vendor' => ['icon' => 'fa-store', 'label' => 'सप्लायर दर्ता', 'label_en' => 'Vendor Enlistment', 'color' => 'warning'],
    ];
    return $typeMap[$type] ?? ['icon' => 'fa-file-alt', 'label' => 'आवेदन', 'label_en' => 'Application', 'color' => 'dark'];
}
?>

<!-- Page Banner — Premium Hero -->
<section class="tracker-hero-section">
    <div class="tracker-hero-bg-pattern"></div>
    <div class="container position-relative">
        <div class="tracker-hero-content text-center">
            <div class="tracker-hero-icon-wrap mb-3">
                <div class="tracker-hero-icon-ring">
                    <i class="fas fa-radar"></i>
                </div>
                <div class="tracker-hero-ping"></div>
            </div>
            <h1 class="tracker-hero-title">
                <?php echo isEnglish() ? 'Application Status Tracker' : 'आवेदन स्थिति ट्र्याकर'; ?>
            </h1>
            <p class="tracker-hero-sub">
                <?php echo isEnglish()
                    ? 'Track your Job, Loan, Account, Grievance &amp; more — all in one place.'
                    : 'रोजगारी, ऋण, खाता, गुनासो लगायत सबै आवेदनको स्थिति — एकैठाउँमा।'; ?>
            </p>
            <nav aria-label="breadcrumb" class="d-flex justify-content-center">
                <ol class="breadcrumb tracker-breadcrumb">
                    <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                    <li class="breadcrumb-item active"><?php echo isEnglish() ? 'Track Application' : 'आवेदन ट्र्याक'; ?></li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<section class="section-padding">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-10">

                <!-- Application Type Icons — Eye-Catching Pills -->
                <div class="tracker-types-strip mb-4">
                    <p class="tracker-types-label"><?php echo isEnglish() ? 'Track any application type:' : 'जुनसुकै आवेदन ट्र्याक गर्नुहोस्:'; ?></p>
                    <div class="tracker-type-pills">
                        <div class="type-pill" style="--pill-color:var(--primary-color)">
                            <div class="type-pill-icon"><i class="fas fa-briefcase"></i></div>
                            <span><?php echo isEnglish() ? 'Job' : 'रोजगारी'; ?></span>
                        </div>
                        <div class="type-pill" style="--pill-color:var(--primary-light)">
                            <div class="type-pill-icon"><i class="fas fa-hand-holding-usd"></i></div>
                            <span><?php echo isEnglish() ? 'Loan' : 'ऋण'; ?></span>
                        </div>
                        <div class="type-pill" style="--pill-color:var(--accent-color)">
                            <div class="type-pill-icon"><i class="fas fa-user-plus"></i></div>
                            <span><?php echo isEnglish() ? 'Account' : 'खाता'; ?></span>
                        </div>
                        <div class="type-pill" style="--pill-color:var(--secondary-color)">
                            <div class="type-pill-icon"><i class="fas fa-exclamation-circle"></i></div>
                            <span><?php echo isEnglish() ? 'Grievance' : 'गुनासो'; ?></span>
                        </div>
                        <div class="type-pill" style="--pill-color:var(--primary-dark)">
                            <div class="type-pill-icon"><i class="fas fa-id-card"></i></div>
                            <span>KYC</span>
                        </div>
                        <div class="type-pill" style="--pill-color:var(--secondary-color)">
                            <div class="type-pill-icon"><i class="fas fa-gavel"></i></div>
                            <span><?php echo isEnglish() ? 'Auction' : 'लिलामी'; ?></span>
                        </div>
                        <div class="type-pill" style="--pill-color:var(--primary-light)">
                            <div class="type-pill-icon"><i class="fas fa-calendar-check"></i></div>
                            <span><?php echo isEnglish() ? 'Appointment' : 'भेटघाट'; ?></span>
                        </div>
                        <div class="type-pill" style="--pill-color:var(--accent-color)">
                            <div class="type-pill-icon"><i class="fas fa-comments"></i></div>
                            <span><?php echo isEnglish() ? 'Survey' : 'सर्वेक्षण'; ?></span>
                        </div>
                        <div class="type-pill" style="--pill-color:var(--secondary-color)">
                            <div class="type-pill-icon"><i class="fas fa-hand-holding-heart"></i></div>
                            <span><?php echo isEnglish() ? 'Welfare' : 'सुविधा'; ?></span>
                        </div>
                        <div class="type-pill" style="--pill-color:var(--primary-color)">
                            <div class="type-pill-icon"><i class="fas fa-mobile-alt"></i></div>
                            <span><?php echo isEnglish() ? 'Digital' : 'डिजिटल'; ?></span>
                        </div>
                    </div>
                </div>

                <!-- Search Form Card — Premium -->
                <div class="tracker-search-card card shadow mb-4">
                    <div class="tracker-search-header">
                        <div class="tracker-search-header-icon">
                            <i class="fas fa-magnifying-glass-chart"></i>
                        </div>
                        <div>
                            <h5 class="mb-0 fw-bold"><?php echo isEnglish() ? 'Track All Your Applications' : 'सबै आवेदनहरू एकै ठाउँमा खोज्नुहोस्'; ?></h5>
                            <p class="mb-0 opacity-75 small"><?php echo isEnglish() ? 'Enter Tracking ID, Phone or Email' : 'Tracking ID, फोन वा इमेल राख्नुहोस्'; ?></p>
                        </div>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" id="trackerForm" class="needs-validation" novalidate>
                            <?php echo csrfField(); ?>
                            <div class="row g-3">
                                <!-- Search Type — expands to col-12 when phone/email (no separate search value) -->
                                <div id="colSearchType" class="col-md-4">
                                    <label class="form-label"><i class="fas fa-filter"></i> <?php echo isEnglish() ? 'Search By' : 'खोज्ने तरिका'; ?></label>
                                    <select name="search_type" class="form-select form-select-lg" id="searchType">
                                        <option value="tracking_id" <?php echo ($_POST['search_type'] ?? 'tracking_id') === 'tracking_id' ? 'selected' : ''; ?>>
                                            <?php echo isEnglish() ? 'Tracking ID / Reference No.' : 'ट्र्याकिङ ID / सन्दर्भ नं.'; ?>
                                        </option>
                                        <option value="phone" <?php echo ($_POST['search_type'] ?? '') === 'phone' ? 'selected' : ''; ?>>
                                            <?php echo isEnglish() ? 'Phone Number' : 'फोन नम्बर'; ?>
                                        </option>
                                        <option value="email" <?php echo ($_POST['search_type'] ?? '') === 'email' ? 'selected' : ''; ?>>
                                            <?php echo isEnglish() ? 'Email Address' : 'इमेल ठेगाना'; ?>
                                        </option>
                                    </select>
                                    <!-- Phone/Email चुनेपछि यहाँ tip देखाउने -->
                                    <small id="searchTypeTip" class="text-muted" style="display:none">
                                        <i class="fas fa-info-circle me-1 tracker-ico-info"></i>
                                        <span id="tipPhone" style="display:none"><?php echo isEnglish() ? 'Enter your phone number &amp; email below — they will be used for search &amp; verification.' : 'तल फोन र इमेल राख्नुहोस् — खोज र प्रमाणीकरण दुवैमा प्रयोग हुन्छ।'; ?></span>
                                        <span id="tipEmail" style="display:none"><?php echo isEnglish() ? 'Enter your email &amp; phone below — they will be used for search &amp; verification.' : 'तल इमेल र फोन राख्नुहोस् — खोज र प्रमाणीकरण दुवैमा प्रयोग हुन्छ।'; ?></span>
                                    </small>
                                </div>

                                <!-- Search Value — only for Tracking ID; hidden when phone/email -->
                                <div id="colSearchValue" class="col-md-8">
                                    <label class="form-label" id="searchLabel"><i class="fas fa-hashtag"></i> <?php echo isEnglish() ? 'Enter Tracking ID' : 'ट्र्याकिङ ID प्रविष्ट गर्नुहोस्'; ?></label>
                                    <input type="text" name="search_value" id="searchValue" class="form-control form-control-lg"
                                           placeholder="<?php echo isEnglish() ? 'e.g.: JOB-20240101-XXXX' : 'जस्तै: JOB-20240101-XXXX'; ?>"
                                           value="<?php echo htmlspecialchars($_POST['search_value'] ?? ''); ?>">
                                    <small class="text-muted">
                                        <span id="hintTrackingId"><?php echo isEnglish() ? 'e.g.: JOB-20240101-XXXX, APT-20260505-XXXXXX, FBK-2082-XXXXXX, WLF-XXXX-XXXXXX' : 'जस्तै: JOB-20240101-XXXX, APT-20260505-XXXXXX, FBK-2082-XXXXXX, WLF-XXXX-XXXXXX'; ?></span>
                                    </small>
                                </div>

                                <!-- ── Security Verification Section ──
                                     Phone / Email बाट खोज्दा मात्र देखिन्छ (JS ले control गर्छ)
                                     Code format: phone last 4 + email first 3 -->
                                <div id="verifySection" class="col-12" style="display:none">
                                    <div class="card tracker-verify-card">
                                        <div class="card-body py-3">
                                            <h6 class="mb-1 tracker-title-warn" id="verifySectionTitle">
                                                <i class="fas fa-shield-alt me-2 tracker-ico-warn"></i>
                                                <?php echo isEnglish() ? 'Search & Identity Verification' : 'खोज र पहिचान प्रमाणीकरण'; ?>
                                            </h6>
                                            <p class="text-muted small mb-3" id="verifySectionDesc">
                                                <?php echo isEnglish()
                                                    ? 'Enter phone and email used during application, then type security code (last 4 digits of phone + first 3 letters before @ in email).'
                                                    : 'आवेदनमा प्रयोग गरेको फोन र इमेल राख्नुहोस्, अनि सुरक्षा कोड टाइप गर्नुहोस् (फोनको अन्तिम ४ अंक + इमेलको @ अघिका पहिला ३ अक्षर)।'; ?>
                                            </p>
                                            <div class="row g-2 mb-3">
                                                <div class="col-md-4">
                                                    <label class="form-label small"><i class="fas fa-phone me-1 text-muted"></i>
                                                        <?php echo isEnglish() ? 'Phone Number (used when applying)' : 'फोन नम्बर (आवेदनमा प्रयोग गरिएको)'; ?>
                                                    </label>
                                                    <input type="tel" name="sec_phone" id="secPhone" class="form-control"
                                                           placeholder="<?php echo isEnglish() ? '10-digit phone number' : '१०-अंकको फोन नम्बर'; ?>"
                                                           value="<?php echo htmlspecialchars($_POST['sec_phone'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small"><i class="fas fa-envelope me-1 text-muted"></i>
                                                        <?php echo isEnglish() ? 'Email Address (used when applying)' : 'इमेल ठेगाना (आवेदनमा प्रयोग गरिएको)'; ?>
                                                    </label>
                                                    <input type="email" name="sec_email" id="secEmail" class="form-control"
                                                           placeholder="akashpame@gmail.com"
                                                           value="<?php echo htmlspecialchars($_POST['sec_email'] ?? ''); ?>">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small"><i class="fas fa-key me-1 tracker-ico-warn"></i>
                                                        <?php echo isEnglish() ? 'Security Code' : 'सुरक्षा कोड'; ?>
                                                    </label>
                                                    <div class="input-group">
                                                        <input type="password" name="security_code" id="securityCode" class="form-control"
                                                               placeholder="<?php echo isEnglish() ? 'e.g. 7000ram' : 'जस्तै: 7000ram'; ?>"
                                                               maxlength="12" autocomplete="off">
                                                        <button class="btn btn-outline-secondary" type="button"
                                                                id="secCodeToggle"
                                                                onclick="(function(){var f=document.getElementById('securityCode'),b=document.getElementById('secCodeToggle'),i=b.querySelector('i');if(!f)return;var show=f.type==='password';f.type=show?'text':'password';i.classList.toggle('fa-eye',!show);i.classList.toggle('fa-eye-slash',show);b.setAttribute('title',show?'<?php echo isEnglish()?"Hide":"लुकाउनुहोस्"; ?>':'<?php echo isEnglish()?"Show":"देखाउनुहोस्"; ?>');})();"
                                                                title="<?php echo isEnglish() ? 'Show security code' : 'सुरक्षा कोड देखाउनुहोस्'; ?>"
                                                                style="border-left:0;color:#6b7280;">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="small rounded-2 p-2 tracker-verify-rule">
                                                <i class="fas fa-info-circle tracker-ico-warn me-1"></i>
                                                <?php echo isEnglish()
                                                    ? 'Code rule: last 4 digits of phone + first 3 letters of email before @ (example: 9827157000 + ram@gmail.com => 7000ram).'
                                                    : 'कोड बनाउने नियम: फोनको अन्तिम ४ अंक + इमेलको @ अघिका पहिला ३ अक्षर (उदाहरण: 9827157000 + ram@gmail.com => 7000ram)।'; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- खोज बटन -->
                                <div class="col-12 d-flex align-items-center gap-3 flex-wrap">
                                    <button type="submit" class="btn btn-tracker-search btn-lg px-5">
                                        <i class="fas fa-search me-2"></i><?php echo isEnglish() ? 'Search Application' : 'आवेदन खोज्नुहोस्'; ?>
                                    </button>
                                    <small class="text-muted" id="verifyNote">
                                        <i class="fas fa-lock tracker-ico-ok me-1"></i>
                                        <?php echo isEnglish()
                                            ? 'Tracking ID search — no extra verification needed.'
                                            : 'Tracking ID बाट खोज्दा थप प्रमाणीकरण आवश्यक छैन।'; ?>
                                    </small>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if ($error): ?>
                <div class="alert alert-warning tracker-alert alert-dismissible fade show d-flex align-items-start gap-2">
                    <i class="fas fa-exclamation-triangle fa-lg mt-1 flex-shrink-0"></i>
                    <div><?php echo $error; ?></div>
                    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>

                <?php if ($success && !empty($allResults)): ?>
                <div class="alert alert-success tracker-alert d-flex align-items-center gap-2 py-2">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo count($allResults); ?> <?php echo isEnglish() ? 'application(s) found.' : 'आवेदन भेटियो।'; ?></span>
                </div>
                <?php endif; ?>

                <?php if (!empty($publicIdCardLink)): ?>
                <!-- v9.9: Public Digital ID Card preview — verified phone+email match approved member -->
                <div class="public-id-card-cta mb-3">
                    <div class="public-id-card-icon-wrap">
                        <i class="fas fa-id-card public-id-card-icon"></i>
                    </div>
                    <div class="public-id-card-content">
                        <div class="public-id-card-title">
                            <?php echo isEnglish() ? '🪪 Your Digital Member ID Card' : '🪪 तपाईंको डिजिटल सदस्य परिचयपत्र'; ?>
                        </div>
                        <div class="public-id-card-text">
                            <?php echo isEnglish()
                                ? 'Verified for ' . htmlspecialchars($publicIdCardName) . '. Click to preview your official member ID card.'
                                : htmlspecialchars($publicIdCardName) . ' को आधिकारिक डिजिटल परिचयपत्र हेर्नुहोस् (verified phone + email)।'; ?>
                        </div>
                    </div>
                    <a href="<?php echo htmlspecialchars($publicIdCardLink); ?>" target="_blank" rel="noopener"
                       class="public-id-card-btn">
                        <i class="fas fa-eye"></i>
                        <?php echo isEnglish() ? 'View ID Card' : 'ID Card हेर्नुहोस्'; ?>
                    </a>
                </div>
                <?php endif; ?>

                <?php if (!empty($allResults)): ?>
                <?php
                    $doneStatuses = ['completed','approved','resolved','closed','rejected','selected','cancelled','disbursed','paid','accepted'];
                    $activeCount = 0; $doneCount = 0;
                    foreach ($allResults as $_r) {
                        $s = $_r['status'] ?? 'pending';
                        if (in_array($s, $doneStatuses)) $doneCount++; else $activeCount++;
                    }
                ?>
                <!-- Tab Navigation -->
                <div class="tracker-tabs-nav mb-3">
                    <button class="tracker-tab-btn active" data-tab="active">
                        <i class="fas fa-clock-rotate-left"></i>
                        <?php echo isEnglish() ? 'Active / New' : 'नयाँ / सक्रिय'; ?>
                        <?php if ($activeCount > 0): ?><span class="tab-count-badge tab-badge-active"><?php echo $activeCount; ?></span><?php endif; ?>
                    </button>
                    <button class="tracker-tab-btn" data-tab="done">
                        <i class="fas fa-check-circle"></i>
                        <?php echo isEnglish() ? 'Completed / Old' : 'पुरानो / सम्पन्न'; ?>
                        <?php if ($doneCount > 0): ?><span class="tab-count-badge tab-badge-done"><?php echo $doneCount; ?></span><?php endif; ?>
                    </button>
                    <div class="tracker-tab-empty-msg" id="tabEmptyMsg" style="display:none;">
                        <i class="fas fa-inbox"></i>
                        <span id="tabEmptyText"><?php echo isEnglish() ? 'No applications in this category.' : 'यस श्रेणीमा कुनै आवेदन छैन।'; ?></span>
                    </div>
                </div>


                <!-- All Results -->
                <div class="results-container">
                    <?php foreach ($allResults as $app):
                        $typeInfo = getAppTypeLabel($app['app_type']);
                        $_status = $app['status'] ?? 'pending';
                        $_tabGroup = in_array($_status, $doneStatuses) ? 'done' : 'active';
                        $statusHistory = trackerFetchHistoryEntries($app);
                    ?>
                    <div class="result-card-premium mb-4" data-tab-group="<?php echo $_tabGroup; ?>" <?php if ($_tabGroup === 'done'): ?>style="display:none;"<?php endif; ?>>
                        <div class="rcp-accent rcp-accent-<?php echo $typeInfo['color']; ?>"></div>
                        <div class="rcp-body">
                            <div class="rcp-header">
                                <div class="rcp-icon-wrap rcp-icon-<?php echo $typeInfo['color']; ?>">
                                    <i class="fas <?php echo $typeInfo['icon']; ?>"></i>
                                </div>
                                <div class="rcp-meta">
                                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                        <span class="rcp-type-badge rcp-badge-<?php echo $typeInfo['color']; ?>">
                                            <i class="fas <?php echo $typeInfo['icon']; ?> me-1"></i><?php echo isEnglish() ? $typeInfo['label_en'] : $typeInfo['label']; ?>
                                        </span>
                                        <span class="rcp-status-badge <?php echo getStatusBadgeClass($app['status'] ?? 'pending'); ?> rcp-status-glow">
                                            <?php echo getStatusText($app['status'] ?? 'pending', $app['app_type']); ?>
                                        </span>
                                    </div>

                                    <h5 class="mb-1">
                                        <?php
                                        if ($app['app_type'] === 'job') {
                                            echo htmlspecialchars($app['job_title_np'] ?? $app['job_title'] ?? 'रोजगारी आवेदन');
                                        } elseif ($app['app_type'] === 'grievance') {
                                            echo htmlspecialchars($app['subject'] ?? 'गुनासो');
                                        } elseif ($app['app_type'] === 'auction_bid') {
                                            echo htmlspecialchars($app['auction_title'] ?? 'लिलामी बोलपत्र');
                                        } elseif ($app['app_type'] === 'loan') {
                                            echo htmlspecialchars($app['loan_type'] ?? 'ऋण आवेदन');
                                        } elseif ($app['app_type'] === 'account') {
                                            echo htmlspecialchars($app['account_type'] ?? 'खाता आवेदन');
                                        } elseif ($app['app_type'] === 'appointment') {
                                            $purposeLabels = [
                                                'account_inquiry' => isEnglish() ? 'Account Inquiry' : 'खाता जानकारी',
                                                'loan_inquiry' => isEnglish() ? 'Loan Inquiry' : 'ऋण जानकारी',
                                                'kyc_update' => isEnglish() ? 'KYC Update' : 'केवाइसी अपडेट',
                                                'loan_repayment' => isEnglish() ? 'Loan Repayment' : 'ऋण भुक्तानी',
                                                'account_opening' => isEnglish() ? 'Account Opening' : 'खाता खोल्ने',
                                                'other' => isEnglish() ? 'Other' : 'अन्य'
                                            ];
                                            echo htmlspecialchars($purposeLabels[$app['purpose'] ?? 'other'] ?? 'भेटघाट');
                                        } elseif ($app['app_type'] === 'feedback') {
                                            echo htmlspecialchars($app['subject'] ?? $app['type'] ?? 'सर्वेक्षण/गुनासो');
                                        } elseif ($app['app_type'] === 'welfare_claim') {
                                            $claimTypes = [
                                                'maternity' => isEnglish() ? 'Maternity Benefit' : 'सुत्केरी सुविधा',
                                                'death' => isEnglish() ? 'Death Benefit' : 'मृत्यु सुविधा',
                                                'insurance' => isEnglish() ? 'Insurance Claim' : 'बीमा दाबी',
                                                'medical' => isEnglish() ? 'Medical Expense' : 'उपचार खर्च',
                                                'other' => isEnglish() ? 'Other Benefit' : 'अन्य सुविधा'
                                            ];
                                            echo htmlspecialchars($claimTypes[$app['claim_type'] ?? 'other'] ?? 'कल्याण दाबी');
                                        } elseif ($app['app_type'] === 'digital_service') {
                                            $dsLabels = ['statement_request'=>'खाता विवरण','bill_payment'=>'बिल भुक्तानी','mobile_recharge'=>'मोबाइल रिचार्ज','fund_transfer'=>'रकम स्थानान्तरण','loan_statement'=>'ऋण विवरण','cheque_book'=>'चेकबुक','atm_card'=>'ATM कार्ड','internet_banking'=>'इन्टरनेट बैंकिङ','mobile_banking'=>'मोबाइल बैंकिङ','other_service'=>'अन्य सेवा'];
                                            echo htmlspecialchars($dsLabels[$app['service_type'] ?? ''] ?? $app['service_type_np'] ?? $app['service_type'] ?? 'डिजिटल सेवा अनुरोध');
                                        } else {
                                            echo isEnglish() ? $typeInfo['label_en'] : $typeInfo['label'];
                                        }
                                        ?>
                                    </h5>

                                    <div class="rcp-chips mt-1">
                                        <span class="rcp-chip"><i class="fas fa-user"></i><?php echo htmlspecialchars(substr($app['full_name'] ?? $app['name'] ?? $app['member_name'] ?? $app['requester_name'] ?? $app['bidder_name'] ?? '-', 0, 30)); ?></span>
                                        <span class="rcp-chip"><i class="fas fa-calendar-alt"></i><?php echo date('Y-m-d', strtotime($app['created_at'])); ?></span>
                                        <?php if (!empty($app['tracking_id'])): ?>
                                        <span class="rcp-chip"><i class="fas fa-hashtag"></i><?php echo htmlspecialchars($app['tracking_id']); ?></span>
                                        <?php elseif ($app['app_type'] === 'grievance'): ?>
                                        <span class="rcp-chip"><i class="fas fa-hashtag"></i>GRV-<?php echo $app['id']; ?></span>
                                        <?php elseif ($app['app_type'] === 'kyc'): ?>
                                        <span class="rcp-chip"><i class="fas fa-hashtag"></i>KYC-<?php echo $app['id']; ?></span>
                                        <?php elseif ($app['app_type'] === 'auction_bid'): ?>
                                        <span class="rcp-chip"><i class="fas fa-hashtag"></i>BID-<?php echo $app['id']; ?></span>
                                        <?php elseif ($app['app_type'] === 'appointment'): ?>
                                        <span class="rcp-chip"><i class="fas fa-hashtag"></i><?php echo htmlspecialchars($app['tracking_id'] ?? ('APT-' . str_pad((string) ($app['id'] ?? 0), 6, '0', STR_PAD_LEFT))); ?></span>
                                        <?php elseif ($app['app_type'] === 'feedback'): ?>
                                        <span class="rcp-chip"><i class="fas fa-hashtag"></i>FBK-<?php echo $app['id']; ?></span>
                                        <?php endif; ?>
                                    </div><!-- /.rcp-chips -->
                                </div><!-- /.rcp-meta -->
                            </div><!-- /.rcp-header -->
                            <!-- Details toggle button -->
                            <div class="rcp-details-toggle mt-2">
                                <button class="btn btn-sm btn-outline-primary rcp-toggle-btn" type="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#details-<?php echo $app['app_type'] . '-' . $app['id']; ?>"
                                        aria-expanded="false">
                                    <i class="fas fa-chevron-down me-1"></i><?php echo isEnglish() ? 'View Details' : 'विवरण हेर्नुहोस्'; ?>
                                </button>
                            </div>

                            <!-- Expandable Details -->
                            <div class="collapse" id="details-<?php echo $app['app_type'] . '-' . $app['id']; ?>">
                                <div class="rcp-timeline-wrap">

                                    <!-- ══ Applicant Detail Info Grid (all types) ══ -->
                                    <?php
                                    $diName   = $app['full_name']  ?? $app['name']        ?? $app['member_name']   ?? $app['requester_name'] ?? $app['bidder_name']  ?? '';
                                    $diPhone  = $app['mobile']     ?? $app['phone']        ?? $app['bidder_phone']  ?? '';
                                    $diEmail  = $app['email']      ?? $app['bidder_email'] ?? '';
                                    $diAddr   = $app['address']    ?? $app['permanent_address'] ?? '';
                                    $diMemId  = $app['member_id']  ?? '';
                                    $diBranch = $app['branch']     ?? '';
                                    $diDate   = !empty($app['created_at']) ? date('Y-m-d  H:i', strtotime($app['created_at'])) : '';
                                    ?>
                                    <div class="di-grid mb-3">
                                        <?php if ($diName): ?><div class="di-item"><span class="di-label"><i class="fas fa-user"></i> <?php echo isEnglish() ? 'Name' : 'नाम'; ?></span><span class="di-value"><?php echo e($diName); ?></span></div><?php endif; ?>
                                        <?php if ($diPhone): ?><div class="di-item"><span class="di-label"><i class="fas fa-phone"></i> <?php echo isEnglish() ? 'Phone' : 'फोन'; ?></span><span class="di-value"><?php echo e($diPhone); ?></span></div><?php endif; ?>
                                        <?php if ($diEmail): ?><div class="di-item"><span class="di-label"><i class="fas fa-envelope"></i> <?php echo isEnglish() ? 'Email' : 'इमेल'; ?></span><span class="di-value" style="word-break:break-all"><?php echo e(mb_substr($diEmail,0,30)); ?></span></div><?php endif; ?>
                                        <?php if ($diMemId): ?><div class="di-item"><span class="di-label"><i class="fas fa-id-badge"></i> <?php echo isEnglish() ? 'Member ID' : 'सदस्य नं.'; ?></span><span class="di-value"><?php echo e($diMemId); ?></span></div><?php endif; ?>
                                        <?php if ($diDate): ?><div class="di-item"><span class="di-label"><i class="fas fa-calendar-alt"></i> <?php echo isEnglish() ? 'Submitted' : 'दर्ता मिति'; ?></span><span class="di-value"><?php echo $diDate; ?></span></div><?php endif; ?>
                                        <?php if ($diBranch): ?><div class="di-item"><span class="di-label"><i class="fas fa-building"></i> <?php echo isEnglish() ? 'Branch' : 'शाखा'; ?></span><span class="di-value"><?php echo e($diBranch); ?></span></div><?php endif; ?>

                                        <?php if ($app['app_type'] === 'loan'): ?>
                                        <?php if (!empty($app['loan_type'])): ?><div class="di-item"><span class="di-label"><i class="fas fa-tag"></i> <?php echo isEnglish() ? 'Loan Type' : 'ऋण प्रकार'; ?></span><span class="di-value"><?php echo e($app['loan_type']); ?></span></div><?php endif; ?>
                                        <?php if (!empty($app['loan_amount'])): ?><div class="di-item"><span class="di-label"><i class="fas fa-rupee-sign"></i> <?php echo isEnglish() ? 'Requested Amount' : 'अनुरोधित रकम'; ?></span><span class="di-value fw-bold tracker-amt-ok">रु. <?php echo number_format((float)$app['loan_amount']); ?></span></div><?php endif; ?>
                                        <?php if (!empty($app['loan_purpose'])): ?><div class="di-item di-item-wide"><span class="di-label"><i class="fas fa-info-circle"></i> <?php echo isEnglish() ? 'Purpose' : 'उद्देश्य'; ?></span><span class="di-value"><?php echo e(mb_substr($app['loan_purpose'],0,80)); ?></span></div><?php endif; ?>
                                        <?php if (!empty($app['occupation'])): ?><div class="di-item"><span class="di-label"><i class="fas fa-briefcase"></i> <?php echo isEnglish() ? 'Occupation' : 'पेशा'; ?></span><span class="di-value"><?php echo e($app['occupation']); ?></span></div><?php endif; ?>
                                        <?php if (!empty($app['monthly_income'])): ?><div class="di-item"><span class="di-label"><i class="fas fa-wallet"></i> <?php echo isEnglish() ? 'Monthly Income' : 'मासिक आय'; ?></span><span class="di-value">रु. <?php echo number_format((float)$app['monthly_income']); ?></span></div><?php endif; ?>
                                        <?php if (!empty($app['guarantor_name'])): ?><div class="di-item"><span class="di-label"><i class="fas fa-user-check"></i> <?php echo isEnglish() ? 'Guarantor' : 'जमानतकर्ता'; ?></span><span class="di-value"><?php echo e($app['guarantor_name']); ?></span></div><?php endif; ?>
                                        <?php if (!empty($app['collateral_type'])): ?><div class="di-item"><span class="di-label"><i class="fas fa-shield-alt"></i> <?php echo isEnglish() ? 'Collateral' : 'धितो'; ?></span><span class="di-value"><?php echo e($app['collateral_type']); ?></span></div><?php endif; ?>

                                        <?php elseif ($app['app_type'] === 'account'): ?>
                                        <?php if (!empty($app['account_type'])): ?><div class="di-item"><span class="di-label"><i class="fas fa-wallet"></i> <?php echo isEnglish() ? 'Account Type' : 'खाता प्रकार'; ?></span><span class="di-value"><?php echo e($app['account_type']); ?></span></div><?php endif; ?>
                                        <?php if (!empty($app['gender'])): ?><div class="di-item"><span class="di-label"><i class="fas fa-venus-mars"></i> <?php echo isEnglish() ? 'Gender' : 'लिङ्ग'; ?></span><span class="di-value"><?php echo e($app['gender']); ?></span></div><?php endif; ?>
                                        <?php if (!empty($app['dob_bs'])): ?><div class="di-item"><span class="di-label"><i class="fas fa-birthday-cake"></i> <?php echo isEnglish() ? 'DOB (BS)' : 'जन्म मिति (बि.स.)'; ?></span><span class="di-value"><?php echo e($app['dob_bs']); ?></span></div><?php endif; ?>
                                        <?php if (!empty($app['citizenship_no'])): ?><div class="di-item"><span class="di-label"><i class="fas fa-id-card"></i> <?php echo isEnglish() ? 'Citizenship No.' : 'नागरिकता नं.'; ?></span><span class="di-value"><?php echo e($app['citizenship_no']); ?></span></div><?php endif; ?>
                                        <?php if (!empty($app['initial_deposit'])): ?><div class="di-item"><span class="di-label"><i class="fas fa-rupee-sign"></i> <?php echo isEnglish() ? 'Initial Deposit' : 'प्रारम्भिक जम्मा'; ?></span><span class="di-value fw-bold tracker-amt-ok">रु. <?php echo number_format((float)$app['initial_deposit']); ?></span></div><?php endif; ?>
                                        <?php if (!empty($app['occupation'])): ?><div class="di-item"><span class="di-label"><i class="fas fa-briefcase"></i> <?php echo isEnglish() ? 'Occupation' : 'पेशा'; ?></span><span class="di-value"><?php echo e($app['occupation']); ?></span></div><?php endif; ?>
                                        <?php if (!empty($app['nominee_name'])): ?><div class="di-item"><span class="di-label"><i class="fas fa-user-friends"></i> <?php echo isEnglish() ? 'Nominee' : 'हकदार'; ?></span><span class="di-value"><?php echo e($app['nominee_name']); ?></span></div><?php endif; ?>
                                        <?php if ($diAddr): ?><div class="di-item di-item-wide"><span class="di-label"><i class="fas fa-map-marker-alt"></i> <?php echo isEnglish() ? 'Address' : 'ठेगाना'; ?></span><span class="di-value"><?php echo e(mb_substr($diAddr,0,80)); ?></span></div><?php endif; ?>

                                        <?php elseif ($app['app_type'] === 'grievance'): ?>
                                        <?php if (!empty($app['category'])): ?><div class="di-item"><span class="di-label"><i class="fas fa-tag"></i> <?php echo isEnglish() ? 'Category' : 'श्रेणी'; ?></span><span class="di-value"><?php echo e($app['category']); ?></span></div><?php endif; ?>
                                        <?php if (!empty($app['subject'])): ?><div class="di-item di-item-wide"><span class="di-label"><i class="fas fa-heading"></i> <?php echo isEnglish() ? 'Subject' : 'विषय'; ?></span><span class="di-value"><?php echo e(mb_substr($app['subject'],0,100)); ?></span></div><?php endif; ?>
                                        <?php if (!empty($app['description'])): ?><div class="di-item di-item-full"><span class="di-label"><i class="fas fa-align-left"></i> <?php echo isEnglish() ? 'Description' : 'विवरण'; ?></span><span class="di-value"><?php echo nl2br(e(mb_substr($app['description'],0,250))); ?><?php if(mb_strlen($app['description'])>250):?>…<?php endif;?></span></div><?php endif; ?>

                                        <?php elseif ($app['app_type'] === 'kyc'): ?>
                                        <?php if (!empty($app['citizenship_no'])): ?><div class="di-item"><span class="di-label"><i class="fas fa-id-card"></i> <?php echo isEnglish() ? 'Citizenship No.' : 'नागरिकता नं.'; ?></span><span class="di-value"><?php echo e($app['citizenship_no']); ?></span></div><?php endif; ?>
                                        <?php if ($diAddr): ?><div class="di-item di-item-wide"><span class="di-label"><i class="fas fa-map-marker-alt"></i> <?php echo isEnglish() ? 'Address' : 'ठेगाना'; ?></span><span class="di-value"><?php echo e(mb_substr($diAddr,0,80)); ?></span></div><?php endif; ?>

                                        <?php elseif ($app['app_type'] === 'appointment'): ?>
                                        <?php $aptPurposeLabels=['account_inquiry'=>'खाता जानकारी','loan_inquiry'=>'ऋण जानकारी','kyc_update'=>'KYC अपडेट','loan_repayment'=>'ऋण भुक्तानी','account_opening'=>'खाता खोल्ने','other'=>'अन्य']; ?>
                                        <?php if (!empty($app['purpose'])): ?><div class="di-item"><span class="di-label"><i class="fas fa-question-circle"></i> <?php echo isEnglish() ? 'Purpose' : 'उद्देश्य'; ?></span><span class="di-value"><?php echo e($aptPurposeLabels[$app['purpose']] ?? $app['purpose']); ?></span></div><?php endif; ?>
                                        <?php if (!empty($app['preferred_date'])): ?><div class="di-item"><span class="di-label"><i class="fas fa-calendar"></i> <?php echo isEnglish() ? 'Pref. Date' : 'मनपर्ने मिति'; ?></span><span class="di-value"><?php echo e($app['preferred_date']); ?></span></div><?php endif; ?>
                                        <?php if (!empty($app['preferred_time'])): ?><div class="di-item"><span class="di-label"><i class="fas fa-clock"></i> <?php echo isEnglish() ? 'Pref. Time' : 'समय'; ?></span><span class="di-value"><?php echo e($app['preferred_time']); ?></span></div><?php endif; ?>
                                        <?php if (!empty($app['purpose_detail'])): ?><div class="di-item di-item-full"><span class="di-label"><i class="fas fa-align-left"></i> <?php echo isEnglish() ? 'Details' : 'थप विवरण'; ?></span><span class="di-value"><?php echo nl2br(e(mb_substr($app['purpose_detail'],0,200))); ?></span></div><?php endif; ?>

                                        <?php elseif ($app['app_type'] === 'digital_service'): ?>
                                        <?php $dsTypeLabels=['statement_request'=>'खाता विवरण','bill_payment'=>'बिल भुक्तानी','mobile_recharge'=>'मोबाइल रिचार्ज','fund_transfer'=>'रकम स्थानान्तरण','loan_statement'=>'ऋण विवरण','cheque_book'=>'चेकबुक','atm_card'=>'ATM कार्ड','internet_banking'=>'इन्टरनेट बैंकिङ','mobile_banking'=>'मोबाइल बैंकिङ','other_service'=>'अन्य सेवा']; ?>
                                        <?php if (!empty($app['service_type'])): ?><div class="di-item"><span class="di-label"><i class="fas fa-mobile-alt"></i> <?php echo isEnglish() ? 'Service Type' : 'सेवा प्रकार'; ?></span><span class="di-value"><?php echo e($dsTypeLabels[$app['service_type']] ?? $app['service_type']); ?></span></div><?php endif; ?>
                                        <?php if (!empty($app['preferred_contact'])): ?><div class="di-item"><span class="di-label"><i class="fas fa-headset"></i> <?php echo isEnglish() ? 'Contact Via' : 'सम्पर्क माध्यम'; ?></span><span class="di-value"><?php echo e($app['preferred_contact']); ?></span></div><?php endif; ?>
                                        <?php if (!empty($app['account_no'] ?? $app['account_number'] ?? '')): ?><div class="di-item"><span class="di-label"><i class="fas fa-hashtag"></i> <?php echo isEnglish() ? 'Account No.' : 'खाता नं.'; ?></span><span class="di-value"><?php echo e($app['account_no'] ?? $app['account_number']); ?></span></div><?php endif; ?>
                                        <?php if (!empty($app['request_details'])): ?><div class="di-item di-item-full"><span class="di-label"><i class="fas fa-align-left"></i> <?php echo isEnglish() ? 'Request Details' : 'अनुरोध विवरण'; ?></span><span class="di-value"><?php echo nl2br(e(mb_substr($app['request_details'],0,250))); ?></span></div><?php endif; ?>

                                        <?php elseif ($app['app_type'] === 'welfare_claim'): ?>
                                        <?php $wlfTypeLabels=['maternity'=>'सुत्केरी सुविधा','death'=>'मृत्यु सुविधा','insurance'=>'बीमा दाबी','medical'=>'उपचार खर्च','other'=>'अन्य सुविधा']; ?>
                                        <?php if (!empty($app['claim_type'])): ?><div class="di-item"><span class="di-label"><i class="fas fa-hand-holding-heart"></i> <?php echo isEnglish() ? 'Claim Type' : 'दाबीको प्रकार'; ?></span><span class="di-value"><?php echo e($wlfTypeLabels[$app['claim_type']] ?? $app['claim_type']); ?></span></div><?php endif; ?>
                                        <?php if (!empty($app['claim_amount'])): ?><div class="di-item"><span class="di-label"><i class="fas fa-rupee-sign"></i> <?php echo isEnglish() ? 'Claimed Amount' : 'दाबी रकम'; ?></span><span class="di-value fw-bold tracker-amt-info">रु. <?php echo number_format((float)$app['claim_amount']); ?></span></div><?php endif; ?>
                                        <?php if ($diAddr): ?><div class="di-item"><span class="di-label"><i class="fas fa-map-marker-alt"></i> <?php echo isEnglish() ? 'Address' : 'ठेगाना'; ?></span><span class="di-value"><?php echo e(mb_substr($diAddr,0,60)); ?></span></div><?php endif; ?>
                                        <?php if (!empty($app['description'])): ?><div class="di-item di-item-full"><span class="di-label"><i class="fas fa-align-left"></i> <?php echo isEnglish() ? 'Description' : 'विवरण'; ?></span><span class="di-value"><?php echo nl2br(e(mb_substr($app['description'],0,200))); ?></span></div><?php endif; ?>

                                        <?php elseif ($app['app_type'] === 'feedback'): ?>
                                        <?php if (!empty($app['type'])): ?><div class="di-item"><span class="di-label"><i class="fas fa-tag"></i> <?php echo isEnglish() ? 'Type' : 'प्रकार'; ?></span><span class="di-value"><?php echo e($app['type']); ?></span></div><?php endif; ?>
                                        <?php if (!empty($app['subject'])): ?><div class="di-item di-item-wide"><span class="di-label"><i class="fas fa-heading"></i> <?php echo isEnglish() ? 'Subject' : 'विषय'; ?></span><span class="di-value"><?php echo e(mb_substr($app['subject'],0,100)); ?></span></div><?php endif; ?>
                                        <?php if (!empty($app['message'])): ?><div class="di-item di-item-full"><span class="di-label"><i class="fas fa-comment"></i> <?php echo isEnglish() ? 'Message' : 'सन्देश'; ?></span><span class="di-value"><?php echo nl2br(e(mb_substr($app['message'],0,200))); ?></span></div><?php endif; ?>

                                        <?php elseif ($app['app_type'] === 'auction_bid'): ?>
                                        <?php if (!empty($app['auction_title'])): ?><div class="di-item di-item-wide"><span class="di-label"><i class="fas fa-gavel"></i> <?php echo isEnglish() ? 'Auction' : 'लिलामी'; ?></span><span class="di-value"><?php echo e($app['auction_title']); ?></span></div><?php endif; ?>
                                        <?php if (!empty($app['bid_amount'])): ?><div class="di-item"><span class="di-label"><i class="fas fa-rupee-sign"></i> <?php echo isEnglish() ? 'Bid Amount' : 'बोलपत्र रकम'; ?></span><span class="di-value fw-bold tracker-amt-danger">रु. <?php echo number_format((float)$app['bid_amount']); ?></span></div><?php endif; ?>

                                        <?php elseif ($app['app_type'] === 'vendor'): ?>
                                        <?php if (!empty($app['company_name'])): ?><div class="di-item"><span class="di-label"><i class="fas fa-building"></i> <?php echo isEnglish() ? 'Company' : 'कम्पनी'; ?></span><span class="di-value"><?php echo e($app['company_name']); ?></span></div><?php endif; ?>
                                        <?php if (!empty($app['business_type'])): ?><div class="di-item"><span class="di-label"><i class="fas fa-tag"></i> <?php echo isEnglish() ? 'Business Type' : 'व्यापारको किसिम'; ?></span><span class="di-value"><?php echo e($app['business_type']); ?></span></div><?php endif; ?>
                                        <?php if (!empty($app['pan_no'])): ?><div class="di-item"><span class="di-label"><i class="fas fa-file-invoice"></i> PAN No.</span><span class="di-value"><?php echo e($app['pan_no']); ?></span></div><?php endif; ?>
                                        <?php if ($diAddr): ?><div class="di-item di-item-wide"><span class="di-label"><i class="fas fa-map-marker-alt"></i> <?php echo isEnglish() ? 'Address' : 'ठेगाना'; ?></span><span class="di-value"><?php echo e(mb_substr($diAddr,0,80)); ?></span></div><?php endif; ?>
                                        <?php if (!empty($app['description'])): ?><div class="di-item di-item-full"><span class="di-label"><i class="fas fa-align-left"></i> <?php echo isEnglish() ? 'Description' : 'विवरण'; ?></span><span class="di-value"><?php echo nl2br(e(mb_substr($app['description'],0,200))); ?></span></div><?php endif; ?>

                                        <?php elseif ($app['app_type'] === 'job'): ?>
                                        <?php if (!empty($app['job_title_np'] ?? $app['job_title'] ?? '')): ?><div class="di-item di-item-wide"><span class="di-label"><i class="fas fa-briefcase"></i> <?php echo isEnglish() ? 'Position Applied' : 'आवेदन दिएको पद'; ?></span><span class="di-value"><?php echo e($app['job_title_np'] ?? $app['job_title'] ?? ''); ?></span></div><?php endif; ?>
                                        <?php if ($diAddr): ?><div class="di-item"><span class="di-label"><i class="fas fa-map-marker-alt"></i> <?php echo isEnglish() ? 'Address' : 'ठेगाना'; ?></span><span class="di-value"><?php echo e(mb_substr($diAddr,0,60)); ?></span></div><?php endif; ?>
                                        <?php endif; ?>
                                    </div><!-- /.di-grid -->
                                    <hr class="my-2 opacity-25">

                                    <?php if ($app['app_type'] === 'job'): ?>
                                    <!-- Job Application Timeline -->
                                    <div class="status-timeline">
                                        <div class="d-flex justify-content-between position-relative">
                                            <div class="timeline-line"></div>
                                            <div class="timeline-step <?php echo in_array($app['status'], ['pending', 'shortlisted', 'interviewed', 'selected', 'rejected']) ? 'active' : ''; ?>">
                                                <div class="step-icon"><i class="fas fa-file-alt"></i></div>
                                                <small><?php echo isEnglish() ? 'Applied' : 'आवेदन'; ?></small>
                                            </div>
                                            <div class="timeline-step <?php echo in_array($app['status'], ['shortlisted', 'interviewed', 'selected']) ? 'active' : ''; ?>">
                                                <div class="step-icon"><i class="fas fa-list-check"></i></div>
                                                <small><?php echo isEnglish() ? 'Shortlist' : 'छनोट'; ?></small>
                                            </div>
                                            <div class="timeline-step <?php echo in_array($app['status'], ['interviewed', 'selected']) ? 'active' : ''; ?>">
                                                <div class="step-icon"><i class="fas fa-comments"></i></div>
                                                <small><?php echo isEnglish() ? 'Interview' : 'अन्तर्वार्ता'; ?></small>
                                            </div>
                                            <div class="timeline-step <?php echo $app['status'] === 'selected' ? 'active success' : ($app['status'] === 'rejected' ? 'active rejected' : ''); ?>">
                                                <div class="step-icon">
                                                    <?php if ($app['status'] === 'rejected'): ?>
                                                    <i class="fas fa-times"></i>
                                                    <?php else: ?>
                                                    <i class="fas fa-check"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <small><?php echo isEnglish() ? 'Result' : 'परिणाम'; ?></small>
                                            </div>
                                        </div>
                                    </div>
                                    <?php elseif ($app['app_type'] === 'grievance'): ?>
                                    <!-- Grievance Timeline -->
                                    <div class="status-timeline">
                                        <div class="d-flex justify-content-between position-relative">
                                            <div class="timeline-line"></div>
                                            <div class="timeline-step <?php echo in_array($app['status'], ['pending', 'in_progress', 'resolved', 'closed']) ? 'active' : ''; ?>">
                                                <div class="step-icon"><i class="fas fa-file-alt"></i></div>
                                                <small><?php echo isEnglish() ? 'Submitted' : 'दर्ता'; ?></small>
                                            </div>
                                            <div class="timeline-step <?php echo in_array($app['status'], ['in_progress', 'resolved', 'closed']) ? 'active' : ''; ?>">
                                                <div class="step-icon"><i class="fas fa-cogs"></i></div>
                                                <small><?php echo isEnglish() ? 'Processing' : 'कार्यान्वयन'; ?></small>
                                            </div>
                                            <div class="timeline-step <?php echo in_array($app['status'], ['resolved', 'closed']) ? 'active success' : ''; ?>">
                                                <div class="step-icon"><i class="fas fa-check"></i></div>
                                                <small><?php echo isEnglish() ? 'Resolved' : 'समाधान'; ?></small>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if (!empty($app['admin_response'])): ?>
                                    <div class="admin-response-block mt-2">
                                        <strong><i class="fas fa-reply tracker-ico-primary"></i> <?php echo isEnglish() ? 'Response:' : 'प्रतिक्रिया:'; ?></strong>
                                        <p class="mb-0 mt-1"><?php echo nl2br(htmlspecialchars($app['admin_response'])); ?></p>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($app['admin_attachment'])): ?>
                                    <div class="mt-2 p-2 bg-light rounded border d-flex align-items-center gap-2">
                                        <i class="fas fa-paperclip tracker-ico-primary"></i>
                                        <span class="small text-muted"><?php echo isEnglish() ? 'Admin document:' : 'Admin संलग्न:'; ?></span>
                                        <a href="<?php echo SITE_URL . '/' . ltrim(htmlspecialchars($app['admin_attachment']), '/'); ?>"
                                           target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2">
                                            <i class="fas fa-download me-1"></i><?php echo basename($app['admin_attachment']); ?>
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    <?php elseif ($app['app_type'] === 'appointment'): ?>
                                    <!-- Appointment Timeline -->
                                    <div class="status-timeline">
                                        <div class="d-flex justify-content-between position-relative">
                                            <div class="timeline-line"></div>
                                            <div class="timeline-step active">
                                                <div class="step-icon"><i class="fas fa-calendar-plus"></i></div>
                                                <small><?php echo isEnglish() ? 'Booked' : 'बुक'; ?></small>
                                            </div>
                                            <div class="timeline-step <?php echo in_array($app['status'] ?? 'pending', ['confirmed', 'completed']) ? 'active' : ''; ?>">
                                                <div class="step-icon"><i class="fas fa-check-circle"></i></div>
                                                <small><?php echo isEnglish() ? 'Confirmed' : 'पुष्टि'; ?></small>
                                            </div>
                                            <div class="timeline-step <?php echo ($app['status'] ?? '') === 'completed' ? 'active success' : (($app['status'] ?? '') === 'cancelled' ? 'active rejected' : ''); ?>">
                                                <div class="step-icon">
                                                    <?php if (($app['status'] ?? '') === 'cancelled'): ?>
                                                    <i class="fas fa-times"></i>
                                                    <?php else: ?>
                                                    <i class="fas fa-calendar-check"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <small><?php echo isEnglish() ? 'Complete' : 'सम्पन्न'; ?></small>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if (!empty($app['preferred_date'])): ?>
                                    <div class="admin-response-block mt-2">
                                        <strong><i class="fas fa-clock tracker-ico-primary"></i> <?php echo isEnglish() ? 'Scheduled:' : 'तालिका:'; ?></strong>
                                        <p class="mb-0 mt-1"><?php echo htmlspecialchars($app['preferred_date'] . ' ' . ($app['preferred_time'] ?? '')); ?></p>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($app['remarks'])): ?>
                                    <div class="admin-response-block mt-2">
                                        <strong><i class="fas fa-reply tracker-ico-primary"></i> <?php echo isEnglish() ? 'Admin Response:' : 'Admin जवाफ:'; ?></strong>
                                        <p class="mb-0 mt-1"><?php echo nl2br(htmlspecialchars($app['remarks'])); ?></p>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($app['admin_attachment'])): ?>
                                    <div class="mt-2 p-2 bg-light rounded border d-flex align-items-center gap-2">
                                        <i class="fas fa-paperclip tracker-ico-primary"></i>
                                        <span class="small text-muted"><?php echo isEnglish() ? 'Admin document:' : 'Admin संलग्न:'; ?></span>
                                        <a href="<?php echo SITE_URL . '/' . ltrim(htmlspecialchars($app['admin_attachment']), '/'); ?>"
                                           target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2">
                                            <i class="fas fa-download me-1"></i><?php echo basename($app['admin_attachment']); ?>
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    <?php elseif ($app['app_type'] === 'feedback'): ?>
                                    <!-- Feedback Timeline -->
                                    <div class="status-timeline">
                                        <div class="d-flex justify-content-between position-relative">
                                            <div class="timeline-line"></div>
                                            <div class="timeline-step active">
                                                <div class="step-icon"><i class="fas fa-paper-plane"></i></div>
                                                <small><?php echo isEnglish() ? 'Submitted' : 'पेश'; ?></small>
                                            </div>
                                            <div class="timeline-step <?php echo in_array($app['status'] ?? 'pending', ['reviewed', 'resolved']) ? 'active' : ''; ?>">
                                                <div class="step-icon"><i class="fas fa-eye"></i></div>
                                                <small><?php echo isEnglish() ? 'Reviewed' : 'समीक्षा'; ?></small>
                                            </div>
                                            <div class="timeline-step <?php echo ($app['status'] ?? '') === 'resolved' ? 'active success' : ''; ?>">
                                                <div class="step-icon"><i class="fas fa-check"></i></div>
                                                <small><?php echo isEnglish() ? 'Resolved' : 'समाधान'; ?></small>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if (!empty($app['message'])): ?>
                                    <div class="admin-response-block mt-2">
                                        <strong><i class="fas fa-comment tracker-ico-primary"></i> <?php echo isEnglish() ? 'Message:' : 'सन्देश:'; ?></strong>
                                        <p class="mb-0 mt-1"><?php echo nl2br(htmlspecialchars($app['message'])); ?></p>
                                    </div>
                                    <?php endif; ?>
                                    <?php elseif ($app['app_type'] === 'digital_service'): ?>
                                    <div class="status-timeline">
                                        <div class="d-flex justify-content-between position-relative">
                                            <div class="timeline-line"></div>
                                            <div class="timeline-step active">
                                                <div class="step-icon"><i class="fas fa-paper-plane"></i></div>
                                                <small><?php echo isEnglish() ? 'Submitted' : 'पेश'; ?></small>
                                            </div>
                                            <div class="timeline-step <?php echo in_array($app['status'] ?? 'pending', ['processing', 'approved', 'completed']) ? 'active' : ''; ?>">
                                                <div class="step-icon"><i class="fas fa-cogs"></i></div>
                                                <small><?php echo isEnglish() ? 'Processing' : 'प्रक्रिया'; ?></small>
                                            </div>
                                            <div class="timeline-step <?php echo in_array($app['status'] ?? 'pending', ['approved', 'completed']) ? 'active success' : (($app['status'] ?? '') === 'rejected' ? 'active rejected' : ''); ?>">
                                                <div class="step-icon">
                                                    <?php if (($app['status'] ?? '') === 'rejected'): ?>
                                                    <i class="fas fa-times"></i>
                                                    <?php else: ?>
                                                    <i class="fas fa-check"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <small><?php echo isEnglish() ? 'Complete' : 'सम्पन्न'; ?></small>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if (!empty($app['admin_remarks'])): ?>
                                    <div class="admin-response-block mt-2">
                                        <strong><i class="fas fa-reply tracker-ico-primary"></i> <?php echo isEnglish() ? 'Response:' : 'प्रतिक्रिया:'; ?></strong>
                                        <p class="mb-0 mt-1"><?php echo nl2br(htmlspecialchars($app['admin_remarks'])); ?></p>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($app['admin_attachment'])): ?>
                                    <div class="mt-2 p-2 bg-light rounded border d-flex align-items-center gap-2">
                                        <i class="fas fa-paperclip tracker-ico-primary"></i>
                                        <span class="small text-muted"><?php echo isEnglish() ? 'Admin document:' : 'Admin संलग्न:'; ?></span>
                                        <a href="<?php echo SITE_URL . '/' . ltrim(htmlspecialchars($app['admin_attachment']), '/'); ?>"
                                           target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2">
                                            <i class="fas fa-download me-1"></i><?php echo basename($app['admin_attachment']); ?>
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    <?php elseif ($app['app_type'] === 'welfare_claim'): ?>
                                    <!-- Welfare Claim Timeline -->
                                    <div class="status-timeline">
                                        <div class="d-flex justify-content-between position-relative">
                                            <div class="timeline-line"></div>
                                            <div class="timeline-step active">
                                                <div class="step-icon"><i class="fas fa-file-alt"></i></div>
                                                <small><?php echo isEnglish() ? 'Applied' : 'दर्ता'; ?></small>
                                            </div>
                                            <div class="timeline-step <?php echo in_array($app['status'] ?? 'pending', ['under_review', 'approved', 'rejected', 'paid', 'completed']) ? 'active' : ''; ?>">
                                                <div class="step-icon"><i class="fas fa-search"></i></div>
                                                <small><?php echo isEnglish() ? 'Review' : 'समीक्षा'; ?></small>
                                            </div>
                                            <div class="timeline-step <?php echo in_array($app['status'] ?? 'pending', ['approved', 'paid', 'completed']) ? 'active' : ($app['status'] === 'rejected' ? 'active rejected' : ''); ?>">
                                                <div class="step-icon">
                                                    <?php if ($app['status'] === 'rejected'): ?>
                                                    <i class="fas fa-times"></i>
                                                    <?php else: ?>
                                                    <i class="fas fa-thumbs-up"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <small><?php echo isEnglish() ? 'Decision' : 'निर्णय'; ?></small>
                                            </div>
                                            <div class="timeline-step <?php echo in_array($app['status'] ?? 'pending', ['paid', 'completed']) ? 'active success' : ''; ?>">
                                                <div class="step-icon"><i class="fas fa-rupee-sign"></i></div>
                                                <small><?php echo isEnglish() ? 'Paid' : 'भुक्तान'; ?></small>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if (!empty($app['approved_amount'])): ?>
                                    <div class="admin-response-block mt-2">
                                        <strong><i class="fas fa-money-bill tracker-ico-ok"></i> <?php echo isEnglish() ? 'Approved Amount:' : 'स्वीकृत रकम:'; ?></strong>
                                        <span class="tracker-amt-ok fw-bold">रु. <?php echo number_format($app['approved_amount']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($app['admin_remarks'])): ?>
                                    <div class="admin-response-block mt-2">
                                        <strong><i class="fas fa-comment tracker-ico-primary"></i> <?php echo isEnglish() ? 'Remarks:' : 'टिप्पणी:'; ?></strong>
                                        <p class="mb-0 mt-1"><?php echo nl2br(htmlspecialchars($app['admin_remarks'])); ?></p>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($app['admin_attachment'])): ?>
                                    <div class="mt-2 p-2 bg-light rounded border d-flex align-items-center gap-2">
                                        <i class="fas fa-paperclip tracker-ico-primary"></i>
                                        <span class="small text-muted"><?php echo isEnglish() ? 'Admin document:' : 'Admin संलग्न:'; ?></span>
                                        <a href="<?php echo SITE_URL . '/' . ltrim(htmlspecialchars($app['admin_attachment']), '/'); ?>"
                                           target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2">
                                            <i class="fas fa-download me-1"></i><?php echo basename($app['admin_attachment']); ?>
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    <?php elseif ($app['app_type'] === 'loan'): ?>
                                    <!-- Loan Application Timeline - More Detailed -->
                                    <div class="status-timeline">
                                        <div class="d-flex justify-content-between position-relative">
                                            <div class="timeline-line"></div>
                                            <div class="timeline-step active">
                                                <div class="step-icon"><i class="fas fa-file-alt"></i></div>
                                                <small><?php echo isEnglish() ? 'Applied' : 'आवेदन'; ?></small>
                                            </div>
                                            <div class="timeline-step <?php echo in_array($app['status'] ?? 'pending', ['processing', 'approved', 'rejected', 'disbursed']) ? 'active' : ''; ?>">
                                                <div class="step-icon"><i class="fas fa-cogs"></i></div>
                                                <small><?php echo isEnglish() ? 'Processing' : 'प्रक्रिया'; ?></small>
                                            </div>
                                            <div class="timeline-step <?php echo in_array($app['status'] ?? 'pending', ['approved', 'disbursed']) ? 'active' : ($app['status'] === 'rejected' ? 'active rejected' : ''); ?>">
                                                <div class="step-icon">
                                                    <?php if ($app['status'] === 'rejected'): ?>
                                                    <i class="fas fa-times"></i>
                                                    <?php else: ?>
                                                    <i class="fas fa-thumbs-up"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <small><?php echo isEnglish() ? 'Decision' : 'निर्णय'; ?></small>
                                            </div>
                                            <div class="timeline-step <?php echo ($app['status'] ?? '') === 'disbursed' ? 'active success' : ''; ?>">
                                                <div class="step-icon"><i class="fas fa-money-bill-wave"></i></div>
                                                <small><?php echo isEnglish() ? 'Disbursed' : 'वितरण'; ?></small>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if (!empty($app['approved_amount'])): ?>
                                    <div class="admin-response-block mt-2">
                                        <strong><i class="fas fa-money-bill tracker-ico-ok"></i> <?php echo isEnglish() ? 'Approved Amount:' : 'स्वीकृत रकम:'; ?></strong>
                                        <span class="tracker-amt-ok fw-bold">रु. <?php echo number_format((float)($app['approved_amount'] ?? 0)); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($app['remarks'])): ?>
                                    <div class="admin-response-block mt-2">
                                        <strong><i class="fas fa-reply tracker-ico-primary"></i> <?php echo isEnglish() ? 'Admin Response:' : 'Admin जवाफ:'; ?></strong>
                                        <p class="mb-0 mt-1"><?php echo nl2br(htmlspecialchars($app['remarks'])); ?></p>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($app['admin_attachment'])): ?>
                                    <div class="mt-2 p-2 bg-light rounded border d-flex align-items-center gap-2">
                                        <i class="fas fa-paperclip tracker-ico-primary"></i>
                                        <span class="small text-muted"><?php echo isEnglish() ? 'Admin document:' : 'Admin संलग्न:'; ?></span>
                                        <a href="<?php echo SITE_URL . '/' . ltrim(htmlspecialchars($app['admin_attachment']), '/'); ?>"
                                           target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2">
                                            <i class="fas fa-download me-1"></i><?php echo basename($app['admin_attachment']); ?>
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    <?php elseif ($app['app_type'] === 'account'): ?>
                                    <!-- Account Application Timeline -->
                                    <div class="status-timeline">
                                        <div class="d-flex justify-content-between position-relative">
                                            <div class="timeline-line"></div>
                                            <div class="timeline-step active">
                                                <div class="step-icon"><i class="fas fa-user-plus"></i></div>
                                                <small><?php echo isEnglish() ? 'Applied' : 'आवेदन'; ?></small>
                                            </div>
                                            <div class="timeline-step <?php echo in_array($app['status'] ?? 'pending', ['processing', 'approved', 'rejected']) ? 'active' : ''; ?>">
                                                <div class="step-icon"><i class="fas fa-id-card"></i></div>
                                                <small><?php echo isEnglish() ? 'Verification' : 'प्रमाणीकरण'; ?></small>
                                            </div>
                                            <div class="timeline-step <?php echo ($app['status'] ?? '') === 'approved' ? 'active success' : (($app['status'] ?? '') === 'rejected' ? 'active rejected' : ''); ?>">
                                                <div class="step-icon">
                                                    <?php if (($app['status'] ?? '') === 'rejected'): ?>
                                                    <i class="fas fa-times"></i>
                                                    <?php else: ?>
                                                    <i class="fas fa-check-circle"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <small><?php echo isEnglish() ? 'Approved' : 'स्वीकृत'; ?></small>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if (!empty($app['remarks'])): ?>
                                    <div class="admin-response-block mt-2">
                                        <strong><i class="fas fa-reply tracker-ico-primary"></i> <?php echo isEnglish() ? 'Admin Response:' : 'Admin जवाफ:'; ?></strong>
                                        <p class="mb-0 mt-1"><?php echo nl2br(htmlspecialchars($app['remarks'])); ?></p>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($app['admin_attachment'])): ?>
                                    <div class="mt-2 p-2 bg-light rounded border d-flex align-items-center gap-2">
                                        <i class="fas fa-paperclip tracker-ico-primary"></i>
                                        <span class="small text-muted"><?php echo isEnglish() ? 'Admin document:' : 'Admin संलग्न:'; ?></span>
                                        <a href="<?php echo SITE_URL . '/' . ltrim(htmlspecialchars($app['admin_attachment']), '/'); ?>"
                                           target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2">
                                            <i class="fas fa-download me-1"></i><?php echo basename($app['admin_attachment']); ?>
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    <?php elseif ($app['app_type'] === 'kyc'): ?>
                                    <!-- KYC Application Timeline -->
                                    <div class="status-timeline">
                                        <div class="d-flex justify-content-between position-relative">
                                            <div class="timeline-line"></div>
                                            <div class="timeline-step active">
                                                <div class="step-icon"><i class="fas fa-upload"></i></div>
                                                <small><?php echo isEnglish() ? 'Submitted' : 'पेश'; ?></small>
                                            </div>
                                            <div class="timeline-step <?php echo in_array($app['status'] ?? 'pending', ['approved', 'rejected']) ? 'active' : ''; ?>">
                                                <div class="step-icon"><i class="fas fa-search"></i></div>
                                                <small><?php echo isEnglish() ? 'Verify' : 'जाँच'; ?></small>
                                            </div>
                                            <div class="timeline-step <?php echo ($app['status'] ?? '') === 'approved' ? 'active success' : (($app['status'] ?? '') === 'rejected' ? 'active rejected' : ''); ?>">
                                                <div class="step-icon">
                                                    <?php if (($app['status'] ?? '') === 'rejected'): ?>
                                                    <i class="fas fa-times"></i>
                                                    <?php else: ?>
                                                    <i class="fas fa-check"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <small><?php echo isEnglish() ? 'Updated' : 'अपडेट'; ?></small>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if (!empty($app['remarks'])): ?>
                                    <div class="admin-response-block mt-2">
                                        <strong><i class="fas fa-reply tracker-ico-primary"></i> <?php echo isEnglish() ? 'Admin Response:' : 'Admin जवाफ:'; ?></strong>
                                        <p class="mb-0 mt-1"><?php echo nl2br(htmlspecialchars($app['remarks'])); ?></p>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($app['admin_attachment'])): ?>
                                    <div class="mt-2 p-2 bg-light rounded border d-flex align-items-center gap-2">
                                        <i class="fas fa-paperclip tracker-ico-primary"></i>
                                        <span class="small text-muted"><?php echo isEnglish() ? 'Admin document:' : 'Admin संलग्न:'; ?></span>
                                        <a href="<?php echo SITE_URL . '/' . ltrim(htmlspecialchars($app['admin_attachment']), '/'); ?>"
                                           target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2">
                                            <i class="fas fa-download me-1"></i><?php echo basename($app['admin_attachment']); ?>
                                        </a>
                                    </div>
                                    <?php endif; ?>
                                    <?php elseif ($app['app_type'] === 'auction_bid'): ?>
                                    <!-- Auction Bid Timeline -->
                                    <div class="status-timeline">
                                        <div class="d-flex justify-content-between position-relative">
                                            <div class="timeline-line"></div>
                                            <div class="timeline-step active">
                                                <div class="step-icon"><i class="fas fa-gavel"></i></div>
                                                <small><?php echo isEnglish() ? 'Bid' : 'बोलपत्र'; ?></small>
                                            </div>
                                            <div class="timeline-step <?php echo in_array($app['status'] ?? 'pending', ['accepted', 'rejected']) ? 'active' : ''; ?>">
                                                <div class="step-icon"><i class="fas fa-balance-scale"></i></div>
                                                <small><?php echo isEnglish() ? 'Evaluate' : 'मूल्यांकन'; ?></small>
                                            </div>
                                            <div class="timeline-step <?php echo ($app['status'] ?? '') === 'accepted' ? 'active success' : (($app['status'] ?? '') === 'rejected' ? 'active rejected' : ''); ?>">
                                                <div class="step-icon">
                                                    <?php if (($app['status'] ?? '') === 'rejected'): ?>
                                                    <i class="fas fa-times"></i>
                                                    <?php else: ?>
                                                    <i class="fas fa-trophy"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <small><?php echo isEnglish() ? 'Result' : 'परिणाम'; ?></small>
                                            </div>
                                        </div>
                                    </div>
                                    <?php if (!empty($app['bid_amount'])): ?>
                                    <div class="admin-response-block mt-2">
                                        <strong><i class="fas fa-tag tracker-ico-primary"></i> <?php echo isEnglish() ? 'Bid Amount:' : 'बोलपत्र रकम:'; ?></strong>
                                        <span class="fw-bold">रु. <?php echo number_format($app['bid_amount']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php else: ?>
                                    <!-- Generic Timeline for Unknown Types -->
                                    <div class="status-timeline">
                                        <div class="d-flex justify-content-between position-relative">
                                            <div class="timeline-line"></div>
                                            <div class="timeline-step active">
                                                <div class="step-icon"><i class="fas fa-file-alt"></i></div>
                                                <small><?php echo isEnglish() ? 'Submitted' : 'दर्ता'; ?></small>
                                            </div>
                                            <div class="timeline-step <?php echo in_array($app['status'], ['approved', 'accepted', 'rejected']) ? 'active' : ''; ?>">
                                                <div class="step-icon"><i class="fas fa-search"></i></div>
                                                <small><?php echo isEnglish() ? 'Review' : 'समीक्षा'; ?></small>
                                            </div>
                                            <div class="timeline-step <?php echo in_array($app['status'], ['approved', 'accepted']) ? 'active success' : ($app['status'] === 'rejected' ? 'active rejected' : ''); ?>">
                                                <div class="step-icon">
                                                    <?php if ($app['status'] === 'rejected'): ?>
                                                    <i class="fas fa-times"></i>
                                                    <?php else: ?>
                                                    <i class="fas fa-check"></i>
                                                    <?php endif; ?>
                                                </div>
                                                <small><?php echo isEnglish() ? 'Decision' : 'निर्णय'; ?></small>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (!empty($statusHistory)): ?>
                                    <div class="admin-response-block mt-2">
                                        <strong><i class="fas fa-clock-rotate-left tracker-ico-primary"></i> <?php echo isEnglish() ? 'Status / Comment History' : 'स्टाटस / कमेन्ट इतिहास'; ?></strong>
                                        <?php foreach ($statusHistory as $h):
                                            $emS = (string)($h['notify_email_status'] ?? '');
                                            $smS = (string)($h['notify_sms_status']   ?? '');
                                            $emR = (string)($h['notify_email_reason'] ?? '');
                                            $smR = (string)($h['notify_sms_reason']   ?? '');
                                            $hasV2 = ($emS !== '' || $smS !== '');
                                            $renderChannelChip = static function (string $kind, string $st, string $reason) {
                                                if ($st === '' || $st === 'not_attempted') return '';
                                                $isEn = function_exists('isEnglish') && isEnglish();
                                                $icon  = $kind === 'email' ? 'fa-envelope' : 'fa-mobile-screen';
                                                $kLbl  = $kind === 'email' ? 'Email' : 'SMS';
                                                $map   = [
                                                    'sent'    => ['trkc-ok',   $isEn ? 'sent'    : 'पठाइयो'],
                                                    'failed'  => ['trkc-err',  $isEn ? 'failed'  : 'असफल'],
                                                    'skipped' => ['trkc-skip', $isEn ? 'skipped' : 'पठाइएन'],
                                                ];
                                                $info = $map[$st] ?? ['trkc-skip', $st];
                                                $tip  = trim($kLbl . ' — ' . $info[1] . ($reason !== '' ? ' (' . $reason . ')' : ''));
                                                return '<span class="trkc-chip ' . $info[0] . '" title="' . htmlspecialchars($tip, ENT_QUOTES, 'UTF-8') . '">'
                                                     . '<i class="fas ' . $icon . '"></i> ' . $kLbl . ': '
                                                     . htmlspecialchars($info[1], ENT_QUOTES, 'UTF-8') . '</span>';
                                            };
                                        ?>
                                        <div class="mt-2 p-2 bg-light rounded border">
                                            <div class="small fw-semibold text-dark"><?php echo htmlspecialchars((string)($h['old_status'] ?: '—')); ?> → <?php echo htmlspecialchars((string)($h['new_status'] ?: '—')); ?></div>
                                            <?php if (!empty($h['admin_comment'])): ?>
                                            <div class="small mt-1"><?php echo nl2br(htmlspecialchars((string)$h['admin_comment'])); ?></div>
                                            <?php endif; ?>
                                            <div class="small text-muted mt-1">
                                                <?php echo htmlspecialchars((string)($h['actor_name'] ?: 'Admin')); ?> ·
                                                <?php echo formatNepaliDate((string)$h['created_at'], true); ?>
                                            </div>
                                            <?php
                                            if ($hasV2):
                                                $emailChip = $renderChannelChip('email', $emS, $emR);
                                                $smsChip   = $renderChannelChip('sms',   $smS, $smR);
                                                if ($emailChip !== '' || $smsChip !== ''):
                                            ?>
                                            <div class="trkc-chip-row mt-2"><?php echo $emailChip . $smsChip; ?></div>
                                            <?php   endif;
                                            elseif (!empty($h['notify_sent'])): ?>
                                            <div class="trkc-chip-row mt-2">
                                                <span class="trkc-chip trkc-ok"><i class="fas fa-bell"></i> <?php echo isEnglish() ? 'Notification sent' : 'सूचना पठाइयो'; ?></span>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>

                                    <?php if (!empty($app['admin_notes']) || !empty($app['remarks'])): ?>
                                    <div class="admin-response-block mt-2">
                                        <strong><i class="fas fa-comment tracker-ico-primary"></i> <?php echo isEnglish() ? 'Notes:' : 'टिप्पणी:'; ?></strong>
                                        <p class="mb-0 mt-1"><?php echo nl2br(htmlspecialchars($app['admin_notes'] ?? $app['remarks'] ?? '')); ?></p>
                                    </div>
                                    <?php endif; ?>
                                </div><!-- /.rcp-timeline-wrap / collapse inner -->
                            </div><!-- /.collapse -->
                        </div><!-- /.rcp-body -->
                    </div><!-- /.result-card-premium -->
                    <?php endforeach; ?>
                </div><!-- /.results-container -->
                <?php endif; ?>

                <!-- Help Info — Visual Callout -->
                <div class="tracker-help-strip mt-5">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="tracker-help-card">
                                <div class="thc-icon thc-icon-primary">
                                    <i class="fas fa-hashtag"></i>
                                </div>
                                <div>
                                    <h6 class="mb-1"><?php echo isEnglish() ? 'Tracking ID' : 'ट्र्याकिङ ID'; ?></h6>
                                    <p class="mb-0 text-muted small"><?php echo isEnglish() ? 'JOB-XXXX, APT-YYYYMMDD-XXXXXX, GRV-123, WLF-XXXX, DSR-XXXX' : 'JOB-XXXX, APT-YYYYMMDD-XXXXXX, GRV-123, WLF-XXXX, DSR-XXXX'; ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="tracker-help-card">
                                <div class="thc-icon thc-icon-success">
                                    <i class="fas fa-phone"></i>
                                </div>
                                <div>
                                    <h6 class="mb-1"><?php echo isEnglish() ? 'Phone Search' : 'फोनद्वारा खोज'; ?></h6>
                                    <p class="mb-0 text-muted small"><?php echo isEnglish() ? 'Phone number used during application' : 'आवेदनमा प्रयोग गरेको फोन नम्बर'; ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="tracker-help-card">
                                <div class="thc-icon thc-icon-warn">
                                    <i class="fas fa-headset"></i>
                                </div>
                                <div>
                                    <h6 class="mb-1"><?php echo isEnglish() ? 'Need Help?' : 'सहायता चाहिन्छ?'; ?></h6>
                                    <p class="mb-0 tracker-text-muted small"><a href="<?php echo SITE_URL; ?>contact.php" class="tracker-link-warn fw-semibold"><?php echo isEnglish() ? 'Contact us →' : 'हामीलाई सम्पर्क गर्नुहोस् →'; ?></a></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</section>

<script>
/* Tracker form: search type बदल्दा placeholder र hint update गर्ने */
function updateSearchHint() {
    const searchType = document.getElementById('searchType');
    const searchValue = document.getElementById('searchValue');
    const hintTrackingId = document.getElementById('hintTrackingId');
    const hintPhone = document.getElementById('hintPhone');
    const hintEmail = document.getElementById('hintEmail');
    if (!searchType) return;

    const val = searchType.value;
    if (hintTrackingId) hintTrackingId.style.display = val === 'tracking_id' ? '' : 'none';
    if (hintPhone) hintPhone.style.display = val === 'phone' ? '' : 'none';
    if (hintEmail) hintEmail.style.display = val === 'email' ? '' : 'none';

    if (searchValue) {
        if (val === 'phone') {
            searchValue.placeholder = '<?php echo isEnglish() ? "e.g.: 9827157000" : "जस्तै: 9827157000"; ?>';
        } else if (val === 'email') {
            searchValue.placeholder = '<?php echo isEnglish() ? "e.g.: akashpame@gmail.com" : "जस्तै: akashpame@gmail.com"; ?>';
        } else {
            searchValue.placeholder = '<?php echo isEnglish() ? "e.g.: JOB-20240101-XXXX, APT-20260505-XXXXXX" : "जस्तै: JOB-20240101-XXXX, APT-20260505-XXXXXX"; ?>';
        }
    }
}
const searchTypeEl = document.getElementById('searchType');
if (searchTypeEl) {
    searchTypeEl.addEventListener('change', updateSearchHint);
    updateSearchHint();
}

/* Tracker Result Tabs: show/hide cards by tab group */
(function() {
    var tabBtns = document.querySelectorAll('.tracker-tab-btn');
    var emptyMsg = document.getElementById('tabEmptyMsg');

    function switchTab(targetTab) {
        var cards = document.querySelectorAll('[data-tab-group]');
        var visibleCount = 0;
        cards.forEach(function(card) {
            if (card.getAttribute('data-tab-group') === targetTab) {
                card.style.display = '';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });
        tabBtns.forEach(function(btn) {
            btn.classList.toggle('active', btn.getAttribute('data-tab') === targetTab);
        });
        if (emptyMsg) {
            emptyMsg.style.display = visibleCount === 0 ? 'block' : 'none';
        }
    }

    tabBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            switchTab(this.getAttribute('data-tab'));
        });
    });

    var activeCards = document.querySelectorAll('[data-tab-group="active"]');
    if (activeCards.length === 0) {
        switchTab('done');
    }
})();
</script>


<script>
/* ================================================================
   Application Tracker — Security Verification Section JS
   — Phone / Email चुन्दा सुरक्षा section देखाउँछ
   — Security code user ले manually टाइप गर्छ
   ================================================================ */
(function() {
    const searchType   = document.getElementById('searchType');
    const verifySection = document.getElementById('verifySection');
    const verifyNote   = document.getElementById('verifyNote');

    /* hint spans */
    const hintTid   = document.getElementById('hintTrackingId');
    const hintPhone = document.getElementById('hintPhone');
    const hintEmail = document.getElementById('hintEmail');

    /* Element refs for column visibility */
    const colSearchType  = document.getElementById('colSearchType');
    const colSearchValue = document.getElementById('colSearchValue');
    const searchValueInp = document.getElementById('searchValue');
    const searchTypeTip  = document.getElementById('searchTypeTip');
    const tipPhone       = document.getElementById('tipPhone');
    const tipEmail       = document.getElementById('tipEmail');

    /* Show/hide sections based on selected search type */
    function updateUI() {
        const v = searchType ? searchType.value : 'tracking_id';
        const needsVerify = (v === 'phone' || v === 'email');

        /* ── Search Value column ──
           Tracking ID: दाँया col-md-8 मा field देखाउने + required
           Phone/Email: field लुकाउने, type dropdown नै col-12 बन्छ */
        if (colSearchValue) colSearchValue.style.display = needsVerify ? 'none' : '';
        if (searchValueInp) {
            if (needsVerify) {
                searchValueInp.removeAttribute('required');
                searchValueInp.value = ''; /* clear to avoid stale value */
            } else {
                searchValueInp.setAttribute('required', '');
            }
        }

        /* ── Search Type column width ── */
        if (colSearchType) {
            colSearchType.className = needsVerify ? 'col-12' : 'col-md-4';
        }

        /* ── Tip below dropdown ── */
        if (searchTypeTip) searchTypeTip.style.display = needsVerify ? '' : 'none';
        if (tipPhone) tipPhone.style.display = v === 'phone' ? '' : 'none';
        if (tipEmail) tipEmail.style.display = v === 'email' ? '' : 'none';

        /* ── Verify section ── */
        verifySection.style.display = needsVerify ? '' : 'none';

        if (verifyNote) {
            if (needsVerify) {
                verifyNote.innerHTML = '<i class="fas fa-shield-alt tracker-ico-warn me-1"></i>'
                    + '<?php echo isEnglish() ? "Your phone/email below is used for both search &amp; identity verification." : "तलको फोन/इमेल नै खोज र प्रमाणीकरण दुवैमा प्रयोग हुन्छ।"; ?>';
            } else {
                verifyNote.innerHTML = '<i class="fas fa-lock tracker-ico-ok me-1"></i>'
                    + '<?php echo isEnglish() ? "Tracking ID search — no extra verification needed." : "Tracking ID बाट खोज्दा थप प्रमाणीकरण आवश्यक छैन।"; ?>';
            }
        }

        /* Hint spans — no longer needed for phone/email (field is hidden) */
        if (hintTid)   hintTid.style.display = v === 'tracking_id' ? '' : 'none';
    }

    if (searchType) searchType.addEventListener('change', updateUI);

    /* Run on page load (in case form is re-submitted with phone/email selected) */
    updateUI();
})();
</script>

<?php require_once 'includes/footer.php'; ?>
