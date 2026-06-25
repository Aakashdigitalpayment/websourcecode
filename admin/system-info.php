<?php
/**
 * Admin System Info Page
 * PHP version, extensions, server info — सबै एकै ठाउँमा
 * URL: /admin/system-info.php
 *
 * FIX: Added comprehensive error handling to prevent HTTP 500 errors.
 * Wrapped all logic in try-catch blocks so the page loads even if DB is unavailable.
 */

// Start output buffering early to catch any accidental output before headers
ob_start();

// Define admin page constant before including config
define('IS_ADMIN_PAGE', true);

// Safe include with error catching
try {
    require_once '../includes/config.php';
} catch (Throwable $e) {
    // If config.php itself fails, show a safe error page
    ob_end_clean();
    http_response_code(503);
    echo '<!DOCTYPE html><html><head><title>System Error</title></head><body>';
    echo '<h2>Configuration Error</h2>';
    echo '<p>includes/config.php लोड हुन सकेन। Database settings जाँच गर्नुहोस्।</p>';
    if (ini_get('display_errors')) {
        echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
    }
    echo '</body></html>';
    exit();
}

// Require admin login — safe redirect if not logged in
try {
    requireAdminLogin();
} catch (Throwable $e) {
    header('Location: ' . (defined('ADMIN_URL') ? ADMIN_URL : '../admin/') . 'index.php');
    exit();
}

// Get system compatibility info safely
$info = [];
$systemInfoError = null;
try {
    if (function_exists('getSystemCompatibilityInfo')) {
        $info = getSystemCompatibilityInfo();
    } else {
        // Fallback: build info manually if function is missing
        $info = [
            'php_version'      => PHP_VERSION,
            'is_compatible'    => version_compare(PHP_VERSION, '8.0', '>='),
            'is_recommended'   => version_compare(PHP_VERSION, '8.2', '>='),
            'os'               => PHP_OS,
            'sapi'             => PHP_SAPI,
            'max_upload'       => ini_get('upload_max_filesize'),
            'max_post'         => ini_get('post_max_size'),
            'memory_limit'     => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'extensions'       => [],
        ];
        // Check common extensions manually
        foreach (['pdo', 'pdo_mysql', 'mbstring', 'gd', 'curl', 'json', 'zip', 'openssl'] as $ext) {
            $info['extensions'][$ext] = [
                'name'    => $ext,
                'loaded'  => extension_loaded($ext),
                'version' => phpversion($ext) ?: '—',
            ];
        }
    }
} catch (Throwable $e) {
    $systemInfoError = $e->getMessage();
    $info = [
        'php_version'      => PHP_VERSION,
        'is_compatible'    => true,
        'is_recommended'   => false,
        'os'               => PHP_OS,
        'sapi'             => PHP_SAPI,
        'max_upload'       => ini_get('upload_max_filesize') ?: 'Unknown',
        'max_post'         => ini_get('post_max_size') ?: 'Unknown',
        'memory_limit'     => ini_get('memory_limit') ?: 'Unknown',
        'max_execution_time' => ini_get('max_execution_time') ?: 'Unknown',
        'extensions'       => [],
    ];
}

// Define version constants fallback if missing
if (!defined('REQUIRED_PHP_VERSION'))    define('REQUIRED_PHP_VERSION', '8.0');
if (!defined('RECOMMENDED_PHP_VERSION')) define('RECOMMENDED_PHP_VERSION', '8.2');

$pageTitle = 'System Information';

// Include admin header safely
try {
    require_once 'includes/admin-header.php';
} catch (Throwable $e) {
    ob_end_clean();
    echo '<!DOCTYPE html><html><head><title>Admin Header Error</title></head><body>';
    echo '<h2>Admin Header Load Error</h2>';
    echo '<p>admin/includes/admin-header.php लोड हुन सकेन।</p>';
    if (ini_get('display_errors')) echo '<pre>' . htmlspecialchars($e->getMessage()) . '</pre>';
    echo '</body></html>';
    exit();
}
?>

<div class="container-fluid py-4">

    <!-- Page Header -->
    <?php echo adminPageHeader('System Information','fa-server','Server, PHP, DB version र configuration।'); ?>

    <div class="row g-4 mb-4">

        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header gradient-card-header">
                    <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Version Requirements</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                    <table class="table-hover table table-sm mb-0">
                        <tr>
                            <td class="text-muted">Minimum</td>
                            <td><strong>PHP <?php echo REQUIRED_PHP_VERSION; ?>+</strong></td>
                            <td><?php echo $info['is_compatible'] ? '<span class="badge bg-success"><i class="fas fa-check me-1"></i>OK</span>' : '<span class="badge bg-danger"><i class="fas fa-times me-1"></i>FAIL</span>'; ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Recommended</td>
                            <td><strong>PHP <?php echo RECOMMENDED_PHP_VERSION; ?>+</strong></td>
                            <td><?php echo $info['is_recommended'] ? '<span class="badge bg-success"><i class="fas fa-check me-1"></i>OK</span>' : '<span class="badge bg-warning text-dark"><i class="fas fa-arrow-up me-1"></i>Update</span>'; ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Current</td>
                            <td><strong>PHP <?php echo htmlspecialchars($info['php_version']); ?></strong></td>
                            <td><span class="badge bg-primary"><i class="fas fa-circle-check me-1"></i>Running</span></td>
                        </tr>
                        <tr>
                            <td class="text-muted">OS</td>
                            <td colspan="2"><?php echo htmlspecialchars($info['os']); ?></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Server API</td>
                            <td colspan="2"><?php echo htmlspecialchars($info['sapi']); ?></td>
                        </tr>
                    </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-header gradient-card-header">
                    <h5 class="mb-0"><i class="fas fa-cog me-2"></i>PHP Settings</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                    <table class="table-hover table table-sm mb-0">
                        <tr>
                            <td class="text-muted">Upload Max Size</td>
                            <td><strong><?php echo htmlspecialchars($info['max_upload']); ?></strong></td>
                        </tr>
                        <tr>
                            <td class="text-muted">POST Max Size</td>
                            <td><strong><?php echo htmlspecialchars($info['max_post']); ?></strong></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Memory Limit</td>
                            <td><strong><?php echo htmlspecialchars($info['memory_limit']); ?></strong></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Max Exec Time</td>
                            <td><strong><?php echo htmlspecialchars($info['max_execution_time']); ?></strong></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Session Save</td>
                            <td><strong><?php echo ini_get('session.save_handler'); ?></strong></td>
                        </tr>
                        <tr>
                            <td class="text-muted">Error Display</td>
                            <td><strong><?php echo ini_get('display_errors') ? '<span class="text-warning">ON (dev)</span>' : '<span class="text-success">OFF (prod)</span>'; ?></strong></td>
                        </tr>
                    </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Required Extensions -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card">
                <div class="card-header gradient-card-header">
                    <h5 class="mb-0"><i class="fas fa-puzzle-piece me-2"></i>Required PHP Extensions</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Extension</th>
                                <th>काम</th>
                                <th>Version</th>
                                <th>स्थिति</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($info['extensions'] as $ext => $details): ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($ext); ?></code></td>
                                <td class="text-muted small"><?php echo htmlspecialchars($details['name']); ?></td>
                                <td class="text-muted small"><?php echo $details['loaded'] ? htmlspecialchars($details['version']) : '—'; ?></td>
                                <td>
                                    <?php if ($details['loaded']): ?>
                                        <span class="badge bg-success"><i class="fas fa-check me-1"></i>Loaded</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger"><i class="fas fa-times me-1"></i>Missing!</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Upgrade Guide -->
        <div class="col-md-6">
            <div class="card">
                <div class="card-header gradient-card-header">
                    <h5 class="mb-0"><i class="fas fa-arrow-up me-2"></i>PHP Upgrade Guide</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3 p-3 rounded si-upgrade-box si-upgrade-81">
                        <strong>8.0 → 8.1 upgrade गर्दा:</strong>
                        <ul class="mb-0 mt-1 small">
                            <li><code>includes/compatibility.php</code> मा <code>array_is_list()</code> polyfill हटाउन सकिन्छ</li>
                            <li>Enum type को support थपिन्छ</li>
                            <li>Readonly properties use गर्न मिल्छ</li>
                        </ul>
                    </div>
                    <div class="mb-3 p-3 rounded si-upgrade-box si-upgrade-82">
                        <strong>8.1 → 8.2 upgrade गर्दा:</strong>
                        <ul class="mb-0 mt-1 small">
                            <li>Dynamic properties deprecated — check गर्नुहोस्</li>
                            <li><code>"${foo}"</code> → <code>"{$foo}"</code> मा बदल्नुहोस्</li>
                            <li><code>utf8_encode()</code> deprecated: <code>mb_convert_encoding()</code> use गर्नुहोस्</li>
                        </ul>
                    </div>
                    <div class="p-3 rounded si-upgrade-box si-upgrade-83">
                        <strong>8.2 → 8.3 upgrade गर्दा:</strong>
                        <ul class="mb-0 mt-1 small">
                            <li>Code changes minimal हुन्छ</li>
                            <li><code>json_validate()</code> नयाँ function थपिन्छ</li>
                            <li>Typed class constants support थपिन्छ</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Compatibility File Location -->
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header gradient-card-header">
                    <h5 class="mb-0"><i class="fas fa-file-code me-2"></i>Version-Sensitive Files — यी files upgrade मा check गर्नुहोस्</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>File</th>
                                <th>के छ त्यहाँ</th>
                                <th>PHP Version Need</th>
                                <th>Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>includes/compatibility.php</code></td>
                                <td>Version check, polyfills, upgrade guide</td>
                                <td><span class="badge bg-secondary">7.4+</span></td>
                                <td class="text-success small"><i class="fas fa-star me-1"></i>Upgrade गर्दा पहिले यहाँ हेर्नुहोस्</td>
                            </tr>
                            <tr>
                                <td><code>includes/config.php</code></td>
                                <td>Database, session, site settings</td>
                                <td><span class="badge bg-primary">8.0+</span></td>
                                <td class="text-muted small">PDO use गर्छ — stable</td>
                            </tr>
                            <tr>
                                <td><code>application-tracker.php</code></td>
                                <td><code>match()</code> expression use गर्छ</td>
                                <td><span class="badge bg-warning text-dark">8.0+ required</span></td>
                                <td class="text-muted small">PHP 7.x मा downgrade गर्न switch() मा बदल्नुहोस्</td>
                            </tr>
                            <tr>
                                <td><code>includes/config.php</code> → <code>session_start()</code></td>
                                <td>Login session management</td>
                                <td><span class="badge bg-success">All versions</span></td>
                                <td class="text-muted small">Version independent</td>
                            </tr>
                            <tr>
                                <td>All <code>*.php</code> files</td>
                                <td><code>??</code> null coalescing operator</td>
                                <td><span class="badge bg-secondary">7.0+</span></td>
                                <td class="text-muted small">सबै modern PHP मा काम गर्छ</td>
                            </tr>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<?php require_once 'includes/admin-footer.php'; ?>
