<?php
/**
 * Public listing renderer — member-marketplace.php / member-skills.php
 * Expects $mpPublicKind = 'product' | 'skill'
 */
if (!defined('SITE_URL')) {
    require_once __DIR__ . '/config.php';
}
require_once __DIR__ . '/ensure-tables.php';
require_once __DIR__ . '/member-marketplace-tables.php';
if (is_file(__DIR__ . '/member-auth.php')) {
    require_once __DIR__ . '/member-auth.php';
}

$mpKind = (isset($mpPublicKind) && $mpPublicKind === 'skill') ? 'skill' : 'product';
$en = function_exists('isEnglish') && isEnglish();
$mpT = static function (string $np, string $enTxt) use ($en): string {
    return $en ? $enTxt : $np;
};

$pageTitle = $mpKind === 'skill'
    ? $mpT('सीप सदस्य / कामदार', 'Skill members / workers')
    : $mpT('सदस्य बजार', 'Member marketplace');
$pageDescription = $mpKind === 'skill'
    ? $mpT(
        'सहकारी सदस्यले दिने प्लम्बर, विद्युत, सौन्दर्य लगायतका सीप सेवा — सम्पर्क गरी लिन सकिन्छ।',
        'Plumber, electrician, beautician and other skills offered by cooperative members.'
    )
    : $mpT(
        'सदस्यले फलाएका तरकारी, फलफूल र अन्य उत्पादन बिक्रीका लागि — अर्को सदस्यले सम्पर्क गरी लिन सकिन्छ।',
        'Vegetables, fruit and other produce listed by cooperative members for fellow members.'
    );

$successMsg = '';
$errorMsg = '';
if (!empty($_SESSION['mp_inq_flash']) && is_array($_SESSION['mp_inq_flash'])) {
    $flash = $_SESSION['mp_inq_flash'];
    unset($_SESSION['mp_inq_flash']);
    if (!empty($flash['ok'])) {
        $successMsg = $mpT('सन्देश पठाइयो। सदस्यले सदस्य पोर्टलमा सूचना पाउनेछन्।', 'Message sent. The member will see it in the member portal.');
    } elseif (!empty($flash['err'])) {
        $errorMsg = (string) $flash['err'];
    }
}

$db = null;
$listings = [];
$detail = null;
$detailUnavailable = false;
$cats = $mpKind === 'skill' ? mpSkillCategories() : mpProductCategories();

try {
    $db = getDB();
    $GLOBALS['db'] = $db;
    ensureMemberMarketplaceTables($db);
    mpExpireStaleListings($db);
} catch (Throwable $e) {
    $db = null;
}

$catFilter = trim((string) ($_GET['cat'] ?? ''));
if ($catFilter !== '' && !isset($cats[$catFilter])) {
    $catFilter = '';
}
$q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 80);
$detailId = (int) ($_GET['id'] ?? 0);

if ($db instanceof PDO) {
    if ($detailId > 0) {
        $row = mpFetchListingById($db, $detailId);
        if ($row && ($row['listing_type'] ?? '') !== $mpKind) {
            header('Location: ' . mpPublicPageUrl((string) $row['listing_type'], $detailId));
            exit;
        }
        if ($row && mpIsPubliclyVisible($row)) {
            $detail = $row;
        } elseif ($row) {
            $detailUnavailable = true;
        } elseif ($detailId > 0) {
            $detailUnavailable = true;
        }
    }
    $listings = mpFetchPublicListings($db, $mpKind, 120, $catFilter, $q);
}

if ($detail) {
    $pageTitle = (string) $detail['title'] . ' — ' . $pageTitle;
}

/* Inquiry POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mp_inquiry']) && $db instanceof PDO) {
    $backId = (int) ($_POST['listing_id'] ?? 0);
    $err = '';
    if (!function_exists('verifyCSRFToken') || !verifyCSRFToken()) {
        $err = $mpT('सुरक्षा जाँच असफल।', 'Security check failed.');
    } elseif (function_exists('checkRateLimit') && !checkRateLimit('mp_inquiry', 8, 3600)) {
        $err = $mpT('धेरै सन्देश भए। केही समयपछि प्रयास गर्नुहोस्।', 'Too many messages. Please try later.');
    } else {
        $listing = mpFetchListingById($db, $backId);
        if (!$listing || !mpIsPubliclyVisible($listing) || ($listing['listing_type'] ?? '') !== $mpKind) {
            $err = $mpT('यो सूची अहिले उपलब्ध छैन।', 'This listing is no longer available.');
        } else {
            $iname = function_exists('clean_text') ? clean_text($_POST['inquirer_name'] ?? '', 120) : trim((string) ($_POST['inquirer_name'] ?? ''));
            $iphone = mpPhoneDigits((string) ($_POST['inquirer_phone'] ?? ''));
            $imsg = function_exists('clean_text') ? clean_text($_POST['message'] ?? '', 1000) : trim((string) ($_POST['message'] ?? ''));
            if ($iname === '' || mb_strlen($iname) < 2) {
                $err = $mpT('नाम लेख्नुहोस्।', 'Please enter your name.');
            } elseif (!preg_match('/^9\d{9}$/', $iphone)) {
                $err = $mpT('सही १० अङ्कको मोबाइल (९XXXXXXXXX) लेख्नुहोस्।', 'Enter a valid 10-digit mobile (9XXXXXXXXX).');
            } elseif (mb_strlen($imsg) < 8) {
                $err = $mpT('सन्देश कम्तीमा ८ अक्षरको हुनुपर्छ।', 'Message must be at least 8 characters.');
            } else {
                $inquirerMemberId = null;
                if (function_exists('currentMember')) {
                    try {
                        $cm = currentMember();
                        if ($cm && !empty($cm['id'])) {
                            $inquirerMemberId = (int) $cm['id'];
                        }
                    } catch (Throwable $e) {
                        $inquirerMemberId = null;
                    }
                }
                try {
                    $ins = $db->prepare(
                        'INSERT INTO member_marketplace_inquiries (listing_id, member_id, inquirer_name, inquirer_phone, message)
                         VALUES (?,?,?,?,?)'
                    );
                    $ins->execute([$backId, $inquirerMemberId, $iname, $iphone, $imsg]);
                    $ownerId = (int) ($listing['member_id'] ?? 0);
                    if ($ownerId > 0 && function_exists('createMemberNotification')) {
                        $GLOBALS['db'] = $db;
                        $nTitle = $mpKind === 'skill'
                            ? 'सीप सेवामा चासो'
                            : 'उत्पादनमा चासो';
                        $nMsg = $iname . ' (' . $iphone . '): ' . mb_substr($imsg, 0, 160);
                        createMemberNotification($ownerId, $nTitle, $nMsg, 'info', SITE_URL . 'member/marketplace.php');
                    } elseif ($ownerId > 0) {
                        try {
                            $db->prepare('INSERT INTO member_notifications (member_id, title, message, type, link) VALUES (?,?,?,?,?)')
                                ->execute([
                                    $ownerId,
                                    $mpKind === 'skill' ? 'सीप सेवामा चासो' : 'उत्पादनमा चासो',
                                    $iname . ' (' . $iphone . '): ' . mb_substr($imsg, 0, 160),
                                    'info',
                                    SITE_URL . 'member/marketplace.php',
                                ]);
                        } catch (Throwable $e) { /* ignore */ }
                    }
                    $_SESSION['mp_inq_flash'] = ['ok' => true];
                    header('Location: ' . mpPublicPageUrl($mpKind, $backId));
                    exit;
                } catch (Throwable $e) {
                    $err = $mpT('सन्देश पठाउन सकिएन।', 'Could not send the message.');
                }
            }
        }
    }
    $_SESSION['mp_inq_flash'] = ['ok' => false, 'err' => $err];
    header('Location: ' . mpPublicPageUrl($mpKind, $backId > 0 ? $backId : 0));
    exit;
}

$prefillName = '';
$prefillPhone = '';
if (function_exists('currentMember')) {
    try {
        $cm = currentMember();
        if (is_array($cm)) {
            $prefillName = trim((string) ($cm['name'] ?? ''));
            $prefillPhone = mpPhoneDigits((string) ($cm['phone'] ?? ''));
        }
    } catch (Throwable $e) {
        /* ignore */
    }
}

require_once __DIR__ . '/header.php';
$L = function_exists('getLangStrings') ? getLangStrings() : ['home' => $mpT('गृहपृष्ठ', 'Home')];
$csrf = function_exists('generateCSRFToken') ? generateCSRFToken() : '';

$heroTitle = $mpKind === 'skill'
    ? $mpT('सीप सदस्य / कामदार', 'Skill members / workers')
    : $mpT('सदस्य बजार', 'Member marketplace');
$heroLead = $mpKind === 'skill'
    ? $mpT(
        'प्लम्बर, विद्युत मिस्त्री, ब्युटीसियन लगायतका सीप भएका सदस्यलाई यहींबाट सम्पर्क गर्नुहोस्। सूची अवधि सकिएपछि हराउँछ; प्रशासनले स्वीकृत गरेपछि मात्र देखिन्छ।',
        'Contact member plumbers, electricians, beauticians and other skilled workers here. Listings leave the page after their available time; they appear only after admin approval.'
    )
    : $mpT(
        'सदस्यले फलाएका काँक्रो, तरकारी, फलफूल लगायत उत्पादन बिक्रीका लागि राख्छन्। अर्को सदस्यले सम्पर्क गरी लिन सक्छन्। मूल्य/उपलब्ध समय सकिएपछि सूची हट्छ; प्रशासन स्वीकृतिपछि मात्र सार्वजनिक हुन्छ।',
        'Members list cucumbers, vegetables, fruit and other produce. Fellow members can contact them to buy. Listings leave after price/availability ends and go public only after admin approval.'
    );
$otherHref = $mpKind === 'skill' ? (SITE_URL . 'member-marketplace.php') : (SITE_URL . 'member-skills.php');
$otherLabel = $mpKind === 'skill'
    ? $mpT('उत्पादन बजार हेर्नुहोस्', 'View product marketplace')
    : $mpT('सीप कामदार हेर्नुहोस्', 'View skill workers');
$memberCta = SITE_URL . 'member/marketplace.php';

$renderCard = static function (array $row) use ($mpKind, $mpT, $en): void {
    $id = (int) ($row['id'] ?? 0);
    $title = (string) ($row['title'] ?? '');
    $cat = (string) ($row['category'] ?? '');
    $loc = trim((string) ($row['location'] ?? ''));
    $phone = mpPhoneDigits((string) ($row['contact_phone'] ?? ''));
    $name = trim((string) ($row['contact_name'] ?? ''));
    $img = mpListingImageUrl($row);
    $price = mpPriceDisplay($row, $en);
    $until = trim((string) ($row['available_until'] ?? ''));
    $untilDisp = $until !== '' && function_exists('formatNepaliDate')
        ? formatNepaliDate($until, true)
        : ($until !== '' ? substr($until, 0, 16) : '');
    $timeFrom = substr((string) ($row['available_time_from'] ?? ''), 0, 5);
    $timeTo = substr((string) ($row['available_time_to'] ?? ''), 0, 5);
    $href = mpPublicPageUrl($mpKind, $id);
    $icon = mpCategoryIcon($mpKind, $cat);
    $wa = $phone !== '' ? mpWhatsAppUrl($phone, $title) : '';
    ?>
    <article class="mkt-card">
        <a class="mkt-card-media" href="<?php echo htmlspecialchars($href); ?>">
            <?php if ($img !== ''): ?>
                <img src="<?php echo htmlspecialchars($img); ?>" alt="" loading="lazy">
            <?php else: ?>
                <span class="mkt-card-fallback" aria-hidden="true"><i class="fas <?php echo htmlspecialchars($icon); ?>"></i></span>
            <?php endif; ?>
        </a>
        <div class="mkt-card-body">
            <span class="mkt-chip"><?php echo htmlspecialchars(mpCategoryLabel($mpKind, $cat, $en)); ?></span>
            <h3 class="mkt-card-title"><a href="<?php echo htmlspecialchars($href); ?>"><?php echo htmlspecialchars($title); ?></a></h3>
            <p class="mkt-price"><?php echo htmlspecialchars($price); ?></p>
            <?php if ($name !== '' || $loc !== ''): ?>
            <p class="mkt-meta">
                <?php if ($name !== ''): ?><i class="fas fa-user" aria-hidden="true"></i> <?php echo htmlspecialchars($name); ?><?php endif; ?>
                <?php if ($loc !== ''): ?><span class="mkt-dot">·</span><i class="fas fa-location-dot" aria-hidden="true"></i> <?php echo htmlspecialchars($loc); ?><?php endif; ?>
            </p>
            <?php endif; ?>
            <?php if ($untilDisp !== '' || ($timeFrom !== '' && $timeFrom !== '00:00')): ?>
            <p class="mkt-until">
                <i class="fas fa-clock" aria-hidden="true"></i>
                <?php
                if ($untilDisp !== '') {
                    echo $mpT('उपलब्ध ', 'Until ') . htmlspecialchars($untilDisp);
                }
                if ($timeFrom !== '' && $timeFrom !== '00:00') {
                    echo ($untilDisp !== '' ? ' · ' : '') . htmlspecialchars($timeFrom) . '–' . htmlspecialchars($timeTo !== '' ? $timeTo : '');
                }
                ?>
            </p>
            <?php endif; ?>
            <div class="mkt-card-actions">
                <?php if ($phone !== ''): ?>
                    <a class="mkt-btn mkt-btn-call" href="tel:<?php echo htmlspecialchars($phone); ?>"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($phone); ?></a>
                <?php endif; ?>
                <?php if ($wa !== ''): ?>
                    <a class="mkt-btn mkt-btn-wa" href="<?php echo htmlspecialchars($wa); ?>" target="_blank" rel="noopener noreferrer"><i class="fab fa-whatsapp"></i> WhatsApp</a>
                <?php endif; ?>
                <a class="mkt-btn mkt-btn-more" href="<?php echo htmlspecialchars($href); ?>"><?php echo $mpT('विवरण', 'Details'); ?></a>
            </div>
        </div>
    </article>
    <?php
};
?>

<section class="page-banner page-banner-modern">
    <div class="container">
        <div class="banner-content-modern">
            <h1 class="page-title-modern"><?php echo htmlspecialchars($heroTitle); ?></h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb breadcrumb-modern">
                    <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>" class="breadcrumb-link-modern"><?php echo $L['home']; ?></a></li>
                    <li class="breadcrumb-item active"><?php echo htmlspecialchars($heroTitle); ?></li>
                </ol>
            </nav>
        </div>
    </div>
</section>

<section class="section-padding mkt-wrap">
<div class="container">

    <div class="mkt-intro">
        <p class="mkt-lead"><?php echo $heroLead; ?></p>
        <div class="mkt-cta-row">
            <a class="btn btn-success btn-sm" href="<?php echo htmlspecialchars($memberCta); ?>">
                <i class="fas fa-plus me-1"></i><?php echo $mpT('मेरो सूची थप्नुहोस्', 'Add my listing'); ?>
            </a>
            <a class="btn btn-outline-success btn-sm" href="<?php echo htmlspecialchars($otherHref); ?>">
                <i class="fas fa-<?php echo $mpKind === 'skill' ? 'basket-shopping' : 'screwdriver-wrench'; ?> me-1"></i><?php echo $otherLabel; ?>
            </a>
            <a class="btn btn-outline-secondary btn-sm" href="<?php echo SITE_URL; ?>member/login.php">
                <i class="fas fa-user me-1"></i><?php echo $mpT('सदस्य पोर्टल', 'Member portal'); ?>
            </a>
        </div>
    </div>

    <?php if ($successMsg !== ''): ?>
        <div class="alert alert-success" role="status"><?php echo htmlspecialchars($successMsg); ?></div>
    <?php endif; ?>
    <?php if ($errorMsg !== ''): ?>
        <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($errorMsg); ?></div>
    <?php endif; ?>

    <?php if ($detailUnavailable): ?>
        <div class="mkt-empty">
            <i class="fas fa-hourglass-end"></i>
            <p><?php echo $mpT('यो सूची अहिले उपलब्ध छैन — स्वीकृत नभएको, अवधि सकिएको, वा फिर्ता लिइएको हुन सक्छ।', 'This listing is not available — it may be unapproved, expired, or withdrawn.'); ?></p>
            <a class="btn btn-outline-success" href="<?php echo htmlspecialchars(mpPublicPageUrl($mpKind)); ?>"><?php echo $mpT('सूचीमा फर्कनुहोस्', 'Back to list'); ?></a>
        </div>
    <?php elseif ($detail): ?>
        <?php
        $dImg = mpListingImageUrl($detail);
        $dPhone = mpPhoneDigits((string) ($detail['contact_phone'] ?? ''));
        $dWa = $dPhone !== '' ? mpWhatsAppUrl($dPhone, (string) $detail['title']) : '';
        $dUntil = trim((string) ($detail['available_until'] ?? ''));
        $dUntilDisp = $dUntil !== '' && function_exists('formatNepaliDate') ? formatNepaliDate($dUntil, true) : $dUntil;
        $dFrom = trim((string) ($detail['available_from'] ?? ''));
        $dFromDisp = $dFrom !== '' && function_exists('formatNepaliDate') ? formatNepaliDate($dFrom) : $dFrom;
        $tFrom = substr((string) ($detail['available_time_from'] ?? ''), 0, 5);
        $tTo = substr((string) ($detail['available_time_to'] ?? ''), 0, 5);
        ?>
        <p class="mb-3"><a href="<?php echo htmlspecialchars(mpPublicPageUrl($mpKind)); ?>">← <?php echo $mpT('सूचीमा फर्कनुहोस्', 'Back to list'); ?></a></p>
        <article class="mkt-detail">
            <div class="mkt-detail-media">
                <?php if ($dImg !== ''): ?>
                    <img src="<?php echo htmlspecialchars($dImg); ?>" alt="">
                <?php else: ?>
                    <div class="mkt-card-fallback mkt-detail-fallback"><i class="fas <?php echo htmlspecialchars(mpCategoryIcon($mpKind, (string) $detail['category'])); ?>"></i></div>
                <?php endif; ?>
            </div>
            <div class="mkt-detail-body">
                <span class="mkt-chip"><?php echo htmlspecialchars(mpCategoryLabel($mpKind, (string) $detail['category'], $en)); ?></span>
                <h2><?php echo htmlspecialchars((string) $detail['title']); ?></h2>
                <p class="mkt-price mkt-price-lg"><?php echo htmlspecialchars(mpPriceDisplay($detail, $en)); ?></p>
                <?php if (trim((string) ($detail['quantity'] ?? '')) !== ''): ?>
                    <p class="mkt-meta"><?php echo $mpT('परिमाण', 'Quantity'); ?>: <?php echo htmlspecialchars((string) $detail['quantity']); ?></p>
                <?php endif; ?>
                <?php if ($mpKind === 'skill' && $detail['experience_years'] !== null && $detail['experience_years'] !== ''): ?>
                    <p class="mkt-meta"><?php echo $mpT('अनुभव', 'Experience'); ?>: <?php echo (int) $detail['experience_years']; ?> <?php echo $mpT('वर्ष', 'years'); ?></p>
                <?php endif; ?>
                <?php if (trim((string) ($detail['description'] ?? '')) !== ''): ?>
                    <p class="mkt-desc"><?php echo htmlspecialchars((string) $detail['description']); ?></p>
                <?php endif; ?>
                <ul class="mkt-facts">
                    <?php if (trim((string) ($detail['contact_name'] ?? '')) !== ''): ?>
                        <li><i class="fas fa-user"></i> <?php echo htmlspecialchars((string) $detail['contact_name']); ?></li>
                    <?php endif; ?>
                    <?php if (trim((string) ($detail['location'] ?? '')) !== ''): ?>
                        <li><i class="fas fa-location-dot"></i> <?php echo htmlspecialchars((string) $detail['location']); ?></li>
                    <?php endif; ?>
                    <?php if ($dFromDisp !== ''): ?>
                        <li><i class="fas fa-calendar"></i> <?php echo $mpT('सुरू', 'From'); ?>: <?php echo htmlspecialchars($dFromDisp); ?></li>
                    <?php endif; ?>
                    <?php if ($dUntilDisp !== ''): ?>
                        <li><i class="fas fa-hourglass-end"></i> <?php echo $mpT('सम्म उपलब्ध', 'Available until'); ?>: <?php echo htmlspecialchars($dUntilDisp); ?></li>
                    <?php endif; ?>
                    <?php if ($tFrom !== '' && $tFrom !== '00:00'): ?>
                        <li><i class="fas fa-clock"></i> <?php echo $mpT('दैनिक समय', 'Daily hours'); ?>: <?php echo htmlspecialchars($tFrom . '–' . $tTo); ?></li>
                    <?php endif; ?>
                </ul>
                <div class="mkt-card-actions">
                    <?php if ($dPhone !== ''): ?>
                        <a class="mkt-btn mkt-btn-call" href="tel:<?php echo htmlspecialchars($dPhone); ?>"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($dPhone); ?></a>
                    <?php endif; ?>
                    <?php if ($dWa !== ''): ?>
                        <a class="mkt-btn mkt-btn-wa" href="<?php echo htmlspecialchars($dWa); ?>" target="_blank" rel="noopener noreferrer"><i class="fab fa-whatsapp"></i> WhatsApp</a>
                    <?php endif; ?>
                </div>

                <form method="post" class="mkt-inq-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <input type="hidden" name="mp_inquiry" value="1">
                    <input type="hidden" name="listing_id" value="<?php echo (int) $detail['id']; ?>">
                    <h3><?php echo $mpT('सदस्यलाई सन्देश पठाउनुहोस्', 'Send a message to the member'); ?></h3>
                    <p class="text-muted small"><?php echo $mpT('सन्देश सदस्य पोर्टलमा जान्छ। सिधै फोन/WhatsApp पनि गर्न सकिन्छ।', 'The message goes to the member portal. You can also call or use WhatsApp.'); ?></p>
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label" for="inqName"><?php echo $mpT('तपाईंको नाम', 'Your name'); ?></label>
                            <input class="form-control" id="inqName" name="inquirer_name" required maxlength="120" value="<?php echo htmlspecialchars($prefillName); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="inqPhone"><?php echo $mpT('मोबाइल', 'Mobile'); ?></label>
                            <input class="form-control" id="inqPhone" name="inquirer_phone" required inputmode="numeric" pattern="9[0-9]{9}" maxlength="10" value="<?php echo htmlspecialchars($prefillPhone); ?>" placeholder="98XXXXXXXX">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="inqMsg"><?php echo $mpT('सन्देश', 'Message'); ?></label>
                            <textarea class="form-control" id="inqMsg" name="message" required maxlength="1000" rows="3" placeholder="<?php echo $mpT('के चाहिएको हो, कहिले चाहिन्छ…', 'What you need and when…'); ?>"></textarea>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-success"><?php echo $mpT('सन्देश पठाउनुहोस्', 'Send message'); ?></button>
                        </div>
                    </div>
                </form>
            </div>
        </article>
    <?php else: ?>

        <form method="get" class="mkt-filter" role="search">
            <label class="visually-hidden" for="mktQ"><?php echo $mpT('खोज', 'Search'); ?></label>
            <input type="search" class="form-control" id="mktQ" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="<?php echo $mpT('नाम, ठाउँ, उत्पादन…', 'Name, place, produce…'); ?>">
            <select class="form-select" name="cat" aria-label="<?php echo $mpT('वर्ग', 'Category'); ?>">
                <option value=""><?php echo $mpT('सबै वर्ग', 'All categories'); ?></option>
                <?php foreach ($cats as $ck => $cv): ?>
                    <option value="<?php echo htmlspecialchars($ck); ?>" <?php echo $catFilter === $ck ? 'selected' : ''; ?>><?php echo htmlspecialchars($en ? $cv['en'] : $cv['np']); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-success"><?php echo $mpT('खोज्नुहोस्', 'Search'); ?></button>
        </form>

        <?php if ($listings === []): ?>
            <div class="mkt-empty">
                <i class="fas fa-<?php echo $mpKind === 'skill' ? 'screwdriver-wrench' : 'basket-shopping'; ?>"></i>
                <p><?php echo $mpT('अहिले सार्वजनिक सूची छैन। सदस्य पोर्टलबाट थप्न सकिन्छ — प्रशासन स्वीकृतिपछि यहाँ देखिन्छ।', 'No public listings yet. Members can add from the portal; they appear here after admin approval.'); ?></p>
                <a class="btn btn-outline-success" href="<?php echo htmlspecialchars($memberCta); ?>"><?php echo $mpT('सूची थप्नुहोस्', 'Add a listing'); ?></a>
            </div>
        <?php else: ?>
            <div class="mkt-grid">
                <?php foreach ($listings as $row) { $renderCard($row); } ?>
            </div>
        <?php endif; ?>

    <?php endif; ?>

</div>
</section>

<?php require_once __DIR__ . '/footer.php'; ?>
