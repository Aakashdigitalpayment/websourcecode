<?php
/**
 * Information Room — shared document viewer partial
 *
 * Expects: $irItem, $irPanel ('admin'|'member'), $irBackUrl, $irViewerLabel, $_t closure
 */
if (!isset($irItem) || !is_array($irItem)) {
    return;
}

$irPanel = ($irPanel ?? 'member') === 'admin' ? 'admin' : 'member';
$irId = (int) ($irItem['id'] ?? 0);
$irTitle = trim((string) ($irItem['title_np'] ?? '')) !== ''
    ? (string) $irItem['title_np']
    : (string) ($irItem['title'] ?? '');
$irCat = irCategoryLabel((string) ($irItem['category'] ?? 'other'), function_exists('isEnglish') && isEnglish());
$irRef = trim((string) ($irItem['reference_no'] ?? ''));
$irDate = trim((string) ($irItem['meeting_date'] ?? ''));
$irDesc = trim((string) ($irItem['description_np'] ?? $irItem['description'] ?? ''));
$irAllowDl = !empty($irItem['allow_download']);
$irRestrict = !empty($irItem['restrict_copy']);
$irExt = strtolower((string) ($irItem['file_type'] ?? pathinfo((string) ($irItem['file_path'] ?? ''), PATHINFO_EXTENSION)));
$irFileUrl = rtrim((string) (defined('SITE_URL') ? SITE_URL : '/'), '/') . '/information-room-file.php?id=' . $irId;
$irDlUrl = $irFileUrl . '&dl=1';
$irIsPdf = ($irExt === 'pdf');
$irIsImage = in_array($irExt, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true);
?>
<div class="ir-viewer-wrap<?php echo $irRestrict ? ' ir-restricted' : ''; ?>" id="irViewerWrap">
    <div class="ir-viewer-toolbar">
        <a href="<?php echo htmlspecialchars($irBackUrl ?? '#', ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-arrow-left me-1"></i><?php echo $_t('फिर्ता', 'Back'); ?>
        </a>
        <div class="ir-viewer-meta">
            <span class="badge bg-primary-subtle text-primary border"><?php echo htmlspecialchars($irCat, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php if ($irRef !== ''): ?>
            <span class="small text-muted ms-2"><?php echo $_t('सन्दर्भ', 'Ref'); ?>: <?php echo htmlspecialchars($irRef, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
            <?php if ($irDate !== ''): ?>
            <span class="small text-muted ms-2"><?php echo $_t('मिति', 'Date'); ?>: <?php echo htmlspecialchars($irDate, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
        </div>
        <?php if ($irAllowDl): ?>
        <a href="<?php echo htmlspecialchars($irDlUrl, ENT_QUOTES, 'UTF-8'); ?>" class="btn btn-sm btn-outline-success" download>
            <i class="fas fa-download me-1"></i><?php echo $_t('डाउनलोड', 'Download'); ?>
        </a>
        <?php else: ?>
        <span class="badge bg-warning-subtle text-warning-emphasis border border-warning">
            <i class="fas fa-eye me-1"></i><?php echo $_t('हेर्न मात्र', 'View only'); ?>
        </span>
        <?php endif; ?>
    </div>

    <div class="ir-viewer-head">
        <h1 class="h5 mb-1"><?php echo htmlspecialchars($irTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
        <?php if ($irDesc !== ''): ?>
        <p class="text-muted small mb-2"><?php echo nl2br(htmlspecialchars($irDesc, ENT_QUOTES, 'UTF-8')); ?></p>
        <?php endif; ?>
        <?php if ($irRestrict): ?>
        <div class="alert alert-warning py-2 px-3 small mb-2">
            <i class="fas fa-shield-halved me-1"></i>
            <?php echo $_t(
                'यो कागजात हेर्न मात्र हो। डाउनलोड अनुमति छैन। स्क्रिनसट/प्रतिलिपि रोक्न प्रयास गरिएको छ — तर १००% गारेन्टी सम्भव छैन।',
                'View-only document. Download is disabled. Copy/screenshot deterrence is applied — 100% prevention is not possible on the web.'
            ); ?>
        </div>
        <?php endif; ?>
        <?php if ($irViewerLabel ?? ''): ?>
        <div class="ir-watermark-label small text-muted"><?php echo htmlspecialchars((string) $irViewerLabel, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
    </div>

    <div class="ir-viewer-stage" id="irViewerStage">
        <?php if ($irIsPdf): ?>
        <iframe
            src="<?php echo htmlspecialchars($irFileUrl, ENT_QUOTES, 'UTF-8'); ?>#toolbar=<?php echo $irAllowDl ? '1' : '0'; ?>&navpanes=0"
            class="ir-doc-frame"
            title="<?php echo htmlspecialchars($irTitle, ENT_QUOTES, 'UTF-8'); ?>"
            sandbox="allow-scripts allow-same-origin"
        ></iframe>
        <?php elseif ($irIsImage): ?>
        <div class="ir-image-wrap">
            <img src="<?php echo htmlspecialchars($irFileUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($irTitle, ENT_QUOTES, 'UTF-8'); ?>" class="ir-doc-image" draggable="false">
        </div>
        <?php else: ?>
        <div class="alert alert-info">
            <?php echo $_t(
                'यो फाइल प्रकार ब्राउजरमा प्रत्यक्ष preview हुँदैन। PDF वा तस्बिर अपलोड गर्नुहोस्।',
                'This file type cannot be previewed inline. Please upload a PDF or image.'
            ); ?>
            <?php if ($irAllowDl): ?>
            <a href="<?php echo htmlspecialchars($irDlUrl, ENT_QUOTES, 'UTF-8'); ?>" class="alert-link"><?php echo $_t('डाउनलोड गर्नुहोस्', 'Download instead'); ?></a>
            <?php else: ?>
            <span class="d-block mt-1 small text-muted"><?php echo $_t('डाउनलोड पनि बन्द छ — admin लाई PDF मा बदल्न अनुरोध गर्नुहोस्।', 'Download is also disabled — ask admin to replace with PDF.'); ?></span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if ($irRestrict): ?>
        <div class="ir-overlay-watermark" aria-hidden="true"><?php echo htmlspecialchars((string) ($irViewerLabel ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
    </div>
</div>
