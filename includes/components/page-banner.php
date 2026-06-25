<?php
/**
 * ════════════════════════════════════════════════════════════
 * PAGE BANNER — Universal Inner Page Banner Component
 * ════════════════════════════════════════════════════════════
 *
 * Use गर्ने तरिका (हरेक inner page मा):
 *
 *   <?php include __DIR__ . '/../includes/components/page-banner.php'; ?>
 *
 * Variables (page मा define गर्नुहोस्, banner include गर्नुअघि):
 *   $pageTitle     string  — Page heading (Nepali वा English)
 *   $pageSubtitle  string  — Optional sub-text (default: '')
 *   $bannerIcon    string  — FA icon class (e.g. 'fa-users', default: 'fa-home')
 *   $breadcrumbs   array   — [['label'=>'Home','url'=>'/'], ['label'=>'Current']]
 *
 * Constraint: PHP backend logic/session code नछुने।
 * ════════════════════════════════════════════════════════════
 */
if (!isset($pageTitle))    $pageTitle    = 'पृष्ठ';
if (!isset($pageSubtitle)) $pageSubtitle = '';
if (!isset($bannerIcon))   $bannerIcon   = 'fa-chevron-right';
if (!isset($breadcrumbs))  $breadcrumbs  = [];
?>
<div class="page-banner">
    <div class="container">
        <div class="d-flex align-items-center justify-content-center gap-2 mb-1">
            <i class="fas <?php echo htmlspecialchars($bannerIcon, ENT_QUOTES); ?> text-white opacity-75"></i>
            <h1><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
        </div>
        <?php if (!empty($pageSubtitle)): ?>
            <p class="mb-2"><?php echo htmlspecialchars($pageSubtitle, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
        <?php if (!empty($breadcrumbs)): ?>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb justify-content-center mb-0">
                <?php foreach ($breadcrumbs as $i => $crumb):
                    $isLast = ($i === count($breadcrumbs) - 1);
                    $label  = htmlspecialchars($crumb['label'] ?? '', ENT_QUOTES, 'UTF-8');
                    $url    = htmlspecialchars($crumb['url']   ?? '#', ENT_QUOTES, 'UTF-8');
                ?>
                <li class="breadcrumb-item<?php echo $isLast ? ' active' : ''; ?>"
                    <?php echo $isLast ? 'aria-current="page"' : ''; ?>>
                    <?php if ($isLast || empty($crumb['url'])): ?>
                        <?php echo $label; ?>
                    <?php else: ?>
                        <a href="<?php echo $url; ?>"><?php echo $label; ?></a>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
            </ol>
        </nav>
        <?php endif; ?>
    </div>
</div>
