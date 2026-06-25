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
$_pt    = $prefillTitle ?? ($_pEn ? 'Your Info (from KYC / Profile)' : 'तपाईंको जानकारी (KYC / प्रोफाइलबाट)');
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
        ? 'Your name, member no., phone and email are <strong>auto-filled from KYC / Profile</strong>. Fill only the request details below.'
        : 'तपाईंको नाम, सदस्य नं., फोन र इमेल <strong>KYC / प्रोफाइलबाट auto-fill</strong> भएको छ। तल केवल अनुरोधको विवरण भर्नुहोस्।'; ?></div>
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

<style>
/* ── Coop Prefill Block — works on all public + member portal pages ── */
.coop-prefill-banner {
    display: flex; align-items: flex-start; gap: 10px;
    background: linear-gradient(135deg,
        color-mix(in srgb, var(--primary-color, #1a5f2a) 8%, #f8faf9),
        color-mix(in srgb, var(--primary-color, #1a5f2a) 4%, #fff));
    border: 1px solid color-mix(in srgb, var(--primary-color, #1a5f2a) 22%, white);
    border-left: 4px solid var(--primary-color, #1a5f2a);
    border-radius: 10px; padding: 11px 14px;
    font-size: .84rem; line-height: 1.5;
    color: var(--primary-color, #1a5f2a);
    margin-bottom: 14px;
}
.coop-prefill-banner i { font-size: 1rem; margin-top: 1px; flex-shrink: 0; }
.coop-prefill-block {
    background: #fff;
    border: 1px solid color-mix(in srgb, var(--primary-color, #1a5f2a) 18%, #e5e7eb);
    border-radius: 12px; margin-bottom: 20px;
    overflow: hidden;
    box-shadow: 0 1px 4px rgba(0,0,0,.05);
}
.coop-prefill-head {
    display: flex; align-items: center; gap: 8px;
    background: color-mix(in srgb, var(--primary-color, #1a5f2a) 8%, #f5faf5);
    border-bottom: 1px solid color-mix(in srgb, var(--primary-color, #1a5f2a) 14%, #e5e7eb);
    padding: 9px 14px;
    font-size: .8rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em;
    color: var(--primary-color, #1a5f2a);
}
.coop-prefill-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
    gap: 0;
}
.coop-prefill-item {
    display: flex; flex-direction: column; gap: 2px;
    padding: 10px 14px;
    border-right: 1px solid color-mix(in srgb, var(--primary-color, #1a5f2a) 10%, #e5e7eb);
    border-bottom: 1px solid color-mix(in srgb, var(--primary-color, #1a5f2a) 10%, #e5e7eb);
}
.coop-prefill-item:last-child { border-right: none; }
.coop-prefill-item--full { grid-column: 1 / -1; }
.coop-prefill-label {
    font-size: .68rem; font-weight: 700; text-transform: uppercase;
    letter-spacing: .04em; color: var(--text-muted, #6b7280);
}
.coop-prefill-value {
    font-size: .88rem; font-weight: 600;
    color: var(--text-primary, #1a2e1f);
    word-break: break-word;
}
.coop-prefill-mono {
    font-family: 'Courier New', monospace;
    background: color-mix(in srgb, var(--primary-color, #1a5f2a) 6%, #f5faf5);
    color: var(--primary-color, #1a5f2a);
    padding: 1px 6px; border-radius: 5px;
    font-size: .82rem; display: inline-block;
}
@media (max-width: 480px) {
    .coop-prefill-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>
