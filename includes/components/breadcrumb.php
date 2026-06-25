<?php
/**
 * ════════════════════════════════════════════════════════════
 * BREADCRUMB — Universal Navigation Trail Component
 * ════════════════════════════════════════════════════════════
 *
 * Use गर्ने तरिका:
 *   <?php
 *   $breadcrumbs = [
 *     ['label' => 'गृहपृष्ठ',    'url' => SITE_URL],
 *     ['label' => 'सदस्यहरू',    'url' => 'members.php'],
 *     ['label' => 'विवरण'],      // last item — no url
 *   ];
 *   include __DIR__ . '/../includes/components/breadcrumb.php';
 *   ?>
 *
 * Options:
 *   $breadcrumbStyle  = 'default' | 'card'  (default: 'default' — inline plain)
 *
 * Constraint: PHP backend logic नछुने।
 * ════════════════════════════════════════════════════════════
 */
if (!isset($breadcrumbs)      || !is_array($breadcrumbs)) $breadcrumbs      = [];
if (!isset($breadcrumbStyle))  $breadcrumbStyle = 'default';

if (empty($breadcrumbs)) return;

$_wrapStyle = $breadcrumbStyle === 'card'
    ? 'background:var(--bg-soft,#f5faf6);border:1px solid var(--border-color,#e5e7eb);border-radius:10px;padding:10px 16px;margin-bottom:16px;'
    : 'margin-bottom:14px;';
?>
<nav aria-label="breadcrumb" style="<?php echo $_wrapStyle; ?>">
    <ol class="breadcrumb mb-0" style="font-size:.82rem;">
        <?php foreach ($breadcrumbs as $_i => $_crumb):
            $_isLast = ($_i === count($breadcrumbs) - 1);
            $_label  = htmlspecialchars($_crumb['label'] ?? '', ENT_QUOTES, 'UTF-8');
            $_url    = htmlspecialchars($_crumb['url']   ?? '', ENT_QUOTES, 'UTF-8');
            $_icon   = !empty($_crumb['icon']) ? '<i class="fas ' . htmlspecialchars($_crumb['icon'], ENT_QUOTES) . ' me-1"></i>' : '';
        ?>
        <li class="breadcrumb-item<?php echo $_isLast ? ' active fw-600' : ''; ?>"
            <?php echo $_isLast ? 'aria-current="page"' : ''; ?>>
            <?php if (!$_isLast && !empty($_url)): ?>
                <a href="<?php echo $_url; ?>" class="text-decoration-none" style="color:var(--primary-color,#1a5f2a);">
                    <?php echo $_icon . $_label; ?>
                </a>
            <?php else: ?>
                <?php echo $_icon . $_label; ?>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ol>
</nav>
<?php
unset($breadcrumbs, $breadcrumbStyle, $_wrapStyle, $_i, $_crumb, $_isLast, $_label, $_url, $_icon);
?>
