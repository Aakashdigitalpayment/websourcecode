<?php
/**
 * ════════════════════════════════════════════════════════════
 * SECTION HEADER — Uniform Section Heading Component
 * ════════════════════════════════════════════════════════════
 *
 * Use गर्ने तरिका:
 *   <?php
 *   $sectionTitle    = 'हाम्रा सेवाहरू';
 *   $sectionSubtitle = 'बचत, ऋण र रेमिट्यान्स सेवाहरू';
 *   $sectionIcon     = 'fa-star';   // optional
 *   $sectionAlign    = 'center';    // 'center' | 'left' | 'right'
 *   include __DIR__ . '/../includes/components/section-header.php';
 *   ?>
 *
 * Constraint: PHP backend logic नछुने।
 * ════════════════════════════════════════════════════════════
 */
if (!isset($sectionTitle))    $sectionTitle    = '';
if (!isset($sectionSubtitle)) $sectionSubtitle = '';
if (!isset($sectionIcon))     $sectionIcon     = '';
if (!isset($sectionAlign))    $sectionAlign    = 'center';

$_alignClass = in_array($sectionAlign, ['left', 'right']) ? "text-{$sectionAlign}" : 'text-center';
?>
<div class="section-header <?php echo $_alignClass; ?>">
    <h2>
        <?php if (!empty($sectionIcon)): ?>
            <i class="fas <?php echo htmlspecialchars($sectionIcon, ENT_QUOTES); ?> me-2"
               style="color:var(--primary-color);font-size:.9em;"></i>
        <?php endif; ?>
        <?php echo htmlspecialchars($sectionTitle, ENT_QUOTES, 'UTF-8'); ?>
    </h2>
    <?php if (!empty($sectionSubtitle)): ?>
    <p><?php echo htmlspecialchars($sectionSubtitle, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <div class="section-divider" style="
        width:52px;height:3px;border-radius:3px;margin:10px auto 0;
        background:linear-gradient(90deg,var(--primary-color),var(--primary-light));
        <?php echo $sectionAlign === 'left' ? 'margin-left:0;' : ''; ?>
        <?php echo $sectionAlign === 'right' ? 'margin-right:0;margin-left:auto;' : ''; ?>
    "></div>
</div>
<?php
// Reset section vars so they don't bleed into next include
unset($sectionTitle, $sectionSubtitle, $sectionIcon, $sectionAlign, $_alignClass);
?>
