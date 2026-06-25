<?php
/**
 * ════════════════════════════════════════════════════════════
 * EMPTY STATE — Universal "No Records" Component
 * ════════════════════════════════════════════════════════════
 *
 * Use गर्ने तरिका:
 *   <?php
 *   $emptyIcon    = 'fa-inbox';         // FA icon (default: fa-inbox)
 *   $emptyTitle   = 'कुनै रेकर्ड छैन';  // heading
 *   $emptyMessage = 'थप जानकारीको लागि…'; // sub-text (optional)
 *   $emptyAction  = ['label'=>'नयाँ थप्नुहोस्','url'=>'add.php','icon'=>'fa-plus']; // optional CTA
 *   include __DIR__ . '/../includes/components/empty-state.php';
 *   ?>
 *
 * Constraint: PHP backend logic नछुने।
 * ════════════════════════════════════════════════════════════
 */
if (!isset($emptyIcon))    $emptyIcon    = 'fa-inbox';
if (!isset($emptyTitle))   $emptyTitle   = 'कुनै रेकर्ड फेला परेन।';
if (!isset($emptyMessage)) $emptyMessage = '';
if (!isset($emptyAction))  $emptyAction  = [];
?>
<div class="empty-state py-5 text-center">
    <div class="mb-3">
        <span style="display:inline-flex;align-items:center;justify-content:center;
                     width:80px;height:80px;border-radius:50%;
                     background:color-mix(in srgb,var(--primary-color,#1a5f2a) 8%,#f8faf9);
                     color:var(--primary-color,#1a5f2a);">
            <i class="fas <?php echo htmlspecialchars($emptyIcon, ENT_QUOTES); ?>"
               style="font-size:2rem;opacity:.6;"></i>
        </span>
    </div>
    <h5 class="fw-700 mb-1" style="color:var(--text-primary,#1a2e1f);font-size:.98rem;">
        <?php echo htmlspecialchars($emptyTitle, ENT_QUOTES, 'UTF-8'); ?>
    </h5>
    <?php if (!empty($emptyMessage)): ?>
    <p class="text-muted mb-3" style="font-size:.85rem;max-width:340px;margin-inline:auto;">
        <?php echo htmlspecialchars($emptyMessage, ENT_QUOTES, 'UTF-8'); ?>
    </p>
    <?php endif; ?>
    <?php if (!empty($emptyAction) && !empty($emptyAction['url'])): ?>
    <a href="<?php echo htmlspecialchars($emptyAction['url'], ENT_QUOTES, 'UTF-8'); ?>"
       class="btn btn-primary btn-sm">
        <?php if (!empty($emptyAction['icon'])): ?>
            <i class="fas <?php echo htmlspecialchars($emptyAction['icon'], ENT_QUOTES); ?> me-1"></i>
        <?php endif; ?>
        <?php echo htmlspecialchars($emptyAction['label'] ?? 'थप्नुहोस्', ENT_QUOTES, 'UTF-8'); ?>
    </a>
    <?php endif; ?>
</div>
<?php unset($emptyIcon, $emptyTitle, $emptyMessage, $emptyAction); ?>
