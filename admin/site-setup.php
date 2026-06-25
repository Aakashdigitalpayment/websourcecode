<?php
/**
 * Admin: Site Setup Manager — site-setup.php
 * ===========================================
 * ⚠️ VIEW ONLY FROM ADMIN PANEL
 * Site configuration edit garnu cPanel File Manager bata garnus!
 *
 * Edit via cPanel:
 *   File Manager → public_html/setup-config.php
 *
 * Superadmin login: `includes/superadmin-config.local.php` (cPanel) मा मात्र।
 */

require_once dirname(__DIR__) . '/includes/superadmin-config.php';

$pageTitle = 'Site Setup Manager';
require_once 'includes/admin-header.php';

$db = getDB();

/* Lock file status */
$lockFile    = dirname(__DIR__) . '/.setup.lock';
$setupLocked = file_exists($lockFile);

/* setup-config.php owner email (for display only) */
$ownerEmail = '';
$configFile = dirname(__DIR__) . '/setup-config.php';
if (file_exists($configFile)) {
    $cfgContent = file_get_contents($configFile);
    if (preg_match("/SETUP_OWNER_EMAIL.*?'([^']+)'/", $cfgContent, $m)) {
        $ownerEmail = $m[1];
    }
}

/* ══════════════════════════════════════════════════════
   POST HANDLERS
══════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    /* ── १. Site Settings Update — setup.php र admin panel दुवैले same table use गर्छन् ── */
    if ($action === 'update_settings') {
        $updates = [
            'site_name'  => trim($_POST['site_name']   ?? ''),
            'email'      => trim($_POST['site_email']  ?? ''),
            'phone'      => trim($_POST['site_phone']  ?? ''),
            'address'    => trim($_POST['site_address']?? ''),
            'site_url'   => trim($_POST['site_url']    ?? ''),
            'card_prefix'=> trim($_POST['card_prefix'] ?? ''),
        ];
        $saved = 0;
        foreach ($updates as $key => $val) {
            if ($val !== '') {
                updateSetting($key, $val); /* config.php मा defined — INSERT or UPDATE */
                $saved++;
            }
        }
        setFlash($saved > 0 ? 'success' : 'warning',
                 $saved > 0 ? 'Site settings save भयो। दुवै ठाउँ (setup.php र admin panel) मा same data देखिनेछ।'
                            : 'केही पनि update भएन — fields खाली थिए।');
        redirect('site-setup.php');
    }

    /* ── २. Setup Lock Toggle (superadmin only) ── */
    if ($action === 'toggle_lock') {
        if (!$isSuperAdmin) {
            setFlash('error', 'यो कार्य केवल Superadmin ले गर्न सक्छ।');
        } else {
            if ($setupLocked) {
                @unlink($lockFile);
                setFlash('info', 'setup.php unlock भयो — public URL बाट फेरि setup गर्न सकिन्छ।');
            } else {
                file_put_contents($lockFile,
                    date('Y-m-d H:i:s') . ' — Admin Panel बाट lock: '
                    . ($_SESSION['admin_username'] ?? $_SESSION['admin_name'] ?? 'admin'));
                setFlash('success', 'setup.php lock भयो — public URL बाट access बन्द।');
            }
        }
        redirect('site-setup.php');
    }
}

/* ── Current site settings (setup.php र यहाँबाट — same DB table) ── */
$siteName    = getSetting('site_name', 'Aakash Cooperative');
$siteEmail   = getSetting('email', '');
$sitePhone   = getSetting('phone', '');
$siteAddress = getSetting('address', '');
$siteUrl     = getSetting('site_url', defined('SITE_URL') ? SITE_URL : '');
$cardPrefix  = getSetting('card_prefix', '');

/* ── Admin users list ── */
$admins = [];
try {
    $admins = $db->query("SELECT id, username, full_name, email, role, is_active FROM admin_users ORDER BY id")
                 ->fetchAll(PDO::FETCH_ASSOC);
    $admins = filter_out_file_managed_superadmin_rows($admins);
} catch (Exception $e) {}

?>

<div class="container-fluid py-4">

    <!-- ── Page Header ── -->
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <div>
            <h4 class="mb-0">
                <i class="fas fa-sliders me-2 ss-title-icon"></i>Site Setup Manager <span class="badge bg-secondary">View Only</span>
            </h4>
            <small class="text-muted">
                Site settings herna मात्र — edit garnu cPanel File Manager bata garnus!
            </small>
        </div>
    </div>

    <!-- ⚠️ VIEW ONLY WARNING -->
    <div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
        <i class="fas fa-exclamation-triangle"></i>
        <div>
            <strong>⚠️ View Only:</strong> 
            Site configuration edit garnu cPanel File Manager <code>(setup-config.php)</code> bata garnus!
            Admin panel ma sirf settings herna मात्र सकिन्छ।
        </div>
    </div>

    <!-- ── Flash ── -->
    <?php $flash = getFlash(); if ($flash): ?>
    <?php $flashTypeClass = in_array(($flash['type'] ?? ''), ['success', 'info', 'warning'], true) ? $flash['type'] : 'danger'; ?>
    <div class="alert alert-<?php echo $flashTypeClass; ?> alert-dismissible fade show mb-4">
        <i class="fas fa-<?php echo $flash['type'] === 'success' ? 'check-circle' : ($flash['type'] === 'info' ? 'info-circle' : ($flash['type'] === 'warning' ? 'exclamation-triangle' : 'exclamation-circle')); ?> me-2"></i>
        <?php echo htmlspecialchars($flash['message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- ══ STATUS CARDS ══ -->
    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 text-center p-3">
                <div class="ss-status-icon">
                    <?php echo $setupLocked
                        ? '<i class="fas fa-lock ss-icon-locked"></i>'
                        : '<i class="fas fa-lock-open ss-icon-unlocked"></i>'; ?>
                </div>
                <div class="fw-bold"><?php echo $setupLocked ? 'Setup Locked ✓' : '⚠️ Unlocked'; ?></div>
                <div class="text-muted small mt-1">
                    <?php echo $setupLocked ? 'setup.php public access बन्द' : 'setup.php public मा खुल्छ'; ?>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 text-center p-3">
                <div class="ss-status-icon ss-icon-info"><i class="fas fa-users"></i></div>
                <div class="fw-bold"><?php echo count($admins); ?> Admin Users</div>
                <div class="text-muted small mt-1">Database मा admin accounts</div>
                <?php if ($isSuperAdmin): ?>
                <a href="manage-admins.php" class="btn btn-sm btn-outline-primary mt-2">Manage</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 text-center p-3">
                <div class="ss-status-icon ss-icon-info">
                    <i class="fas fa-table-list"></i>
                </div>
                <div class="fw-bold">DB Setup</div>
                <div class="text-muted small mt-1">तालिका सूची र install.sql</div>
                <?php if ($isSuperAdmin): ?>
                <a href="db-setup.php" class="btn btn-sm btn-outline-primary mt-2">खोल्नुहोस्</a>
                <?php else: ?>
                <div class="text-muted small mt-2">Superadmin</div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-sm-6 col-lg-3">
            <div class="card border-0 shadow-sm h-100 text-center p-3">
                <div class="ss-status-icon ss-icon-warn"><i class="fas fa-landmark"></i></div>
                <div class="fw-bold text-truncate"><?php echo htmlspecialchars($siteName); ?></div>
                <div class="text-muted small mt-1">Current Site Name</div>
            </div>
        </div>
    </div>

    <div class="row g-4">

        <!-- ════ LEFT ════ -->
        <div class="col-lg-7">

            <!-- ── Admin accounts — एकै ठाउँ manage-admins.php ── -->
            <?php if ($isSuperAdmin): ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header py-2 d-flex align-items-center justify-content-between flex-wrap gap-2"
                     >
                    <span><i class="fas fa-users-gear me-2"></i>Admin खाताहरू</span>
                    <a href="manage-admins.php" class="btn btn-sm btn-light">
                        <i class="fas fa-arrow-right me-1"></i>Admin व्यवस्थापन खोल्नुहोस्
                    </a>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-2">
                        नयाँ admin/editor, पासवर्ड reset, सक्रिय/निष्क्रिय, मेटाउने — सबै <strong>Admin व्यवस्थापन</strong> पृष्ठमा मात्र गर्नुहोस्।
                        यहाँ दोहोरो फर्म हटाइएको छ ताकि एउटै प्रवाह र सुरक्षा नियम (<code>must_change_password</code> आदि) लागू होस्।
                    </p>
                    <?php if (!empty($admins)): ?>
                    <p class="small mb-0"><span class="text-muted">DB मा (फाइल-सुपरएडमिन बाहेक)</span>
                        <?php foreach ($admins as $a): ?>
                            <span class="badge bg-light text-dark border me-1"><?php echo htmlspecialchars($a['username'], ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php endforeach; ?>
                    </p>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="alert alert-secondary border-0 shadow-sm mb-4">
                <i class="fas fa-user-shield me-2"></i>
                <strong>Admin users</strong> थप्न वा बदल्न Superadmin ले <a href="manage-admins.php" class="alert-link">Admin व्यवस्थापन</a> खोल्नुपर्छ। तल <strong>Site Settings</strong> सबै authorized admin ले सम्पादन गर्न सकिन्छ।
            </div>
            <?php endif; ?>

            <!-- ── Site Settings ── -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header py-2">
                    <i class="fas fa-cog me-2"></i>
                    Site Settings
                    <span class="ss-site-settings-subtitle">
                        — setup.php र admin panel दुवैले यही table use गर्छन्
                    </span>
                </div>
                <div class="card-body">
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="fas fa-sync-alt me-1"></i>
                        यहाँ update गरेको setup.php मा र setup.php बाट update गरेको यहाँ — <strong>automatically same</strong> देखिन्छ। एउटै database table (<code>site_settings</code>) प्रयोग हुन्छ।
                    </div>
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_settings">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <div class="row g-3">
                            <div class="col-md-12">
                                <label class="form-label fw-semibold">
                                    Cooperative / Site नाम
                                </label>
                                <input type="text" name="site_name" class="form-control"
                                       value="<?php echo htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'); ?>"
                                       placeholder="जस्तै: बन्दना सहकारी संस्था लि.">
                            </div>
                            <div class="col-md-12">
                                <label class="form-label fw-semibold">Website / Domain URL</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-globe"></i></span>
                                    <input type="url" name="site_url" class="form-control"
                                           value="<?php echo htmlspecialchars($siteUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                           placeholder="https://yourdomain.com.np/">
                                </div>
                                <div class="form-text">
                                    Card footer मा website auto देखिन्छ, र Verification Code prefix पनि यही domain बाट derive हुन्छ।
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">इमेल</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                                    <input type="email" name="site_email" class="form-control"
                                           value="<?php echo htmlspecialchars($siteEmail, ENT_QUOTES, 'UTF-8'); ?>"
                                           placeholder="akashpame@gmail.com">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">फोन</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-phone"></i></span>
                                    <input type="text" name="site_phone" class="form-control"
                                           value="<?php echo htmlspecialchars($sitePhone, ENT_QUOTES, 'UTF-8'); ?>"
                                           placeholder="०१-२३४५६७८">
                                </div>
                            </div>
                            <div class="col-md-12">
                                <label class="form-label fw-semibold">ठेगाना</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-map-marker-alt"></i></span>
                                    <input type="text" name="site_address" class="form-control"
                                           value="<?php echo htmlspecialchars($siteAddress, ENT_QUOTES, 'UTF-8'); ?>"
                                           placeholder="जस्तै: काठमाण्डौ, नेपाल">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">Card Prefix (Optional)</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-id-card"></i></span>
                                    <input type="text" name="card_prefix" class="form-control"
                                           value="<?php echo htmlspecialchars($cardPrefix, ENT_QUOTES, 'UTF-8'); ?>"
                                           maxlength="10"
                                           placeholder="जस्तै: BAN (3 अक्षर)">
                                </div>
                                <div class="form-text">खाली राखे domain बाट auto 3-letter prefix हुन्छ।</div>
                            </div>
                        </div>
                        <div class="mt-3 d-flex gap-2 flex-wrap">
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="fas fa-save me-1"></i>Update गर्नुहोस्
                            </button>
                            <a href="settings.php" class="btn btn-outline-secondary">
                                <i class="fas fa-sliders me-1"></i>Full Settings Page
                            </a>
                        </div>
                    </form>
                </div>
            </div>

        </div><!-- /left col -->

        <!-- ════ RIGHT ════ -->
        <div class="col-lg-5">

            <!-- ── Database — विस्तृत सूची `db-setup.php` मा मात्र (दोहोरो हटाइयो) ── -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header py-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <span><i class="fas fa-database me-2"></i>Database & migration</span>
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-3">
                        सबै तालिकाको स्थिति, <code>install.sql</code> चलाउने, SQL upload, schema lock reset —
                        <strong>DB Setup</strong> पृष्ठमा एउटै ठाउँमा छ। यहाँको छोटो सूची हटाइएको छ ताकि <code>db-setup.php</code> नै स्रोत हुन्।
                    </p>
                    <?php if ($isSuperAdmin): ?>
                    <a href="db-setup.php" class="btn btn-success btn-sm w-100 mb-2">
                        <i class="fas fa-table-list me-1"></i>DB Setup खोल्नुहोस्
                    </a>
                    <a href="run-migration.php" class="btn btn-outline-secondary btn-sm w-100">
                        <i class="fas fa-terminal me-1"></i>Advanced Migration / SQL Upload
                    </a>
                    <?php else: ?>
                    <p class="text-muted small mb-0">
                        <i class="fas fa-shield-alt me-1"></i>DB Setup र install.sql चलाउन Superadmin चाहिन्छ।
                    </p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── setup.php Lock / Unlock ── -->
            <div class="card border-0 shadow-sm mb-4 ss-lock-card <?php echo $setupLocked ? 'is-locked' : 'is-unlocked'; ?>">
                <div class="card-header py-2"
                     >
                    <i class="fas fa-<?php echo $setupLocked ? 'lock' : 'lock-open'; ?> me-2"></i>
                    setup.php — <?php echo $setupLocked ? 'Locked ✓' : '⚠️ Unlocked'; ?>
                </div>
                <div class="card-body">
                    <?php if ($setupLocked): ?>
                    <p class="text-muted small mb-3">
                        <i class="fas fa-shield-halved me-1 text-success"></i>
                        setup.php public URL बाट access बन्द छ। सुरक्षित अवस्था।<br>
                        Re-setup चाहिन्छ भने Superadmin ले unlock गर्न सक्छ।
                    </p>
                    <?php else: ?>
                    <div class="alert alert-danger py-2 small mb-3">
                        <i class="fas fa-exclamation-triangle me-1"></i>
                        <strong>Warning:</strong> setup.php unlock छ!
                        जो कोहीले पनि public URL बाट admin बनाउन सक्छ।
                        तुरुन्त lock गर्नुहोस्!
                    </div>
                    <?php endif; ?>

                    <?php if ($isSuperAdmin): ?>
                    <form method="POST"
                          onsubmit="return confirm('<?php echo $setupLocked
                              ? 'setup.php unlock गर्ने? Public URL बाट access खुल्नेछ!'
                              : '⚠️ setup.php lock गर्ने?'; ?>');">
                        <input type="hidden" name="action" value="toggle_lock">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <button type="submit"
                                class="btn btn-sm <?php echo $setupLocked ? 'btn-outline-danger' : 'btn-danger'; ?>">
                            <i class="fas fa-<?php echo $setupLocked ? 'lock-open' : 'lock'; ?> me-1"></i>
                            <?php echo $setupLocked
                                ? 'Unlock गर्नुहोस् (Public Access)'
                                : 'Lock गर्नुहोस् (बन्द गर्नुहोस्)'; ?>
                        </button>
                    </form>
                    <?php else: ?>
                    <div class="text-muted small">
                        <i class="fas fa-info-circle me-1"></i>Lock/Unlock: Superadmin मात्र
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($ownerEmail) && $ownerEmail !== ''): ?>
                    <hr class="my-2">
                    <div class="small text-muted">
                        <i class="fas fa-envelope me-1"></i>
                        setup-config.php email:
                        <strong><?php echo htmlspecialchars($ownerEmail, ENT_QUOTES, 'UTF-8'); ?></strong>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── Quick Links ── -->
            <div class="card border-0 shadow-sm">
                <div class="card-header py-2">
                    <i class="fas fa-link me-2"></i>Quick Links
                </div>
                <div class="list-group list-group-flush small">
                    <?php if ($isSuperAdmin): ?>
                    <a href="manage-admins.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-users-gear me-2 text-primary"></i>Admin User Management
                    </a>
                    <?php endif; ?>
                    <a href="settings.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-sliders me-2 text-warning"></i>Full Site Settings
                    </a>
                    <?php if ($isSuperAdmin): ?>
                    <a href="db-setup.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-table-list me-2 text-success"></i>DB Setup (तालिका + install.sql)
                    </a>
                    <?php endif; ?>
                    <a href="run-migration.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-database me-2 text-success"></i>Database Migration
                    </a>
                    <a href="site-health.php" class="list-group-item list-group-item-action">
                        <i class="fas fa-heart-pulse me-2 text-danger"></i>Site Health Check
                    </a>
                    <a href="../setup.php" target="_blank" class="list-group-item list-group-item-action">
                        <i class="fas fa-external-link-alt me-2 text-secondary"></i>
                        setup.php Public URL
                        <?php echo $setupLocked ? '<span class="badge bg-success ms-1">Locked</span>' : '<span class="badge bg-danger ms-1">Unlocked ⚠️</span>'; ?>
                    </a>
                </div>
            </div>

        </div><!-- /right col -->
    </div>
</div><!-- /container-fluid -->

<?php require_once 'includes/admin-footer.php'; ?>
