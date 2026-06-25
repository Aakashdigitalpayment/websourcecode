<?php
/**
 * Error Log Viewer — Admin बाट site errors हेर्ने / fix गर्ने
 * URL: admin/error-log.php
 */
$pageTitle   = 'Error Log';
$currentPage = 'error-log';
$activeGroup = 'prawidhi';
require_once __DIR__ . '/includes/admin-header.php';

/* Log file path */
$logFile = dirname(__DIR__) . '/logs/error.log';

/* Action: clear log */
if (isset($_POST['action']) && $_POST['action'] === 'clear' && checkCSRF()) {
    if (file_exists($logFile)) {
        file_put_contents($logFile, '');
        $flash = 'success';
    }
}

/* Read log */
$logContent = '';
$logSize    = 0;
if (file_exists($logFile)) {
    $logSize    = filesize($logFile);
    $logContent = file_get_contents($logFile);
    /* Last 200 lines मात्र */
    $logLines   = array_slice(explode("\n", $logContent), -200);
    $logContent = implode("\n", $logLines);
}
?>
<div class="container-fluid py-4">
    <?php echo adminPageHeader('Error Log', 'fa-bug', 'Site errors को latest 200 lines monitoring र quick fix guidance।'); ?>

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <p class="text-muted mb-0">Log size: <?php echo round($logSize/1024, 2); ?> KB
               | Last 200 lines मात्र देखाइएको</p>
        </div>
        <form method="post">
            <input type="hidden" name="action" value="clear">
            <button type="submit" class="btn btn-danger btn-sm"
                    onclick="return confirm('Log clear गर्ने?')">
                <i class="fas fa-trash me-1"></i>Log Clear गर्नुस्
            </button>
        </form>
    </div>

    <?php if (!file_exists($logFile) || $logSize == 0): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle me-2"></i>
        कुनै Error छैन। Site राम्रोसँग चलिरहेको छ।
    </div>
    <?php else: ?>
    <div class="card">
        <div class="card-header bg-dark text-white d-flex justify-content-between">
            <span><i class="fas fa-terminal me-2"></i>Error Output</span>
            <small class="text-muted"><?php echo date('Y-m-d H:i'); ?></small>
        </div>
        <div class="card-body p-0">
            <pre class="error-log-output"><?php
                /* Color-code PHP errors */
                $html = htmlspecialchars($logContent);
                $html = preg_replace('/\[error\]/i',   '<span style="color:#ff5f56">[error]</span>',   $html);
                $html = preg_replace('/\[warning\]/i', '<span style="color:#ffbd2e">[warning]</span>', $html);
                $html = preg_replace('/\[notice\]/i',  '<span style="color:#27c93f">[notice]</span>',  $html);
                $html = preg_replace('/Fatal|Error|Exception/i', '<strong style="color:#ff5f56">$0</strong>', $html);
                echo $html;
            ?></pre>
        </div>
    </div>
    <?php endif; ?>

    <!-- Common Fix Tips (collapsed by default for clean UI) -->
    <div class="d-flex align-items-center gap-2 mt-4 mb-2">
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#errorFixTips" aria-expanded="false" aria-controls="errorFixTips">
            <i class="fas fa-lightbulb me-1 text-warning"></i>सामान्य Error Fix गर्ने तरिका
        </button>
    </div>
    <div class="collapse" id="errorFixTips">
        <div class="card">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="p-3 border rounded h-100">
                            <strong class="text-danger"><i class="fas fa-database me-1"></i>Database Error</strong>
                            <p class="small mt-1 mb-0">includes/database.local.php मा DB_NAME, DB_USER, DB_PASS confirm गर्नुस्।
                            cPanel → MySQL Databases मा user permissions check गर्नुस्।</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 border rounded h-100">
                            <strong class="text-warning"><i class="fas fa-folder me-1"></i>File Permission Error</strong>
                            <p class="small mt-1 mb-0">assets/uploads/ र logs/ folder को permission 755 राख्नुस्।
                            cPanel → File Manager → Permission मा जानुस्।</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 border rounded h-100">
                            <strong class="text-info"><i class="fas fa-table me-1"></i>Table Missing Error</strong>
                            <p class="small mt-1 mb-0">Admin → DB Setup वा Run Migration मा जानुस्।
                            install.sql phpMyAdmin मा re-import गर्नुस्।</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/includes/admin-footer.php'; ?>
