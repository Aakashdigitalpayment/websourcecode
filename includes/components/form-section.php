<?php
/**
 * ════════════════════════════════════════════════════════════
 * FORM SECTION — Uniform Form Card Section Component v2
 * ════════════════════════════════════════════════════════════
 *
 * USAGE:
 *   <?php
 *   $formSectionTitle = 'व्यक्तिगत जानकारी';
 *   $formSectionIcon  = 'fa-user';   // optional FA icon
 *   $formSectionInfo  = 'यो खेत अनिवार्य छ।'; // optional info text
 *   include __DIR__ . '/../includes/components/form-section.php';
 *   ?>
 *   <!-- form fields यहाँ -->
 *   <?php include __DIR__ . '/../includes/components/form-section-close.php'; ?>
 *
 * VARIABLES (optional):
 *   $formSectionTitle  - Section heading (string)
 *   $formSectionIcon   - FontAwesome icon class (string)
 *   $formSectionInfo   - Info/help text below title (string)
 *   $formSectionClass  - Extra CSS classes (string)
 *
 * ════════════════════════════════════════════════════════════
 */
if (!isset($formSectionTitle)) $formSectionTitle = '';
if (!isset($formSectionIcon))  $formSectionIcon  = '';
if (!isset($formSectionInfo))  $formSectionInfo  = '';
if (!isset($formSectionClass)) $formSectionClass = '';

$_classAttr = $formSectionClass ? " {$formSectionClass}" : '';
?>
<div class="form-card mb-4<?php echo $_classAttr; ?>">
    <?php if (!empty($formSectionTitle)): ?>
    <div class="form-card-header">
        <div class="form-card-title">
            <?php if (!empty($formSectionIcon)): ?>
                <i class="fas <?php echo htmlspecialchars($formSectionIcon, ENT_QUOTES); ?>"></i>
            <?php endif; ?>
            <span><?php echo htmlspecialchars($formSectionTitle, ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
        <?php if (!empty($formSectionInfo)): ?>
        <p class="form-card-info"><?php echo htmlspecialchars($formSectionInfo, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="form-section-body">
<?php
$GLOBALS['__fs_open'] = true;
unset($formSectionTitle, $formSectionIcon, $formSectionInfo, $formSectionClass, $_classAttr);
?>
