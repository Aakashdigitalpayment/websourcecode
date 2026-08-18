<?php
/**
 * Member Portal — उत्पादन / सीप सूची (बजार)
 */
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../includes/member-marketplace-tables.php';
requireMemberLogin();
memberSecurityHeaders();

$db = getDB();
$mem = currentMember();
if (!$mem) {
    header('Location: login.php?msg=session_expired');
    exit;
}
$_t = static function (string $np, string $en): string {
    return isEnglish() ? $en : $np;
};

$memberId = (int) $mem['id'];
$memName = trim((string) ($mem['name'] ?? ''));
$memPhone = preg_replace('/[^0-9]/', '', (string) ($mem['phone'] ?? ''));
require __DIR__ . '/../includes/member-portal-identity.php';
$rPhone = $memPhone ?: preg_replace('/[^0-9]/', '', (string) ($kycRow['mobile'] ?? $kycRow['phone'] ?? ''));
$rName = $memName !== '' ? $memName : trim((string) ($kycRow['full_name'] ?? ''));
$rAddress = trim((string) ($kycRow['temporary_address'] ?? $kycRow['permanent_address'] ?? ''));

ensureMemberMarketplaceTables($db);
mpExpireStaleListings($db);

$successMsg = '';
$errorMsg = '';
if (!empty($_SESSION['mmp_flash']) && is_array($_SESSION['mmp_flash'])) {
    $mmpFlash = $_SESSION['mmp_flash'];
    unset($_SESSION['mmp_flash']);
    if (($mmpFlash['type'] ?? '') === 'success') {
        $successMsg = (string) ($mmpFlash['msg'] ?? '');
    } else {
        $errorMsg = (string) ($mmpFlash['msg'] ?? '');
    }
}
$editRow = null;
$editId = (int) ($_GET['edit'] ?? 0);

$productCats = mpProductCategories();
$skillCats = mpSkillCategories();

$loadMine = static function () use ($db, $memberId): array {
    try {
        $st = $db->prepare(
            'SELECT * FROM member_marketplace_listings WHERE member_id = ? ORDER BY created_at DESC LIMIT 80'
        );
        $st->execute([$memberId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
};

if ($editId > 0) {
    try {
        $st = $db->prepare('SELECT * FROM member_marketplace_listings WHERE id = ? AND member_id = ? LIMIT 1');
        $st->execute([$editId, $memberId]);
        $editRow = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$editRow) {
            $errorMsg = $_t('सूची भेटिएन।', 'Listing not found.');
            $editId = 0;
        }
    } catch (Throwable $e) {
        $editRow = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!verifyCSRFToken()) {
        $errorMsg = $_t('सुरक्षा जाँच असफल।', 'Security check failed.');
    } elseif ($action === 'withdraw') {
        $wid = (int) ($_POST['id'] ?? 0);
        try {
            $st = $db->prepare(
                "UPDATE member_marketplace_listings SET status='withdrawn'
                 WHERE id=? AND member_id=? AND status IN ('pending','approved')"
            );
            $st->execute([$wid, $memberId]);
            if ($st->rowCount() > 0) {
                $_SESSION['mmp_flash'] = ['type' => 'success', 'msg' => $_t('सूची सार्वजनिकबाट हटाइयो।', 'Listing withdrawn from public view.')];
            } else {
                $_SESSION['mmp_flash'] = ['type' => 'error', 'msg' => $_t('सूची फिर्ता गर्न सकिएन।', 'Could not withdraw.')];
            }
            header('Location: marketplace.php');
            exit;
        } catch (Throwable $e) {
            $_SESSION['mmp_flash'] = ['type' => 'error', 'msg' => $_t('फिर्ता गर्न सकिएन।', 'Could not withdraw.')];
            header('Location: marketplace.php');
            exit;
        }
    } elseif ($action === 'save') {
        if (!checkRateLimit('mp_save_' . $memberId, 12, 3600)) {
            $errorMsg = $_t('धेरै अनुरोध भए। केही समयपछि प्रयास गर्नुहोस्।', 'Too many requests. Please try later.');
        } else {
            $id = (int) ($_POST['id'] ?? 0);
            $listingType = (($_POST['listing_type'] ?? '') === 'skill') ? 'skill' : 'product';
            $cats = $listingType === 'skill' ? $skillCats : $productCats;
            $category = trim((string) ($_POST['category'] ?? ''));
            if (!isset($cats[$category])) {
                $category = 'other';
            }
            $title = clean_text($_POST['title'] ?? '', 200);
            $description = clean_text($_POST['description'] ?? '', 2000);
            $unit = clean_text($_POST['unit'] ?? '', 40);
            $priceRaw = trim((string) ($_POST['price'] ?? ''));
            $price = $priceRaw === '' ? null : max(0, (float) $priceRaw);
            $priceNote = clean_text($_POST['price_note'] ?? '', 120);
            $quantity = clean_text($_POST['quantity'] ?? '', 80);
            $expRaw = trim((string) ($_POST['experience_years'] ?? ''));
            $experience = $expRaw === '' ? null : max(0, min(60, (int) $expRaw));
            $location = clean_text($_POST['location'] ?? '', 200);
            $contactName = clean_text($_POST['contact_name'] ?? '', 120) ?: $rName;
            $contactPhone = mpPhoneDigits((string) ($_POST['contact_phone'] ?? ''));
            $from = mpNormalizeDate((string) ($_POST['available_from'] ?? ''));
            $untilDate = mpNormalizeDate((string) ($_POST['available_until'] ?? ''));
            $untilTime = mpNormalizeTime((string) ($_POST['available_until_time'] ?? ''));
            $timeFrom = mpNormalizeTime((string) ($_POST['available_time_from'] ?? ''));
            $timeTo = mpNormalizeTime((string) ($_POST['available_time_to'] ?? ''));

            if ($untilDate === '') {
                $untilDate = date('Y-m-d', strtotime('+' . mpDefaultUntilDays() . ' days'));
            }
            $until = mpCombineUntil($untilDate, $untilTime);

            $existing = null;
            if ($id > 0) {
                try {
                    $st = $db->prepare('SELECT * FROM member_marketplace_listings WHERE id=? AND member_id=? LIMIT 1');
                    $st->execute([$id, $memberId]);
                    $existing = $st->fetch(PDO::FETCH_ASSOC) ?: null;
                } catch (Throwable $e) {
                    $existing = null;
                }
                if (!$existing) {
                    $errorMsg = $_t('सूची भेटिएन।', 'Listing not found.');
                }
            }

            if ($errorMsg === '') {
                if ($title === '' || mb_strlen($title) < 3) {
                    $errorMsg = $_t('शीर्षक कम्तीमा ३ अक्षरको हुनुपर्छ।', 'Title must be at least 3 characters.');
                } elseif (!preg_match('/^9\d{9}$/', $contactPhone)) {
                    $errorMsg = $_t('सही १० अङ्कको मोबाइल (९XXXXXXXXX) लेख्नुहोस्।', 'Enter a valid 10-digit mobile (9XXXXXXXXX).');
                } elseif ($from !== '' && $until !== null && strtotime($from) > strtotime(substr((string) $until, 0, 10))) {
                    $errorMsg = $_t('समाप्त मिति सुरु मितिभन्दा अगाडि हुन सक्दैन।', 'End date cannot be before start date.');
                }
            }

            $image = $existing['image'] ?? '';
            if ($errorMsg === '' && !empty($_FILES['image']['name']) && (int) ($_FILES['image']['error'] ?? 0) === UPLOAD_ERR_OK) {
                $imgExt = strtolower(pathinfo((string) $_FILES['image']['name'], PATHINFO_EXTENSION));
                if (!in_array($imgExt, ALLOWED_IMAGE_EXTENSIONS, true)) {
                    $errorMsg = $_t('तस्बिर JPG/PNG/WebP मात्र।', 'Images must be JPG, PNG or WebP.');
                } else {
                    $up = uploadFile($_FILES['image'], 'marketplace');
                    if (!empty($up['success'])) {
                        $newImage = (string) $up['path'];
                        if ($image !== '' && $image !== $newImage && function_exists('deleteFile')) {
                            try { deleteFile($image); } catch (Throwable $e) { /* ignore */ }
                        }
                        $image = $newImage;
                    } else {
                        $errorMsg = $_t('तस्बिर अपलोड असफल। JPG/PNG मात्र, सानो फाइल राख्नुहोस्।', 'Image upload failed. Use a small JPG/PNG.');
                    }
                }
            }

            if ($errorMsg === '') {
                try {
                    $countSt = $db->prepare(
                        "SELECT COUNT(*) FROM member_marketplace_listings
                         WHERE member_id=? AND status IN ('pending','approved')"
                    );
                    $countSt->execute([$memberId]);
                    $liveCount = (int) $countSt->fetchColumn();
                    if ($id < 1 && $liveCount >= 20) {
                        $errorMsg = $_t('एक पटकमा बढीमा २० वटा सक्रिय सूची राख्न सकिन्छ।', 'You can have at most 20 active listings.');
                    } else {
                        $needsReview = true;
                        $status = 'pending';
                        if ($existing && in_array((string) $existing['status'], ['pending', 'approved', 'rejected'], true)) {
                            $status = 'pending';
                        }
                        if ($id > 0 && $existing) {
                            $sql = "UPDATE member_marketplace_listings SET
                                listing_type=?, category=?, title=?, description=?, unit=?, price=?, price_note=?,
                                quantity=?, experience_years=?, location=?, contact_name=?, contact_phone=?, image=?,
                                available_from=?, available_until=?, available_time_from=?, available_time_to=?,
                                status=?, admin_note='', approved_at=NULL, approved_by=NULL
                                WHERE id=? AND member_id=?";
                            $db->prepare($sql)->execute([
                                $listingType, $category, $title, $description, $unit, $price, $priceNote,
                                $quantity, $experience, $location, $contactName, $contactPhone, $image,
                                $from !== '' ? $from : null, $until, $timeFrom !== '' ? $timeFrom : null, $timeTo !== '' ? $timeTo : null,
                                $status, $id, $memberId,
                            ]);
                            $_SESSION['mmp_flash'] = ['type' => 'success', 'msg' => $_t('सूची अपडेट भयो। प्रशासनले फेरि स्वीकृत गरेपछि सार्वजनिक हुन्छ।', 'Listing updated. It will be public again after admin approval.')];
                        } else {
                            $sql = "INSERT INTO member_marketplace_listings
                                (member_id, listing_type, category, title, description, unit, price, price_note,
                                 quantity, experience_years, location, contact_name, contact_phone, image,
                                 available_from, available_until, available_time_from, available_time_to, status)
                                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)";
                            $db->prepare($sql)->execute([
                                $memberId, $listingType, $category, $title, $description, $unit, $price, $priceNote,
                                $quantity, $experience, $location, $contactName, $contactPhone, $image,
                                $from !== '' ? $from : null, $until, $timeFrom !== '' ? $timeFrom : null, $timeTo !== '' ? $timeTo : null,
                                'pending',
                            ]);
                            $_SESSION['mmp_flash'] = ['type' => 'success', 'msg' => $_t('सूची पेश भयो। प्रशासनले स्वीकृत गरेपछि सार्वजनिक बजारमा देखिन्छ।', 'Listing submitted. It will appear publicly after admin approval.')];
                        }
                        if ($needsReview && function_exists('sendAdminNotification')) {
                            try {
                                require_once __DIR__ . '/../includes/notifications.php';
                                sendAdminNotification('member_marketplace', [
                                    'नाम' => $contactName,
                                    'शीर्षक' => $title,
                                    'प्रकार' => mpTypeLabel($listingType),
                                    'फोन' => $contactPhone,
                                ]);
                            } catch (Throwable $e) { /* ignore */ }
                        }
                        header('Location: marketplace.php');
                        exit;
                    }
                } catch (Throwable $e) {
                    $errorMsg = $_t('सुरक्षित गर्न सकिएन।', 'Could not save.');
                }
            }
        }
    }
}

$mine = $loadMine();
$inquiries = [];
try {
    $ids = array_map(static fn($r) => (int) $r['id'], $mine);
    if ($ids !== []) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $iq = $db->prepare(
            "SELECT i.*, l.title, l.listing_type
             FROM member_marketplace_inquiries i
             JOIN member_marketplace_listings l ON l.id = i.listing_id
             WHERE i.listing_id IN ($ph)
             ORDER BY i.id DESC LIMIT 40"
        );
        $iq->execute($ids);
        $inquiries = $iq->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    $inquiries = [];
}

$showForm = isset($_GET['new']) || $editRow || $mine === [];
$formType = $editRow['listing_type'] ?? ((($_GET['type'] ?? '') === 'skill') ? 'skill' : 'product');
$csrfField = '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCSRFToken()) . '">';

$pageTitle = $_t('सदस्य बजार / सीप', 'Marketplace / skills') . ' — ' . (function_exists('getSetting') ? getSetting('site_name', 'सहकारी') : 'सहकारी');
$extraHead = '<style>
.mmp-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:16px}
.mmp-title{margin:0;color:var(--primary-color);font-size:1.35rem}
.mmp-lead{margin:6px 0 0;color:var(--text-light);font-size:.88rem;max-width:42rem}
.mmp-grid{display:grid;gap:12px}
.mmp-item{background:#fff;border:1px solid color-mix(in srgb,var(--primary-color) 14%,#e5e7eb);border-radius:12px;padding:14px;display:grid;grid-template-columns:88px 1fr auto;gap:12px;align-items:start}
@media(max-width:640px){.mmp-item{grid-template-columns:1fr}}
.mmp-thumb{width:88px;height:88px;border-radius:10px;object-fit:cover;background:color-mix(in srgb,var(--primary-color) 10%,#f8fafc);display:flex;align-items:center;justify-content:center;color:var(--primary-color);font-size:1.6rem}
.mmp-thumb img{width:100%;height:100%;object-fit:cover;border-radius:10px}
.mmp-pill{display:inline-flex;align-items:center;gap:4px;font-size:.72rem;font-weight:700;padding:3px 8px;border-radius:999px}
.mmp-form .form-group{margin-bottom:12px}
.mmp-form label{display:block;font-weight:600;font-size:.86rem;margin-bottom:4px}
.mmp-form .form-control,.mmp-form select,.mmp-form textarea{width:100%;padding:10px 12px;border-radius:10px;border:1.5px solid color-mix(in srgb,var(--primary-color) 20%,#d1d5db);font-family:inherit}
.mmp-type-tabs{display:flex;gap:8px;margin-bottom:14px}
.mmp-type-tabs label{flex:1;border:1.5px solid color-mix(in srgb,var(--primary-color) 20%,#d1d5db);border-radius:10px;padding:10px;text-align:center;cursor:pointer;font-weight:700;font-size:.85rem}
.mmp-type-tabs input{display:none}
.mmp-type-tabs input:checked+span{color:var(--primary-color)}
.mmp-type-tabs label:has(input:checked){border-color:var(--primary-color);background:color-mix(in srgb,var(--primary-color) 10%,#fff)}
.mmp-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media(max-width:540px){.mmp-row{grid-template-columns:1fr}}
.mmp-hint{font-size:.78rem;color:var(--text-light);margin-top:4px}
.mmp-actions{display:flex;gap:6px;flex-wrap:wrap;justify-content:flex-end}
</style>';
require __DIR__ . '/includes/chrome.php';

$f = static function (string $key, string $fallback = '') use ($editRow): string {
    if (!$editRow) {
        return $fallback;
    }
    return (string) ($editRow[$key] ?? $fallback);
};
$untilDateVal = '';
$untilTimeVal = '';
if ($editRow && !empty($editRow['available_until'])) {
    $untilDateVal = substr((string) $editRow['available_until'], 0, 10);
    $untilTimeVal = substr((string) $editRow['available_until'], 11, 5);
}
?>

<div class="mmp-head">
    <div>
        <h1 class="mmp-title"><i class="fas fa-store"></i> <?php echo $_t('सदस्य बजार र सीप', 'Member marketplace & skills'); ?></h1>
        <p class="mmp-lead"><?php echo $_t(
            'आफूले फलाएको उत्पादन (काँक्रो, तरकारी, फलफूल…) वा दिन सक्ने सीप (प्लम्बर, विद्युत, ब्युटीसियन…) राख्नुहोस्। प्रशासनले स्वीकृत गरेपछि मात्र सार्वजनिक मेनुमा देखिन्छ। मूल्य/उपलब्ध समय सकिएपछि सूची हट्छ।',
            'List produce you grow (cucumber, vegetables, fruit…) or skills you offer (plumber, electrician, beautician…). They appear in the public menu only after admin approval, and leave after the price/availability window ends.'
        ); ?></p>
    </div>
    <div class="mmp-actions">
        <a class="btn btn-outline-success btn-sm" href="<?php echo SITE_URL; ?>member-marketplace.php" target="_blank" rel="noopener"><?php echo $_t('सार्वजनिक बजार', 'Public market'); ?></a>
        <a class="btn btn-outline-success btn-sm" href="<?php echo SITE_URL; ?>member-skills.php" target="_blank" rel="noopener"><?php echo $_t('सीप सूची', 'Skill list'); ?></a>
        <?php if (!$showForm || $editRow): ?>
        <a class="btn btn-success btn-sm" href="marketplace.php?new=1"><?php echo $_t('+ नयाँ सूची', '+ New listing'); ?></a>
        <?php endif; ?>
    </div>
</div>

<?php if ($successMsg): ?><div class="mem-alert mem-alert-success"><i class="fas fa-circle-check"></i> <?php echo htmlspecialchars($successMsg); ?></div><?php endif; ?>
<?php if ($errorMsg): ?><div class="mem-alert mem-alert-error"><i class="fas fa-circle-xmark"></i> <?php echo htmlspecialchars($errorMsg); ?></div><?php endif; ?>

<?php if ($showForm): ?>
<div class="mem-card" style="margin-bottom:18px;">
    <div class="mem-card-header">
        <div class="mem-card-title"><i class="fas fa-pen"></i> <?php echo $editRow ? $_t('सूची सम्पादन', 'Edit listing') : $_t('नयाँ सूची', 'New listing'); ?></div>
    </div>
    <div class="mem-card-body">
        <form method="post" enctype="multipart/form-data" class="mmp-form">
            <?php echo $csrfField; ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?php echo (int) ($editRow['id'] ?? 0); ?>">

            <div class="mmp-type-tabs" role="radiogroup" aria-label="<?php echo $_t('सूची प्रकार', 'Listing type'); ?>">
                <label>
                    <input type="radio" name="listing_type" value="product" <?php echo $formType !== 'skill' ? 'checked' : ''; ?> onchange="mmpToggleType()">
                    <span><i class="fas fa-basket-shopping"></i> <?php echo $_t('उत्पादन बिक्री', 'Sell produce'); ?></span>
                </label>
                <label>
                    <input type="radio" name="listing_type" value="skill" <?php echo $formType === 'skill' ? 'checked' : ''; ?> onchange="mmpToggleType()">
                    <span><i class="fas fa-screwdriver-wrench"></i> <?php echo $_t('सीप / कामदार', 'Skill / worker'); ?></span>
                </label>
            </div>

            <div class="mmp-row">
                <div class="form-group">
                    <label for="mpCat"><?php echo $_t('वर्ग', 'Category'); ?></label>
                    <select class="form-control" id="mpCat" name="category" required>
                        <?php
                        $selCat = $f('category');
                        foreach ($productCats as $ck => $cv):
                        ?>
                        <option class="mmp-opt-product" value="<?php echo htmlspecialchars($ck); ?>" <?php echo $selCat === $ck ? 'selected' : ''; ?>><?php echo htmlspecialchars(isEnglish() ? $cv['en'] : $cv['np']); ?></option>
                        <?php endforeach; ?>
                        <?php foreach ($skillCats as $ck => $cv): ?>
                        <option class="mmp-opt-skill" value="<?php echo htmlspecialchars($ck); ?>" <?php echo $selCat === $ck ? 'selected' : ''; ?>><?php echo htmlspecialchars(isEnglish() ? $cv['en'] : $cv['np']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="mpTitle"><?php echo $_t('शीर्षक', 'Title'); ?></label>
                    <input class="form-control" id="mpTitle" name="title" required maxlength="200" value="<?php echo htmlspecialchars($f('title')); ?>" placeholder="<?php echo $_t('जस्तै: ताजा काँक्रो / घर प्लम्बिङ', 'e.g. Fresh cucumber / home plumbing'); ?>">
                </div>
            </div>

            <div class="form-group">
                <label for="mpDesc"><?php echo $_t('विवरण', 'Description'); ?></label>
                <textarea class="form-control" id="mpDesc" name="description" rows="3" maxlength="2000"><?php echo htmlspecialchars($f('description')); ?></textarea>
            </div>

            <div class="mmp-row">
                <div class="form-group">
                    <label for="mpPrice"><?php echo $_t('मूल्य (रु.)', 'Price (Rs.)'); ?></label>
                    <input class="form-control" id="mpPrice" name="price" type="number" min="0" step="1" value="<?php echo htmlspecialchars($editRow && $editRow['price'] !== null ? (string) (int) $editRow['price'] : ''); ?>">
                </div>
                <div class="form-group">
                    <label for="mpPriceNote"><?php echo $_t('मूल्य नोट / इकाई', 'Price note / unit'); ?></label>
                    <input class="form-control" id="mpPriceNote" name="price_note" maxlength="120" value="<?php echo htmlspecialchars($f('price_note')); ?>" placeholder="<?php echo $_t('प्रति के.जी. / प्रति दिन / सम्झौता', 'per kg / per day / negotiable'); ?>">
                    <input type="hidden" name="unit" value="<?php echo htmlspecialchars($f('unit')); ?>">
                </div>
            </div>

            <div class="mmp-row mmp-product-only">
                <div class="form-group">
                    <label for="mpQty"><?php echo $_t('उपलब्ध परिमाण', 'Available quantity'); ?></label>
                    <input class="form-control" id="mpQty" name="quantity" maxlength="80" value="<?php echo htmlspecialchars($f('quantity')); ?>" placeholder="<?php echo $_t('जस्तै: ५० के.जी.', 'e.g. 50 kg'); ?>">
                </div>
            </div>
            <div class="mmp-row mmp-skill-only">
                <div class="form-group">
                    <label for="mpExp"><?php echo $_t('अनुभव (वर्ष)', 'Experience (years)'); ?></label>
                    <input class="form-control" id="mpExp" name="experience_years" type="number" min="0" max="60" value="<?php echo htmlspecialchars($editRow && $editRow['experience_years'] !== null ? (string) (int) $editRow['experience_years'] : ''); ?>">
                </div>
                <div class="form-group">
                    <label for="mpTf"><?php echo $_t('दैनिक उपलब्ध समय', 'Daily available hours'); ?></label>
                    <div class="mmp-row">
                        <input class="form-control" id="mpTf" type="time" name="available_time_from" value="<?php echo htmlspecialchars(substr($f('available_time_from'), 0, 5)); ?>">
                        <input class="form-control" type="time" name="available_time_to" value="<?php echo htmlspecialchars(substr($f('available_time_to'), 0, 5)); ?>">
                    </div>
                </div>
            </div>

            <div class="mmp-row">
                <div class="form-group">
                    <label for="mpFrom"><?php echo $_t('उपलब्ध मिति (सुरू)', 'Available from'); ?></label>
                    <input class="form-control" id="mpFrom" type="date" name="available_from" value="<?php echo htmlspecialchars(substr($f('available_from'), 0, 10)); ?>">
                </div>
                <div class="form-group">
                    <label for="mpUntil"><?php echo $_t('सम्म उपलब्ध (यसपछि हट्छ)', 'Available until (then removed)'); ?></label>
                    <input class="form-control" id="mpUntil" type="date" name="available_until" required value="<?php echo htmlspecialchars($untilDateVal ?: date('Y-m-d', strtotime('+30 days'))); ?>">
                    <input class="form-control" style="margin-top:6px" type="time" name="available_until_time" value="<?php echo htmlspecialchars($untilTimeVal ?: '23:59'); ?>">
                    <div class="mmp-hint"><?php echo $_t('यो मिति/समयपछि सार्वजनिक सूचीबाट स्वतः हट्छ। खाली भए ३० दिन राखिन्छ।', 'After this date/time the listing leaves the public list. Defaults to 30 days.'); ?></div>
                </div>
            </div>

            <div class="mmp-row">
                <div class="form-group">
                    <label for="mpLoc"><?php echo $_t('ठाउँ / वडा', 'Place / ward'); ?></label>
                    <input class="form-control" id="mpLoc" name="location" maxlength="200" value="<?php echo htmlspecialchars($f('location', $rAddress)); ?>">
                </div>
                <div class="form-group">
                    <label for="mpImg"><?php echo $_t('तस्बिर (ऐच्छिक)', 'Photo (optional)'); ?></label>
                    <input class="form-control" id="mpImg" type="file" name="image" accept="image/jpeg,image/png,image/webp">
                </div>
            </div>

            <div class="mmp-row">
                <div class="form-group">
                    <label for="mpCname"><?php echo $_t('सम्पर्क नाम', 'Contact name'); ?></label>
                    <input class="form-control" id="mpCname" name="contact_name" required maxlength="120" value="<?php echo htmlspecialchars($f('contact_name', $rName)); ?>">
                </div>
                <div class="form-group">
                    <label for="mpCphone"><?php echo $_t('सम्पर्क मोबाइल', 'Contact mobile'); ?></label>
                    <input class="form-control" id="mpCphone" name="contact_phone" required inputmode="numeric" pattern="9[0-9]{9}" maxlength="10" value="<?php echo htmlspecialchars($f('contact_phone', $rPhone)); ?>">
                </div>
            </div>

            <p class="mmp-hint"><?php echo $_t('स्वीकृतिपछि नाम, मोबाइल र मूल्य सार्वजनिक देखिन्छ। गलत विवरण भए प्रशासनले अस्वीकृत गर्न सक्छ।', 'After approval, name, mobile and price are public. Admin may reject inaccurate listings.'); ?></p>
            <button type="submit" class="wf-submit-btn" style="width:100%;padding:12px;background:var(--primary-color);color:#fff;border:0;border-radius:10px;font-weight:700;cursor:pointer">
                <i class="fas fa-paper-plane"></i> <?php echo $editRow ? $_t('अपडेट गरी स्वीकृति पठाउनुहोस्', 'Update and send for approval') : $_t('स्वीकृतिका लागि पठाउनुहोस्', 'Submit for approval'); ?>
            </button>
            <?php if ($editRow): ?>
                <p style="text-align:center;margin-top:10px"><a href="marketplace.php"><?php echo $_t('रद्द', 'Cancel'); ?></a></p>
            <?php endif; ?>
        </form>
    </div>
</div>
<script>
function mmpToggleType(){
  var skill = document.querySelector('input[name="listing_type"][value="skill"]');
  var isSkill = skill && skill.checked;
  document.querySelectorAll('.mmp-product-only').forEach(function(el){ el.style.display = isSkill ? 'none' : ''; });
  document.querySelectorAll('.mmp-skill-only').forEach(function(el){ el.style.display = isSkill ? '' : 'none'; });
  document.querySelectorAll('.mmp-opt-product').forEach(function(o){ o.disabled = isSkill; o.hidden = isSkill; });
  document.querySelectorAll('.mmp-opt-skill').forEach(function(o){ o.disabled = !isSkill; o.hidden = !isSkill; });
  var sel = document.getElementById('mpCat');
  if (sel) {
    var opt = sel.options[sel.selectedIndex];
    if (opt && opt.hidden) {
      var first = sel.querySelector('option:not([hidden])');
      if (first) sel.value = first.value;
    }
  }
}
mmpToggleType();
</script>
<?php endif; ?>

<div class="mem-card">
    <div class="mem-card-header">
        <div class="mem-card-title"><i class="fas fa-list"></i> <?php echo $_t('मेरा सूचीहरू', 'My listings'); ?></div>
    </div>
    <div class="mem-card-body">
        <?php if ($mine === []): ?>
            <p class="text-muted"><?php echo $_t('अहिले कुनै सूची छैन। माथिको फारमबाट थप्नुहोस्।', 'No listings yet. Add one with the form above.'); ?></p>
        <?php else: ?>
            <div class="mmp-grid">
            <?php foreach ($mine as $row):
                $st = mpStatusMeta((string) $row['status']);
                $img = mpListingImageUrl($row);
                $isEn = isEnglish();
            ?>
                <div class="mmp-item">
                    <div class="mmp-thumb">
                        <?php if ($img !== ''): ?><img src="<?php echo htmlspecialchars($img); ?>" alt=""><?php else: ?><i class="fas <?php echo htmlspecialchars(mpCategoryIcon((string)$row['listing_type'], (string)$row['category'])); ?>"></i><?php endif; ?>
                    </div>
                    <div>
                        <div><strong><?php echo htmlspecialchars((string)$row['title']); ?></strong></div>
                        <div class="mmp-hint">
                            <?php echo htmlspecialchars(mpTypeLabel((string)$row['listing_type'], $isEn)); ?>
                            · <?php echo htmlspecialchars(mpCategoryLabel((string)$row['listing_type'], (string)$row['category'], $isEn)); ?>
                            · <?php echo htmlspecialchars(mpPriceDisplay($row, $isEn)); ?>
                        </div>
                        <span class="mmp-pill" style="background:color-mix(in srgb, var(--primary-color) 12%, #fff)">
                            <?php echo $isEn ? $st['en'] : $st['np']; ?>
                        </span>
                        <?php if (!empty($row['available_until'])): ?>
                            <div class="mmp-hint"><?php echo $_t('सम्म', 'Until'); ?>: <?php echo function_exists('formatNepaliDate') ? formatNepaliDate($row['available_until'], true) : htmlspecialchars((string)$row['available_until']); ?></div>
                        <?php endif; ?>
                        <?php if ((string)$row['status'] === 'rejected' && trim((string)($row['admin_note'] ?? '')) !== ''): ?>
                            <div class="mmp-hint"><?php echo $_t('कारण', 'Reason'); ?>: <?php echo htmlspecialchars((string)$row['admin_note']); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="mmp-actions">
                        <a class="btn btn-sm btn-outline-primary" href="marketplace.php?edit=<?php echo (int)$row['id']; ?>"><?php echo $_t('सम्पादन', 'Edit'); ?></a>
                        <?php if (in_array((string)$row['status'], ['pending','approved'], true)): ?>
                        <form method="post" onsubmit="return confirm('<?php echo $_t('सूची फिर्ता गर्ने?', 'Withdraw this listing?'); ?>');">
                            <?php echo $csrfField; ?>
                            <input type="hidden" name="action" value="withdraw">
                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-secondary"><?php echo $_t('फिर्ता', 'Withdraw'); ?></button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($inquiries !== []): ?>
<div class="mem-card" style="margin-top:18px">
    <div class="mem-card-header">
        <div class="mem-card-title"><i class="fas fa-comments"></i> <?php echo $_t('चासो सन्देशहरू', 'Interest messages'); ?></div>
    </div>
    <div class="mem-card-body">
        <?php foreach ($inquiries as $iq): ?>
            <div class="mmp-item" style="grid-template-columns:1fr">
                <div>
                    <strong><?php echo htmlspecialchars((string)$iq['inquirer_name']); ?></strong>
                    · <a href="tel:<?php echo htmlspecialchars(mpPhoneDigits((string)$iq['inquirer_phone'])); ?>"><?php echo htmlspecialchars((string)$iq['inquirer_phone']); ?></a>
                    <div class="mmp-hint"><?php echo htmlspecialchars((string)$iq['title']); ?> · <?php echo htmlspecialchars((string)$iq['created_at']); ?></div>
                    <p style="margin:6px 0 0"><?php echo nl2br(htmlspecialchars((string)$iq['message'])); ?></p>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/chrome-foot.php'; ?>
