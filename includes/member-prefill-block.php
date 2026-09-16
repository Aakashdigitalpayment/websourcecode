<?php
/**
 * MEMBER KYC PREFILL BLOCK — reusable partial
 * ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
 * सबै public forms मा $loggedMember भएमा यो block देखाउनुस्।
 * hidden inputs: name, member_id, phone, email — form submit गर्दा backend पाउँछ।
 *
 * Required variables (set before include):
 *   $loggedMember  — array from getLoggedInMemberProfile()
 *   $kycForDisplay — array|null  from loadKycRowForLoggedMemberPublic() (optional, can be null)
 *
 * Optional variables:
 *   $prefillTitle  — string override for block heading
 *   $isEnglish     — bool (defaults to calling isEnglish())
 */
if (empty($loggedMember)) return;

$_pEn   = function_exists('isEnglish') ? isEnglish() : false;
$_pt    = $prefillTitle ?? ($_pEn ? 'Your Info (from KYM / Profile)' : 'तपाईंको जानकारी (KYM / प्रोफाइलबाट)');
$_krow  = $kycForDisplay ?? null;

/* Resolve display values */
$_pfName    = trim((string)(is_array($_krow) && !empty($_krow['full_name'])   ? $_krow['full_name']   : ($loggedMember['name']             ?? '')));
$_pfPhone   = trim((string)(is_array($_krow) && !empty($_krow['mobile'])      ? $_krow['mobile']      : ($loggedMember['phone']            ?? '')));
$_pfEmail   = trim((string)(is_array($_krow) && !empty($_krow['email'])       ? $_krow['email']       : ($loggedMember['email']            ?? '')));
$_pfMemNo   = trim((string)(is_array($_krow) && !empty($_krow['member_id'])   ? $_krow['member_id']   : ($loggedMember['sadasyata_number'] ?? '')));
if ($_pfMemNo === '' && is_array($_krow) && !empty($_krow['sadasyata_number'])) {
    $_pfMemNo = trim((string)$_krow['sadasyata_number']);
}
$_pfAddr    = trim((string)(is_array($_krow) && !empty($_krow['permanent_address']) ? $_krow['permanent_address'] : ''));
?>
<div class="coop-prefill-banner">
    <i class="lucide-icon" aria-hidden="true" data-lucide="circle-check"></i>
    <div><?php echo $_pEn
        ? 'Your name, member no., phone and email are <strong>auto-filled from KYM / Profile</strong>. Fill only the request details below.'
        : 'तपाईंको नाम, सदस्य नं., फोन र इमेल <strong>KYM / प्रोफाइलबाट auto-fill</strong> भएको छ। तल केवल अनुरोधको विवरण भर्नुहोस्।'; ?></div>
</div>

<div class="coop-prefill-block">
    <div class="coop-prefill-head">
        <i class="lucide-icon" aria-hidden="true" data-lucide="user-check"></i>
        <?php echo htmlspecialchars($_pt, ENT_QUOTES, 'UTF-8'); ?>
    </div>
    <div class="coop-prefill-grid">
        <div class="coop-prefill-item">
            <span class="coop-prefill-label"><?php echo $_pEn ? 'Name' : 'नाम'; ?></span>
            <span class="coop-prefill-value"><?= htmlspecialchars($_pfName ?: '—', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="coop-prefill-item">
            <span class="coop-prefill-label"><?php echo $_pEn ? 'Member No.' : 'सदस्यता नम्बर'; ?></span>
            <span class="coop-prefill-value coop-prefill-mono"><?= htmlspecialchars($_pfMemNo ?: '—', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="coop-prefill-item">
            <span class="coop-prefill-label"><?php echo $_pEn ? 'Phone' : 'फोन'; ?></span>
            <span class="coop-prefill-value"><?= htmlspecialchars($_pfPhone ?: '—', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="coop-prefill-item">
            <span class="coop-prefill-label">Email</span>
            <span class="coop-prefill-value"><?= htmlspecialchars($_pfEmail ?: '—', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php if ($_pfAddr): ?>
        <div class="coop-prefill-item coop-prefill-item--full">
            <span class="coop-prefill-label"><?php echo $_pEn ? 'Address' : 'ठेगाना'; ?></span>
            <span class="coop-prefill-value"><?= htmlspecialchars($_pfAddr, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Hidden inputs so backend still gets values on POST -->
<input type="hidden" name="_prefill_name"    value="<?= htmlspecialchars($_pfName,  ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="_prefill_phone"   value="<?= htmlspecialchars($_pfPhone, ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="_prefill_email"   value="<?= htmlspecialchars($_pfEmail, ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="_prefill_mem_no"  value="<?= htmlspecialchars($_pfMemNo, ENT_QUOTES, 'UTF-8') ?>">

<?php
if (!defined('COOP_PREFILL_BLOCK_CSS')) {
    define('COOP_PREFILL_BLOCK_CSS', true);
    if (function_exists('coopThemeLink')) {
        coopThemeLink('assets/css/member-prefill-block.css');
    } elseif (function_exists('coopThemeLinkHtml')) {
        echo coopThemeLinkHtml('assets/css/member-prefill-block.css');
    }
}
?>
