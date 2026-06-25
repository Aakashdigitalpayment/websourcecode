<?php
$__t = static function (string $np, string $en): string {
    $lang = (string)($_SESSION['admin_lang'] ?? $_SESSION['lang'] ?? 'np');
    return strtolower($lang) === 'en' ? $en : $np;
};
$pageTitle = $__t('कार्यक्रम उपस्थिति रिपोर्ट', 'Program Attendance Report');
$currentPage = 'program-attendance';
/* CSV export अघि HTML नछापियोस् — नत्र Excel मा पूरै page source “code” जस्तो देखिन्छ */
if (!ob_get_level()) {
    ob_start();
}
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
require_once __DIR__ . '/../includes/program-tables.php';

$db = getDB();
ensureProgramTables($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCSRF();
    $action = $_POST['action'] ?? '';
    if ($action === 'bulk_notify_prereg' || $action === 'bulk_notify_prereg_test') {
        $ids = $_POST['prereg_ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $channel = trim((string)($_POST['notify_channel'] ?? 'both')); // sms|email|both
        $bulkMsg = trim((string)($_POST['bulk_message'] ?? ''));
        if (empty($ids)) {
            setFlash('error', 'पहिले कम्तीमा एक pre-registration सदस्य छान्नुहोस्।');
        } elseif ($bulkMsg === '') {
            setFlash('error', 'Bulk सन्देश खाली राख्न मिल्दैन।');
        } else {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $sel = $db->prepare("SELECT pr.*, COALESCE(NULLIF(m.name,''), pr.member_name) AS display_name, m.email AS member_email, m.phone AS member_phone, m.phone AS member_mobile
                                 FROM member_program_preregistrations pr
                                 LEFT JOIN members m ON m.id = pr.member_id
                                 WHERE pr.id IN ($ph)");
            $sel->execute($ids);
            $targets = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($action === 'bulk_notify_prereg_test' && !empty($targets)) {
                $targets = [reset($targets)];
            }
            if (empty($targets)) {
                setFlash('error', 'छानिएका सदस्य फेला परेनन्।');
            } else {
                $origEmail = getSetting('notify_member_email', '1');
                $origSms = getSetting('notify_member_sms', '0');
                if ($channel === 'email') {
                    updateSetting('notify_member_email', '1');
                    updateSetting('notify_member_sms', '0');
                } elseif ($channel === 'sms') {
                    updateSetting('notify_member_email', '0');
                    updateSetting('notify_member_sms', '1');
                } else {
                    updateSetting('notify_member_email', '1');
                    updateSetting('notify_member_sms', '1');
                }

                $okCount = 0;
                foreach ($targets as $t) {
                    $name = trim((string)($t['display_name'] ?? $t['member_name'] ?? 'Member'));
                    $email = trim((string)($t['member_email'] ?? ''));
                    $phone = trim((string)($t['member_phone'] ?: ($t['member_mobile'] ?? $t['phone'] ?? '')));
                    $extra = "कार्यक्रम: " . (string)($t['program_title'] ?? '');
                    if (!empty($t['event_date'])) $extra .= " | मिति: " . (string)$t['event_date'];
                    $fullComment = $bulkMsg . "\n" . $extra;
                    sendMemberStatusUpdate('digital_service', $email, $phone, $name, 'confirmed', $fullComment, (string)($t['member_card_no'] ?? ''));
                    $okCount++;
                }
                updateSetting('notify_member_email', $origEmail);
                updateSetting('notify_member_sms', $origSms);
                if ($action === 'bulk_notify_prereg_test') {
                    setFlash('success', "Test notification पठाइयो (1 सदस्य, channel: {$channel})।");
                } else {
                    setFlash('success', "Bulk notification पठाइयो (count: {$okCount}, channel: {$channel})।");
                }
            }
        }
    } elseif ($action === 'mark_prereg_attended') {
        $preregId = (int)($_POST['prereg_id'] ?? 0);
        if ($preregId > 0) {
            try {
                $stp = $db->prepare("SELECT * FROM member_program_preregistrations WHERE id=? LIMIT 1");
                $stp->execute([$preregId]);
                $pr = $stp->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($pr) {
                    $chk = $db->prepare("SELECT id FROM member_program_attendance WHERE member_id=? AND program_id=? LIMIT 1");
                    $chk->execute([(int)$pr['member_id'], (int)$pr['program_id']]);
                    if ($chk->fetchColumn()) {
                        setFlash('error', 'यो सदस्यको attendance पहिल्यै register भइसकेको छ।');
                    } else {
                        $ins = $db->prepare("INSERT INTO member_program_attendance
                            (member_id, member_card_no, program_id, program_title, is_priority, attendance_note, verified_by_ip, source)
                            VALUES (?,?,?,?,?,?,?,?)");
                        $ins->execute([
                            (int)$pr['member_id'],
                            (string)($pr['member_card_no'] ?? ''),
                            (int)$pr['program_id'],
                            mb_substr((string)($pr['program_title'] ?? ''), 0, 180),
                            0,
                            'Pre-registration बाट attendance mark',
                            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                            'admin_prereg'
                        ]);
                        setFlash('success', 'Pre-registration बाट attendance mark भयो।');
                    }
                } else {
                    setFlash('error', 'Pre-registration record फेला परेन।');
                }
            } catch (Throwable $e) {
                setFlash('error', 'Attendance mark गर्न समस्या भयो।');
            }
        }
    } elseif ($action === 'approve_attendance_request') {
        $reqId = (int)($_POST['request_id'] ?? 0);
        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        if ($reqId > 0) {
            try {
                $stR = $db->prepare("SELECT * FROM member_program_attendance_requests WHERE id=? AND status='pending' LIMIT 1");
                $stR->execute([$reqId]);
                $req = $stR->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$req) {
                    setFlash('error', 'अनुरोध फेला परेन वा पहिले नै प्रक्रिया भइसकेको छ।');
                } else {
                    $mid = (int)$req['member_id'];
                    $pid = (int)$req['program_id'];
                    if ($mid <= 0) {
                        setFlash('error', 'यो अनुरोध existing member सँग match भएको छैन। पहिले सदस्य registration/link गरेर मात्र attendance approve गर्नुहोस्।');
                    } else {
                    $chk = $db->prepare("SELECT id FROM member_program_attendance WHERE member_id=? AND program_id=? LIMIT 1");
                    $chk->execute([$mid, $pid]);
                    if ($chk->fetchColumn()) {
                        $db->prepare("UPDATE member_program_attendance_requests SET status='approved', processed_at=NOW(), admin_id=? WHERE id=?")
                            ->execute([$adminId ?: null, $reqId]);
                        setFlash('success', 'सदस्य पहिले नै उपस्थिति सूचीमा छ — अनुरोध बन्द गरियो।');
                    } else {
                        $note = 'QR अनुरोध #' . $reqId . ' Admin स्वीकृति';
                        $ins = $db->prepare("INSERT INTO member_program_attendance
                            (member_id, member_card_no, program_id, program_title, is_priority, attendance_note, verified_by_ip, source)
                            VALUES (?,?,?,?,?,?,?,?)");
                        $ins->execute([
                            $mid,
                            (string)($req['member_card_no'] ?? ''),
                            $pid,
                            mb_substr((string)($req['program_title'] ?? ''), 0, 180),
                            0,
                            $note,
                            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                            'admin_request_approve',
                        ]);
                        $db->prepare("UPDATE member_program_attendance_requests SET status='approved', processed_at=NOW(), admin_id=? WHERE id=?")
                            ->execute([$adminId ?: null, $reqId]);
                        setFlash('success', 'उपस्थिति अनुरोध स्वीकृत भयो — सूचीमा थपियो।');
                    }
                    }
                }
            } catch (Throwable $e) {
                setFlash('error', 'स्वीकृति गर्दा समस्या भयो।');
            }
        }
    } elseif ($action === 'link_attendance_request_member') {
        $reqId = (int)($_POST['request_id'] ?? 0);
        $targetMemberId = (int)($_POST['target_member_id'] ?? 0);
        if ($reqId > 0 && $targetMemberId > 0) {
            try {
                $m = $db->prepare("SELECT id, name, sadasyata_number, phone, address FROM members WHERE id=? LIMIT 1");
                $m->execute([$targetMemberId]);
                $member = $m->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$member) {
                    setFlash('error', 'Link गर्ने सदस्य फेला परेन।');
                } else {
                    $u = $db->prepare("UPDATE member_program_attendance_requests
                        SET member_id=?, member_name=?, member_card_no=?, member_phone=COALESCE(NULLIF(member_phone,''), ?), member_address=COALESCE(NULLIF(member_address,''), ?)
                        WHERE id=? AND status='pending'");
                    $u->execute([
                        (int)$member['id'],
                        (string)($member['name'] ?? ''),
                        (string)($member['sadasyata_number'] ?? ''),
                        (string)($member['phone'] ?? ''),
                        (string)($member['address'] ?? ''),
                        $reqId
                    ]);
                    setFlash('success', 'Attendance request सदस्यसँग link भयो। अब approve गर्न सकिन्छ।');
                }
            } catch (Throwable $e) {
                setFlash('error', 'Member link गर्न समस्या भयो।');
            }
        }
    } elseif ($action === 'create_member_from_attendance_request') {
        $reqId = (int)($_POST['request_id'] ?? 0);
        if ($reqId > 0) {
            try {
                $stR = $db->prepare("SELECT * FROM member_program_attendance_requests WHERE id=? AND status='pending' LIMIT 1");
                $stR->execute([$reqId]);
                $req = $stR->fetch(PDO::FETCH_ASSOC) ?: null;
                if (!$req) {
                    setFlash('error', 'अनुरोध फेला परेन।');
                } else {
                    $name = mb_substr(trim((string)($req['member_name'] ?? '')), 0, 200, 'UTF-8');
                    $phone = mb_substr(trim((string)($req['member_phone'] ?? '')), 0, 20, 'UTF-8');
                    $address = mb_substr(trim((string)($req['member_address'] ?? '')), 0, 300, 'UTF-8');
                    $cardNo = mb_substr(trim((string)($req['member_card_no'] ?? '')), 0, 60, 'UTF-8');
                    if ($name === '' || $phone === '') {
                        setFlash('error', 'नयाँ सदस्य बनाउन नाम र फोन आवश्यक छ।');
                    } else {
                        if ($cardNo === '') {
                            $cardNo = 'MEM-' . date('Y') . '-' . str_pad((string)((int)$db->query("SELECT COUNT(*) FROM members")->fetchColumn() + 1), 4, '0', STR_PAD_LEFT);
                        }
                        $insM = $db->prepare("INSERT INTO members (sadasyata_number, name, phone, address, approval_status, is_active, created_at)
                            VALUES (?,?,?,?, 'approved', 1, NOW())");
                        $insM->execute([$cardNo, $name, $phone, $address]);
                        $newMemberId = (int)$db->lastInsertId();
                        $db->prepare("UPDATE member_program_attendance_requests SET member_id=?, member_card_no=? WHERE id=?")
                            ->execute([$newMemberId, $cardNo, $reqId]);
                        setFlash('success', 'नयाँ सदस्य बन्यो र request link भयो। अब approve गर्न सकिन्छ।');
                    }
                }
            } catch (Throwable $e) {
                setFlash('error', 'नयाँ सदस्य बनाउन समस्या भयो: ' . $e->getMessage());
            }
        }
    } elseif ($action === 'reject_attendance_request') {
        $reqId = (int)($_POST['request_id'] ?? 0);
        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        $rejNote = mb_substr(trim((string)($_POST['reject_note'] ?? '')), 0, 500, 'UTF-8');
        if ($reqId > 0) {
            try {
                $u = $db->prepare("UPDATE member_program_attendance_requests SET status='rejected', processed_at=NOW(), admin_id=?, admin_note=? WHERE id=? AND status='pending'");
                $u->execute([$adminId ?: null, $rejNote, $reqId]);
                if ($u->rowCount()) {
                    setFlash('success', 'अनुरोध अस्वीकृत गरियो।');
                } else {
                    setFlash('error', 'अनुरोध फेला परेन वा पहिले नै प्रक्रिया भइसकेको छ।');
                }
            } catch (Throwable $e) {
                setFlash('error', 'अस्वीकृति गर्दा समस्या भयो।');
            }
        }
    }
    $rq = [];
    if (isset($_GET['program_id'])) {
        $rq['program_id'] = (int)($_GET['program_id'] ?? 0);
    }
    if (isset($_GET['q']) && trim((string)$_GET['q']) !== '') {
        $rq['q'] = mb_substr(trim((string)$_GET['q']), 0, 200, 'UTF-8');
    }
    if (isset($_GET['show_done']) && (string)$_GET['show_done'] === '1') {
        $rq['show_done'] = 1;
    }
    $dfP = trim((string)($_GET['date_from'] ?? ''));
    $dtP = trim((string)($_GET['date_to'] ?? ''));
    if ($dfP !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dfP)) {
        $rq['date_from'] = $dfP;
    }
    if ($dtP !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dtP)) {
        $rq['date_to'] = $dtP;
    }
    if (isset($_GET['active_only']) && (string)$_GET['active_only'] === '1') {
        $rq['active_only'] = 1;
    }
    if (isset($_GET['page'])) {
        $pg = max(1, (int)$_GET['page']);
        if ($pg > 1) {
            $rq['page'] = $pg;
        }
    }
    redirect('program-attendance.php' . (!empty($rq) ? ('?' . http_build_query($rq)) : ''));
}

$programId = (int)($_GET['program_id'] ?? 0);
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 200, 'UTF-8');
$showDone = isset($_GET['show_done']) && (string)$_GET['show_done'] === '1';
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}
$activeOnly = isset($_GET['active_only']) && (string)$_GET['active_only'] === '1';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

$whereA = '1=1';
$paramsA = [];
if ($programId > 0) {
    $whereA .= ' AND a.program_id=?';
    $paramsA[] = $programId;
}
if ($q !== '') {
    $whereA .= ' AND (a.member_card_no LIKE ? OR a.program_title LIKE ? OR m.name LIKE ?)';
    $like = "%$q%";
    array_push($paramsA, $like, $like, $like);
}
if ($dateFrom !== '') {
    $whereA .= ' AND DATE(a.attended_at) >= ?';
    $paramsA[] = $dateFrom;
}
if ($dateTo !== '') {
    $whereA .= ' AND DATE(a.attended_at) <= ?';
    $paramsA[] = $dateTo;
}
if ($activeOnly) {
    $whereA .= ' AND p.is_active = 1';
}

$wherePr = '1=1';
$paramsPr = [];
if ($programId > 0) {
    $wherePr .= ' AND pr.program_id=?';
    $paramsPr[] = $programId;
}
if ($q !== '') {
    $wherePr .= ' AND (pr.member_card_no LIKE ? OR pr.program_title LIKE ? OR pr.member_name LIKE ? OR m.name LIKE ?)';
    $like = "%$q%";
    array_push($paramsPr, $like, $like, $like, $like);
}

$joinA = "FROM member_program_attendance a
        LEFT JOIN members m ON m.id = a.member_id
        LEFT JOIN upcoming_programs p ON p.id = a.program_id";

$paQuery = [];
if ($programId > 0) {
    $paQuery['program_id'] = $programId;
}
if ($q !== '') {
    $paQuery['q'] = $q;
}
if ($showDone) {
    $paQuery['show_done'] = 1;
}
if ($dateFrom !== '') {
    $paQuery['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $paQuery['date_to'] = $dateTo;
}
if ($activeOnly) {
    $paQuery['active_only'] = 1;
}

if (isset($_GET['export']) && $_GET['export'] === '1') {
    $sqlAll = "SELECT a.*, m.name AS member_name, m.gender AS gender, p.event_date, p.location
        {$joinA}
        WHERE {$whereA}
        ORDER BY a.attended_at DESC";
    $stAll = $db->prepare($sqlAll);
    $stAll->execute($paramsA);
    $exportRows = $stAll->fetchAll(PDO::FETCH_ASSOC);
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="program-attendance-' . date('Ymd-His') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['Program', 'Event Date', 'Member Name', 'Gender', 'Member Card', 'Priority', 'Note', 'Attended At']);
    foreach ($exportRows as $r) {
        fputcsv($out, [
            (string)($r['program_title'] ?? ''),
            (string)($r['event_date'] ?? ''),
            (string)($r['member_name'] ?? ''),
            (string)($r['gender'] ?? ''),
            (string)($r['member_card_no'] ?? ''),
            ((int)($r['is_priority'] ?? 0) ? 'Yes' : 'No'),
            (string)($r['attendance_note'] ?? ''),
            (string)($r['attended_at'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

$preJoinSql = "FROM member_program_preregistrations pr
               LEFT JOIN members m ON m.id = pr.member_id
               LEFT JOIN upcoming_programs p ON p.id = pr.program_id
               LEFT JOIN member_program_attendance a2 ON a2.member_id = pr.member_id AND a2.program_id = pr.program_id";

if (isset($_GET['export']) && $_GET['export'] === 'prereg') {
    $preExportSql = "SELECT pr.*, COALESCE(NULLIF(m.name,''), pr.member_name) AS display_name, m.phone AS member_phone, m.phone AS member_mobile, m.email AS member_email, p.event_date, p.location,
                      CASE WHEN a2.id IS NULL THEN 0 ELSE 1 END AS is_done
               {$preJoinSql}
               WHERE {$wherePr}
               ORDER BY pr.created_at DESC";
    $pex = $db->prepare($preExportSql);
    $pex->execute($paramsPr);
    $preExportRows = $pex->fetchAll(PDO::FETCH_ASSOC) ?: [];
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="program-preregistration-' . date('Ymd-His') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['Program', 'Event Date', 'Member Name', 'Member ID', 'Phone', 'Email', 'Note', 'Registered At']);
    foreach ($preExportRows as $r) {
        $csvName = (string)($r['display_name'] ?? $r['member_name'] ?? '');
        $phone = (string)($r['member_phone'] ?? $r['member_mobile'] ?? $r['phone'] ?? '');
        fputcsv($out, [
            (string)($r['program_title'] ?? ''),
            (string)($r['event_date'] ?? ''),
            $csvName,
            (string)($r['member_card_no'] ?? ''),
            $phone,
            (string)($r['member_email'] ?? ''),
            (string)($r['note'] ?? ''),
            (string)($r['created_at'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}

$cntSt = $db->prepare("SELECT COUNT(*) {$joinA} WHERE {$whereA}");
$cntSt->execute($paramsA);
$totalFiltered = (int)$cntSt->fetchColumn();
$totalPages = max(1, (int)ceil($totalFiltered / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}

$uniqueSql = "SELECT COUNT(DISTINCT CASE WHEN a.member_id > 0 THEN CONCAT('M', a.member_id) ELSE CONCAT('C', IFNULL(a.member_card_no, '')) END) {$joinA} WHERE {$whereA}";
$ust = $db->prepare($uniqueSql);
$ust->execute($paramsA);
$uniqueMembers = (int)$ust->fetchColumn();

$pstc = $db->prepare("SELECT SUM(a.is_priority) {$joinA} WHERE {$whereA}");
$pstc->execute($paramsA);
$priorityCount = (int)$pstc->fetchColumn();

$pcd = $db->prepare("SELECT COUNT(DISTINCT a.program_id) {$joinA} WHERE {$whereA}");
$pcd->execute($paramsA);
$distinctProgramCount = (int)$pcd->fetchColumn();

$genderCounts = ['male' => 0, 'female' => 0, 'other' => 0, 'unknown' => 0];
$gst = $db->prepare("SELECT LOWER(TRIM(IFNULL(m.gender, ''))) AS g, COUNT(*) AS c {$joinA} WHERE {$whereA} GROUP BY g");
$gst->execute($paramsA);
foreach ($gst->fetchAll(PDO::FETCH_ASSOC) as $gr) {
    $g = (string)($gr['g'] ?? '');
    if ($g === '' || !isset($genderCounts[$g])) {
        $g = 'unknown';
    }
    $genderCounts[$g] += (int)($gr['c'] ?? 0);
}

$programCounts = [];
$pct = $db->prepare("SELECT a.program_title AS pt, COUNT(*) AS cnt {$joinA} WHERE {$whereA} GROUP BY a.program_title ORDER BY cnt DESC LIMIT 8");
$pct->execute($paramsA);
foreach ($pct->fetchAll(PDO::FETCH_ASSOC) as $pr) {
    $programCounts[(string)$pr['pt']] = (int)$pr['cnt'];
}
$topProgramLabels = array_keys($programCounts);
$topProgramData = array_values($programCounts);

$dct = $db->prepare("SELECT DATE(a.attended_at) AS d, COUNT(*) AS c {$joinA} WHERE {$whereA} AND a.attended_at IS NOT NULL GROUP BY DATE(a.attended_at) ORDER BY d ASC");
$dct->execute($paramsA);
$dailyRows = $dct->fetchAll(PDO::FETCH_ASSOC) ?: [];
$dailyCounts = [];
foreach ($dailyRows as $dr) {
    $d = (string)($dr['d'] ?? '');
    if ($d !== '') {
        $dailyCounts[$d] = (int)($dr['c'] ?? 0);
    }
}
$trendLabels = array_slice(array_keys($dailyCounts), -12);
$trendData = array_map(static fn($k) => $dailyCounts[$k], $trendLabels);

$programGender = [];
$pgst = $db->prepare("SELECT a.program_title AS pt, LOWER(TRIM(IFNULL(m.gender, ''))) AS g, COUNT(*) AS c {$joinA} WHERE {$whereA} GROUP BY a.program_title, g ORDER BY a.program_title");
$pgst->execute($paramsA);
foreach ($pgst->fetchAll(PDO::FETCH_ASSOC) as $pgr) {
    $pt = (string)($pgr['pt'] ?? 'Unknown');
    $g = (string)($pgr['g'] ?? '');
    if ($g === '' || !isset($genderCounts[$g])) {
        $g = 'unknown';
    }
    $c = (int)($pgr['c'] ?? 0);
    if (!isset($programGender[$pt])) {
        $programGender[$pt] = ['male' => 0, 'female' => 0, 'other' => 0, 'unknown' => 0, 'total' => 0];
    }
    $programGender[$pt][$g] += $c;
    $programGender[$pt]['total'] += $c;
}

$offset = ($page - 1) * $perPage;
$sql = "SELECT a.*, m.name AS member_name, m.gender AS gender, p.event_date, p.location
        {$joinA}
        WHERE {$whereA}
        ORDER BY a.attended_at DESC
        LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
$st = $db->prepare($sql);
$st->execute($paramsA);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$preRows = [];
$prePendingCount = 0;
$preDoneCount = 0;
try {
    $preAgg = $db->prepare("SELECT
        SUM(CASE WHEN a2.id IS NULL THEN 1 ELSE 0 END) AS pending_c,
        SUM(CASE WHEN a2.id IS NULL THEN 0 ELSE 1 END) AS done_c
        {$preJoinSql}
        WHERE {$wherePr}");
    $preAgg->execute($paramsPr);
    $agg = $preAgg->fetch(PDO::FETCH_ASSOC) ?: [];
    $prePendingCount = (int)($agg['pending_c'] ?? 0);
    $preDoneCount = (int)($agg['done_c'] ?? 0);

    $preSql = "SELECT pr.*, COALESCE(NULLIF(m.name,''), pr.member_name) AS display_name, m.phone AS member_phone, m.phone AS member_mobile, m.email AS member_email, p.event_date, p.location,
                      CASE WHEN a2.id IS NULL THEN 0 ELSE 1 END AS is_done
               {$preJoinSql}
               WHERE {$wherePr}
               ORDER BY pr.created_at DESC
               LIMIT 250";
    $pst = $db->prepare($preSql);
    $pst->execute($paramsPr);
    $preRows = $pst->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('program-attendance prereg: ' . $e->getMessage());
    $preRows = [];
    $prePendingCount = 0;
    $preDoneCount = 0;
}

$whereReq = "r.status='pending'";
$paramsReq = [];
if ($programId > 0) {
    $whereReq .= ' AND r.program_id=?';
    $paramsReq[] = $programId;
}
if ($q !== '') {
    $whereReq .= ' AND (r.member_card_no LIKE ? OR r.program_title LIKE ? OR r.member_name LIKE ? OR m.name LIKE ?)';
    $like = "%$q%";
    array_push($paramsReq, $like, $like, $like, $like);
}

if (isset($_GET['export']) && $_GET['export'] === 'requests') {
    $reqExportSql = "SELECT r.*, COALESCE(NULLIF(m.name,''), r.member_name) AS display_name, m.phone AS matched_phone, p.event_date, p.location
               FROM member_program_attendance_requests r
               LEFT JOIN members m ON m.id=r.member_id
               LEFT JOIN upcoming_programs p ON p.id=r.program_id
               WHERE {$whereReq}
               ORDER BY r.requested_at ASC";
    $rex = $db->prepare($reqExportSql);
    $rex->execute($paramsReq);
    $reqExportRows = $rex->fetchAll(PDO::FETCH_ASSOC) ?: [];
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="program-attendance-requests-' . date('Ymd-His') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, ['Program', 'Event Date', 'Member Name', 'Member ID', 'Phone', 'Address', 'Source', 'IP', 'User Agent', 'Requested At']);
    foreach ($reqExportRows as $r) {
        fputcsv($out, [
            (string)($r['program_title'] ?? ''),
            (string)($r['event_date'] ?? ''),
            (string)($r['display_name'] ?? $r['member_name'] ?? ''),
            (string)($r['member_card_no'] ?? ''),
            (string)($r['matched_phone'] ?? $r['member_phone'] ?? ''),
            (string)($r['member_address'] ?? ''),
            (string)($r['source'] ?? ''),
            (string)($r['verified_by_ip'] ?? ''),
            (string)($r['user_agent'] ?? ''),
            (string)($r['requested_at'] ?? ''),
        ]);
    }
    fclose($out);
    exit;
}
$reqRows = [];
$reqPendingCount = 0;
try {
    $rc = $db->prepare("SELECT COUNT(*) FROM member_program_attendance_requests r
               LEFT JOIN members m ON m.id=r.member_id
               WHERE {$whereReq}");
    $rc->execute($paramsReq);
    $reqPendingCount = (int)$rc->fetchColumn();

    $reqSql = "SELECT r.*, m.name AS mname, m.phone AS mphone, m.phone AS mmobile, p.event_date, p.location
               FROM member_program_attendance_requests r
               LEFT JOIN members m ON m.id=r.member_id
               LEFT JOIN upcoming_programs p ON p.id=r.program_id
               WHERE {$whereReq}
               ORDER BY r.requested_at ASC
               LIMIT 200";
    $rst = $db->prepare($reqSql);
    $rst->execute($paramsReq);
    $reqRows = $rst->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $reqRows = [];
}

$totalAttendance = $totalFiltered;

$programs = $db->query("SELECT id, title, is_active FROM upcoming_programs ORDER BY is_active DESC, COALESCE(event_date,'9999-12-31') ASC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="container-fluid py-3">
<?php echo adminPageHeader($__t('कार्यक्रम उपस्थिति रिपोर्ट', 'Program Attendance Report'), 'fa-clipboard-check', $__t('कार्यक्रम छानेर pre-registration र उपस्थिति हेर्नुहोस्। लामो सूचीको लागि कार्यक्रम व्यवस्थापनमा सक्रिय/निष्क्रिय छुट्याउनुहोस्।', 'Select a program to view pre-registrations and attendance. For long lists, separate active/inactive from program management.'),
    '<a class="btn btn-outline-primary btn-sm" href="programs.php"><i class="fas fa-calendar-plus me-1"></i>' . $__t('कार्यक्रम व्यवस्थापन', 'Program Management') . '</a>'); ?>
<?php if ($f = getFlash()): ?><div class="mb-3"><?php echo adminAlert($f['type'], $f['message']); ?></div><?php endif; ?>

<div class="card admin-table-card mb-3">
  <div class="card-body">
    <form class="row g-2 align-items-end" method="get" action="program-attendance.php">
      <div class="col-md-3"><label class="form-label small mb-1"><?php echo $__t('कार्यक्रम','Program'); ?></label><select name="program_id" class="form-select"><option value="0"><?php echo $__t('सबै कार्यक्रम','All Programs'); ?></option><?php foreach ($programs as $p): ?><?php
          $pTitle = (string)($p['title'] ?? '');
          $pInactive = isset($p['is_active']) && (int)$p['is_active'] !== 1;
          $pLabel = $pTitle . ($pInactive ? ' (निष्क्रिय)' : '');
      ?><option value="<?php echo (int)$p['id']; ?>" <?php echo $programId === (int)$p['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($pLabel); ?></option><?php endforeach; ?></select></div>
      <div class="col-md-3"><label class="form-label small mb-1"><?php echo $__t('खोज (नाम / सदस्य नं. / कार्यक्रम)', 'Search (name / member no. / program)'); ?></label><input name="q" class="form-control" value="<?php echo htmlspecialchars($q); ?>" placeholder="<?php echo $__t('खोज…', 'Search...'); ?>"></div>
      <div class="col-md-2"><label class="form-label small mb-1">देखि</label><input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($dateFrom); ?>"></div>
      <div class="col-md-2"><label class="form-label small mb-1">सम्म</label><input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($dateTo); ?>"></div>
      <div class="col-md-2">
        <label class="form-check-label small d-block mb-1">
          <input class="form-check-input me-1" type="checkbox" name="active_only" value="1" <?php echo $activeOnly ? 'checked' : ''; ?>>
          सक्रिय कार्यक्रम मात्र
        </label>
        <label class="form-check-label small d-block">
          <input class="form-check-input me-1" type="checkbox" id="showDoneToggle" name="show_done" value="1" <?php echo $showDone ? 'checked' : ''; ?>>
          Pre-reg Done पनि
        </label>
      </div>
      <div class="col-12 col-md-10 d-flex flex-wrap gap-2">
        <button type="submit" class="btn btn-primary"><i class="fas fa-search me-1"></i><?php echo $__t('फिल्टर', 'Filter'); ?></button>
        <a href="program-attendance.php?<?php echo htmlspecialchars(http_build_query(array_merge($paQuery, ['export' => 1])), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-success"><i class="fas fa-file-excel me-1"></i>Excel/CSV (<?php echo $__t('सबै फिल्टर', 'all filters'); ?>)</a>
        <a href="program-attendance.php?<?php echo htmlspecialchars(http_build_query(array_merge($paQuery, ['export' => 'prereg'])), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-outline-primary"><i class="fas fa-user-plus me-1"></i>Pre-Reg CSV</a>
        <?php if ($paQuery !== []): ?><a href="program-attendance.php" class="btn btn-outline-secondary">Reset</a><?php endif; ?>
      </div>
    </form>
    <p class="small text-muted mb-0 mt-2"><?php echo $__t('उपस्थिति तालिका', 'Attendance table'); ?> <?php echo (int)$perPage; ?>/<?php echo $__t('पृष्ठ', 'page'); ?>. KPI <?php echo $__t('र चार्ट हालको फिल्टरको', 'and charts are based on'); ?> <strong><?php echo $__t('जम्मा', 'total'); ?></strong> <?php echo $__t('डाटामा आधारित छन्।', 'filtered data.'); ?></p>
  </div>
</div>

<?php
  $statCards = [
    ['icon'=>'fa-chart-bar',      'label'=>$__t('कुल उपस्थिति','Total Attendance'), 'value'=>(int)$totalAttendance,        'color'=>'primary'],
    ['icon'=>'fa-users',          'label'=>'अद्वितीय सदस्य',                        'value'=>(int)$uniqueMembers,          'color'=>'success'],
    ['icon'=>'fa-star',           'label'=>'Priority मार्क',                          'value'=>(int)$priorityCount,          'color'=>'warning'],
    ['icon'=>'fa-calendar-days',  'label'=>$__t('कार्यक्रम संख्या','Program Count'), 'value'=>(int)$distinctProgramCount,   'color'=>'info'],
    ['icon'=>'fa-mars',           'label'=>'पुरुष (Male)',                             'value'=>(int)$genderCounts['male'],   'color'=>'secondary'],
    ['icon'=>'fa-venus',          'label'=>'महिला (Female)',                           'value'=>(int)$genderCounts['female'], 'color'=>'danger'],
    ['icon'=>'fa-circle-question','label'=>'अन्य (Other)',                             'value'=>(int)$genderCounts['other'],  'color'=>'secondary'],
    ['icon'=>'fa-circle-minus',   'label'=>'अनिर्दिष्ट',                              'value'=>(int)$genderCounts['unknown'],'color'=>'secondary'],
  ];
  $statColClass = 'col-6 col-sm-4 col-md-3 col-lg-2';
  include __DIR__ . '/../includes/components/stat-card.php';
?>

<?php if (!empty($topProgramLabels) || !empty($trendLabels)): ?>
<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="card admin-table-card h-100">
      <div class="card-header"><h6 class="mb-0">Program-wise attendance (Top)</h6></div>
      <div class="card-body"><canvas id="paProgramChart" height="140"></canvas></div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card admin-table-card h-100">
      <div class="card-header"><h6 class="mb-0">Attendance trend</h6></div>
      <div class="card-body"><canvas id="paTrendChart" height="140"></canvas></div>
    </div>
  </div>
</div>
<?php endif; ?>

<ul class="nav nav-tabs admin-nav-tabs mb-3" id="paSectionTabs" role="tablist">
  <li class="nav-item" role="presentation">
    <button class="nav-link active" id="pa-tab-req" data-bs-toggle="tab" data-bs-target="#pa-pane-req" type="button" role="tab" aria-controls="pa-pane-req" aria-selected="true">
      <i class="fas fa-hourglass-half me-2"></i>उपस्थिति अनुरोध
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="pa-tab-att" data-bs-toggle="tab" data-bs-target="#pa-pane-att" type="button" role="tab" aria-controls="pa-pane-att" aria-selected="false">
      <i class="fas fa-list me-2"></i>उपस्थिति सूची
    </button>
  </li>
  <li class="nav-item" role="presentation">
    <button class="nav-link" id="pa-tab-prereg" data-bs-toggle="tab" data-bs-target="#pa-pane-prereg" type="button" role="tab" aria-controls="pa-pane-prereg" aria-selected="false">
      <i class="fas fa-user-plus me-2"></i>Pre-registration
    </button>
  </li>
</ul>

<div class="tab-content" id="paSectionTabsContent">
  <div class="tab-pane fade show active" id="pa-pane-req" role="tabpanel" aria-labelledby="pa-tab-req">
<div class="card admin-table-card mb-3 border-warning" style="border-width:2px;">
  <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <h6 class="mb-0"><i class="fas fa-hourglass-half text-warning me-2"></i><?php echo $__t('उपस्थिति अनुरोध (QR / मोबाइल बिना)', 'Attendance Requests (without QR/mobile)'); ?></h6>
    <span class="badge bg-warning text-dark">Pending <?php echo (int)$reqPendingCount; ?></span>
  </div>
  <div class="card-body py-2 small text-muted border-bottom">
    सार्वजनिक <code>attend.php?token=…</code> मा सदस्यले सदस्यता/फोन भरेर पठाएको अनुरोध। स्थलमा उपस्थिति पुष्टि भएपछि <strong>स्वीकृत</strong> गर्नुहोस् — तब मात्र माथिल्लो उपस्थिति सूची र सदस्यको Member Portal इतिहासमा जान्छ।
    <?php if ($reqPendingCount > count($reqRows)): ?>
      <div class="alert alert-info py-1 px-2 mt-2 mb-0 small">Pending जम्मा <?php echo (int)$reqPendingCount; ?> — तालिकामा पहिलो <?php echo count($reqRows); ?> मात्र (छिटो लोड)। बाँकी स्वीकृत गर्दै जाँदा सूची छोटो हुन्छ।</div>
    <?php endif; ?>
    <div class="mt-2">
      <a href="program-attendance.php?<?php echo htmlspecialchars(http_build_query(array_merge($paQuery, ['export' => 'requests'])), ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-warning">
        <i class="fas fa-file-csv me-1"></i>Pending Request CSV
      </a>
    </div>
  </div>
  <div class="table-responsive">
    <table class="table table-hover table-sm align-middle mb-0">
      <thead><tr><th><?php echo $__t('कार्यक्रम','Program'); ?></th><th><?php echo $__t('मिति / स्थान','Date / Location'); ?></th><th><?php echo $__t('सदस्य','Member'); ?></th><th><?php echo $__t('सदस्य नं.','Member No.'); ?></th><th><?php echo $__t('फोन','Phone'); ?></th><th><?php echo $__t('अनुरोध समय','Request Time'); ?></th><th><?php echo $__t('कार्य','Actions'); ?></th></tr></thead>
      <tbody>
      <?php if (empty($reqRows)): ?>
      <tr><td colspan="7" class="text-center text-muted py-3"><?php echo $__t('कुनै pending अनुरोध छैन।', 'No pending requests.'); ?></td></tr>
      <?php endif; ?>
      <?php foreach ($reqRows as $rx): ?>
      <?php
        $rn = trim((string)($rx['mname'] ?: $rx['member_name'] ?: ''));
        $rph = trim((string)($rx['mphone'] ?: ($rx['mmobile'] ?? $rx['member_phone'] ?? '')));
        $raddr = trim((string)($rx['member_address'] ?? ''));
      ?>
      <tr>
        <td><?php echo htmlspecialchars($rx['program_title'] ?? ''); ?></td>
        <td class="small"><?php echo htmlspecialchars(trim(($rx['event_date'] ?? '') . ' ' . ($rx['location'] ?? ''))); ?></td>
        <td><?php echo htmlspecialchars($rn ?: '—'); ?><?php if ($raddr !== ''): ?><div class="small text-muted"><?php echo htmlspecialchars($raddr); ?></div><?php endif; ?></td>
        <td><code><?php echo htmlspecialchars($rx['member_card_no'] ?: '—'); ?></code></td>
        <td><?php echo htmlspecialchars($rph ?: '—'); ?></td>
        <td class="small"><?php echo htmlspecialchars($rx['requested_at'] ?? ''); ?></td>
        <td>
          <div class="d-flex flex-wrap gap-1">
            <form method="POST" class="d-inline" onsubmit="return confirm('<?php echo $__t('यो सदस्यलाई उपस्थिति सूचीमा थप्ने? स्थलमा उपस्थिति पुष्टि भइसकेको हो?', 'Add this member to attendance list? Is physical attendance confirmed?'); ?>');">
              <?php echo csrfField(); ?>
              <input type="hidden" name="action" value="approve_attendance_request">
              <input type="hidden" name="request_id" value="<?php echo (int)$rx['id']; ?>">
              <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-check me-1"></i><?php echo $__t('स्वीकृत','Approve'); ?></button>
            </form>
            <form method="POST" class="d-inline-flex align-items-center gap-1 flex-wrap" onsubmit="return confirm('<?php echo $__t('अनुरोध अस्वीकृत गर्ने?', 'Reject this request?'); ?>');">
              <?php echo csrfField(); ?>
              <input type="hidden" name="action" value="reject_attendance_request">
              <input type="hidden" name="request_id" value="<?php echo (int)$rx['id']; ?>">
              <input type="text" name="reject_note" class="form-control form-control-sm" style="min-width:120px;max-width:180px;" placeholder="कारण (वैकल्पिक)">
              <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fas fa-times me-1"></i><?php echo $__t('अस्वीकृत','Reject'); ?></button>
            </form>
            <?php if ((int)($rx['member_id'] ?? 0) <= 0): ?>
            <form method="POST" class="d-inline-flex align-items-center gap-1 flex-wrap">
              <?php echo csrfField(); ?>
              <input type="hidden" name="action" value="link_attendance_request_member">
              <input type="hidden" name="request_id" value="<?php echo (int)$rx['id']; ?>">
              <input type="number" min="1" name="target_member_id" class="form-control form-control-sm" style="width:92px;" placeholder="Member ID">
              <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fas fa-link me-1"></i>Link</button>
            </form>
            <form method="POST" class="d-inline" onsubmit="return confirm('<?php echo $__t('यस request बाट नयाँ सदस्य बनाउने?', 'Create a new member from this request?'); ?>');">
              <?php echo csrfField(); ?>
              <input type="hidden" name="action" value="create_member_from_attendance_request">
              <input type="hidden" name="request_id" value="<?php echo (int)$rx['id']; ?>">
              <button type="submit" class="btn btn-sm btn-outline-success"><i class="fas fa-user-plus me-1"></i>Create Member</button>
            </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
  </div>

  <div class="tab-pane fade" id="pa-pane-att" role="tabpanel" aria-labelledby="pa-tab-att">
<div class="card admin-table-card">
  <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
    <h6 class="mb-0"><i class="fas fa-list me-2"></i><?php echo $__t('उपस्थिति सूची','Attendance List'); ?></h6>
    <?php if ($totalFiltered > 0): ?><span class="badge bg-secondary"><?php echo (int)$totalFiltered; ?> रेकर्ड (फिल्टर)</span><?php endif; ?>
  </div>
  <div class="table-responsive">
    <table class="table table-hover table-sm align-middle mb-0">
      <thead><tr><th>कार्यक्रम</th><th>मिति</th><th>सदस्य</th><th>सदस्य नं.</th><th>Priority</th><th>नोट</th><th>समय</th></tr></thead>
      <tbody>
      <?php if (empty($rows)): ?><tr><td colspan="7" class="text-center text-muted py-4">उपस्थिति रेकर्ड छैन।</td></tr><?php endif; ?>
      <?php foreach($rows as $r): ?>
      <tr>
        <td><?php echo htmlspecialchars($r['program_title']); ?></td>
        <td><?php echo htmlspecialchars($r['event_date'] ?: '—'); ?></td>
        <td><?php echo htmlspecialchars($r['member_name'] ?: '—'); ?></td>
        <td><?php echo htmlspecialchars($r['member_card_no'] ?: '—'); ?></td>
        <td><?php echo (int)$r['is_priority'] ? '<span class="badge bg-warning text-dark">Priority</span>' : '<span class="text-muted">No</span>'; ?></td>
        <td><?php echo htmlspecialchars($r['attendance_note'] ?: ''); ?></td>
        <td><?php echo htmlspecialchars($r['attended_at']); ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($totalPages > 1): ?>
  <div class="card-footer d-flex flex-wrap align-items-center justify-content-between gap-2 py-2">
    <span class="small text-muted">पृष्ठ <?php echo (int)$page; ?> / <?php echo (int)$totalPages; ?></span>
    <nav class="d-flex flex-wrap gap-1">
      <?php
        $mkPageUrl = static function (int $p) use ($paQuery): string {
            $q = $paQuery;
            if ($p > 1) {
                $q['page'] = $p;
            }
            return 'program-attendance.php?' . http_build_query($q);
        };
      ?>
      <?php if ($page > 1): ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo htmlspecialchars($mkPageUrl($page - 1), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $__t('अघिल्लो','Previous'); ?></a><?php endif; ?>
      <?php if ($page < $totalPages): ?><a class="btn btn-sm btn-outline-secondary" href="<?php echo htmlspecialchars($mkPageUrl($page + 1), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $__t('अर्को','Next'); ?></a><?php endif; ?>
    </nav>
  </div>
  <?php endif; ?>
</div>
  </div>

  <div class="tab-pane fade" id="pa-pane-prereg" role="tabpanel" aria-labelledby="pa-tab-prereg">
<div class="card admin-table-card mt-3">
  <div class="card-header d-flex align-items-center justify-content-between">
    <h6 class="mb-0">Pre-registration सूची</h6>
    <div class="d-flex gap-2">
      <span class="badge bg-warning text-dark">Pending <?php echo (int)$prePendingCount; ?></span>
      <span class="badge bg-success">Done <?php echo (int)$preDoneCount; ?></span>
      <span class="badge bg-primary"><?php echo (int)($prePendingCount + $preDoneCount); ?> कुल</span>
      <span class="badge bg-secondary">तालिका <?php echo count($preRows); ?><?php echo count($preRows) >= 250 ? '+' : ''; ?></span>
    </div>
  </div>
  <div class="card-body border-bottom py-2">
    <form method="POST" id="bulkNotifyForm" class="needs-validation row g-2 align-items-end" novalidate>
      <?php echo csrfField(); ?>
      <input type="hidden" name="action" value="bulk_notify_prereg">
      <div class="col-md-2">
        <label class="form-label small mb-1">Channel</label>
        <select name="notify_channel" class="form-select form-select-sm">
          <option value="both">SMS + Email</option>
          <option value="sms">SMS Only</option>
          <option value="email">Email Only</option>
        </select>
      </div>
      <div class="col-md-7">
        <label class="form-label small mb-1">Bulk message</label>
        <input type="text" name="bulk_message" class="form-control form-control-sm" placeholder="उदाहरण: कार्यक्रम सुरु हुनुभन्दा ३० मिनेट अगाडि उपस्थित हुनुस्।" required>
      </div>
      <div class="col-md-3 d-grid gap-1">
        <button type="submit" name="action" value="bulk_notify_prereg_test" class="btn btn-sm btn-outline-secondary"><i class="fas fa-vial-circle-check me-1"></i>Test Send</button>
        <button class="btn btn-sm btn-primary"><i class="fas fa-paper-plane me-1"></i>Selected लाई पठाउनुहोस्</button>
      </div>
      <div class="col-12">
        <div class="small text-muted">तल list बाट सदस्यहरू select गरेर bulk सन्देश पठाउनुहोस्। SMS gateway / email settings admin notification settings बाट controlled हुन्छ।</div>
      </div>
    </form>
  </div>
  <div class="table-responsive">
    <table class="table table-hover table-sm align-middle mb-0">
      <thead><tr><th style="width:34px;"><input type="checkbox" id="preRegSelectAll"></th><th>कार्यक्रम</th><th>सदस्य</th><th>सदस्य नं.</th><th>फोन</th><th>Status</th><th>Contact</th><th>नोट</th><th>समय</th><th>Action</th></tr></thead>
      <tbody>
      <?php
        $visiblePreRows = [];
        foreach ($preRows as $prr) {
          if (!$showDone && !empty($prr['is_done'])) continue; /* auto-hide done by default */
          $visiblePreRows[] = $prr;
        }
      ?>
      <?php if (empty($visiblePreRows)): ?><tr><td colspan="10" class="text-center text-muted py-4">Pre-registration रेकर्ड छैन।</td></tr><?php endif; ?>
      <?php foreach($visiblePreRows as $r): ?>
      <?php
        $prName = $r['display_name'] ?: '—';
        $prPhone = $r['member_phone'] ?: ($r['member_mobile'] ?? $r['phone'] ?? '');
        $prEmail = $r['member_email'] ?? '';
        $isDone = !empty($r['is_done']);
      ?>
      <tr>
        <td><input type="checkbox" class="preRegSelectItem" name="prereg_ids[]" value="<?php echo (int)$r['id']; ?>" form="bulkNotifyForm"></td>
        <td><?php echo htmlspecialchars($r['program_title']); ?> <span class="text-muted small"><?php echo htmlspecialchars($r['event_date'] ?: ''); ?></span></td>
        <td><?php echo htmlspecialchars($prName); ?></td>
        <td><code><?php echo htmlspecialchars($r['member_card_no'] ?: '—'); ?></code></td>
        <td><?php echo htmlspecialchars($prPhone ?: '—'); ?></td>
        <td><?php echo $isDone ? '<span class="badge bg-success">Done</span>' : '<span class="badge bg-warning text-dark">Pending</span>'; ?></td>
        <td>
          <?php if ($prPhone): ?><a class="btn btn-sm btn-outline-success" href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $prPhone)); ?>"><i class="fas fa-phone"></i></a><?php endif; ?>
          <?php if ($prEmail): ?><a class="btn btn-sm btn-outline-primary" href="mailto:<?php echo htmlspecialchars($prEmail); ?>"><i class="fas fa-envelope"></i></a><?php endif; ?>
        </td>
        <td><?php echo htmlspecialchars($r['note'] ?: ''); ?></td>
        <td><?php echo htmlspecialchars($r['created_at']); ?></td>
        <td>
          <?php if ($isDone): ?>
            <span class="text-success small fw-bold"><i class="fas fa-check-circle me-1"></i>Marked</span>
          <?php else: ?>
            <form method="POST" class="d-inline" onsubmit="return confirm('यो सदस्यलाई attendance मा mark गर्ने?');">
              <?php echo csrfField(); ?>
              <input type="hidden" name="action" value="mark_prereg_attended">
              <input type="hidden" name="prereg_id" value="<?php echo (int)$r['id']; ?>">
              <button class="btn btn-sm btn-primary"><i class="fas fa-user-check me-1"></i>Mark Attended</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (!empty($programGender)): ?>
<div class="card admin-table-card mt-3">
  <div class="card-header"><h6 class="mb-0">Program-wise Male/Female/Total</h6></div>
  <div class="table-responsive">
    <table class="table table-hover table-sm align-middle mb-0">
      <thead><tr><th>कार्यक्रम</th><th>Total</th><th>Male</th><th>Female</th><th>Other</th><th>Unknown</th></tr></thead>
      <tbody>
      <?php foreach ($programGender as $pt => $gc): ?>
        <tr>
          <td><?php echo htmlspecialchars($pt); ?></td>
          <td><strong><?php echo (int)$gc['total']; ?></strong></td>
          <td><?php echo (int)$gc['male']; ?></td>
          <td><?php echo (int)$gc['female']; ?></td>
          <td><?php echo (int)$gc['other']; ?></td>
          <td><?php echo (int)$gc['unknown']; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>
  </div>
</div>

<?php if (!empty($topProgramLabels) || !empty($trendLabels)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
  var topLabels = <?php echo json_encode(array_values($topProgramLabels), JSON_UNESCAPED_UNICODE); ?>;
  var topData = <?php echo json_encode(array_values($topProgramData)); ?>;
  var trendLabels = <?php echo json_encode(array_values($trendLabels), JSON_UNESCAPED_UNICODE); ?>;
  var trendData = <?php echo json_encode(array_values($trendData)); ?>;

  var pctx = document.getElementById('paProgramChart');
  if (pctx && topLabels.length) {
    new Chart(pctx, {
      type: 'bar',
      data: { labels: topLabels, datasets: [{ label: 'Attendance', data: topData, backgroundColor: 'rgba(26,95,42,0.75)', borderRadius: 6 }] },
      options: { responsive: true, plugins:{legend:{display:false}}, scales:{ y:{beginAtZero:true, ticks:{precision:0}} } }
    });
  }
  var tctx = document.getElementById('paTrendChart');
  if (tctx && trendLabels.length) {
    new Chart(tctx, {
      type: 'line',
      data: { labels: trendLabels, datasets: [{ label:'Daily', data: trendData, borderColor:'var(--secondary-color,#c0392b)', backgroundColor:'rgba(192,57,43,0.12)', fill:true, tension:0.3 }] },
      options: { responsive:true, plugins:{legend:{display:false}}, scales:{ y:{beginAtZero:true, ticks:{precision:0}} } }
    });
  }
})();
</script>
<?php endif; ?>

<script>
(function () {
  var key = 'programAttendanceShowDone';
  var cb = document.getElementById('showDoneToggle');
  if (!cb) return;
  try {
    var saved = localStorage.getItem(key);
    if (saved === '1' && !cb.checked) {
      cb.checked = true;
      var form = cb.closest('form');
      if (form) form.submit();
    }
  } catch (e) {}
  cb.addEventListener('change', function () {
    try { localStorage.setItem(key, cb.checked ? '1' : '0'); } catch (e) {}
  });
})();
</script>
<script>
(function(){
  var all = document.getElementById('preRegSelectAll');
  if (!all) return;
  all.addEventListener('change', function(){
    document.querySelectorAll('.preRegSelectItem').forEach(function(cb){ cb.checked = all.checked; });
  });
})();
</script>

<?php require_once 'includes/admin-footer.php'; ?>
