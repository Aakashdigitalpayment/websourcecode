<?php
/**
 * Admin: About Page Settings — History Section Photo
 * File: admin/about-settings.php
 *
 * Admin ले यहाँबाट:
 *   - History section को side photo upload गर्न सक्छ
 *   - Static bank icon हटाइएको छ — photo upload feature थपिएको छ
 *
 * Issue #14: History section मा photo upload feature
 */

define('IS_ADMIN_PAGE', true);
require_once '../includes/config.php';
requireAdminLogin();

  /* ── Early CSRF Protection ── */
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCSRFToken()) {
      setFlash('error', 'सुरक्षा जाँच असफल। कृपया पुनः प्रयास गर्नुहोस्।');
      redirect($_SERVER['HTTP_REFERER'] ?? ADMIN_URL . 'dashboard.php');
  }
  if (empty($csrfToken)) $csrfToken = generateCSRFToken();
  $db = getDB();
$errors   = [];
$messages = [];

/* -------------------------------------------------------
   POST handler — photo upload / remove
------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = clean_text($_POST['action'] ?? '');

    /* History photo upload */
    if ($action === 'upload_history_photo') {
        if (!empty($_FILES['history_photo']['name'])) {
            $file      = $_FILES['history_photo'];
            $ext       = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed   = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $maxSize   = 5 * 1024 * 1024; /* 5MB */

            if (!in_array($ext, $allowed)) {
                $errors[] = 'Only JPG, PNG, GIF, WebP images allowed.';
            } elseif ($file['size'] > $maxSize) {
                $errors[] = 'File size must be under 5MB.';
            } elseif ($file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Upload error: ' . $file['error'];
            } elseif (@getimagesize($file['tmp_name']) === false) {
                $errors[] = 'मान्य image file मात्र अपलोड गर्नुहोस्।';
            } else {
                /* Upload directory — assets/uploads/about/ */
                $uploadDir = ROOT_PATH . 'assets/uploads/about/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                /* Old photo delete गर्नुहोस् */
                $oldPhoto = getSetting('history_photo', '');
                if ($oldPhoto && file_exists(ROOT_PATH . $oldPhoto)) {
                    @unlink(ROOT_PATH . $oldPhoto);
                }

                /* New filename — unique */
                $filename  = 'history_' . time() . '.' . $ext;
                $targetPath = $uploadDir . $filename;

                if (move_uploaded_file($file['tmp_name'], $targetPath)) {
                    $relativePath = 'assets/uploads/about/' . $filename;
                    updateSetting('history_photo', $relativePath);
                    setFlash('success', 'History photo upload भयो।');
                    redirect('about-settings.php');
                } else {
                    $errors[] = 'File save गर्न सकिएन। Directory permissions जाँच गर्नुहोस्।';
                }
            }
        } else {
            $errors[] = 'कृपया एउटा photo छान्नुहोस्।';
        }
    }

    /* History photo remove गर्नुहोस् */
    if ($action === 'remove_history_photo') {
        $oldPhoto = getSetting('history_photo', '');
        if ($oldPhoto && file_exists(ROOT_PATH . $oldPhoto)) {
            @unlink(ROOT_PATH . $oldPhoto);
        }
        updateSetting('history_photo', '');
        setFlash('success', 'History photo हटाइयो।');
        redirect('about-settings.php');
    }

    /* History text update */
    if ($action === 'update_history_text') {
        $contentNp = $_POST['history_content_np'] ?? '';
        $contentEn = $_POST['history_content_en'] ?? '';
        $year      = clean_text($_POST['established_year'] ?? '');

        updateSetting('history_content_np', $contentNp);
        updateSetting('history_content_en', $contentEn);
        if ($year) updateSetting('established_year', $year);

        setFlash('success', 'History content अपडेट भयो।');
        redirect('about-settings.php');
    }
}

/* -------------------------------------------------------
   Load current settings
------------------------------------------------------- */
$historyPhoto   = getSetting('history_photo', '');
$historyNp      = getSetting('history_content_np', '');
$historyEn      = getSetting('history_content_en', '');
$establishedYear = getSetting('established_year', '२०७५');
$panel = (string)($_GET['panel'] ?? 'photo');
if (!in_array($panel, ['photo', 'content'], true)) {
    $panel = 'photo';
}

$pageTitle = 'About Page Settings';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';
?>

<div class="container-fluid py-4">

    <?php
    echo adminPageHeader('About Page Settings','fa-building-columns','History section photo र content manage गर्नुहोस्।');
    if ($flash = getFlash()):
    ?>
    <div class="alert alert-<?php echo $flash['type']==='success'?'success':'danger'; ?> alert-dismissible fade show mb-3"><i class="fas fa-<?php echo $flash['type']==='success'?'check-circle':'exclamation-circle'; ?> me-2"></i><?php echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <ul class="nav admin-nav-tabs mb-4" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $panel === 'photo' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#about-photo-tab" type="button" role="tab">
                <i class="fas fa-image me-1"></i> फोटो व्यवस्थापन
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $panel === 'content' ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#about-content-tab" type="button" role="tab">
                <i class="fas fa-file-pen me-1"></i> कन्टेन्ट व्यवस्थापन
            </button>
        </li>
    </ul>

    <div class="tab-content about-settings-page">
        <div class="tab-pane fade <?php echo $panel === 'photo' ? 'show active' : ''; ?>" id="about-photo-tab" role="tabpanel">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-image me-2"></i>History Section Photo</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted small">
                        यो photo <code>about.php</code> को History section को बाँया side मा देखिन्छ।
                        <br>Photo नभए default icon देखिन्छ।
                        <br><strong>Recommended size:</strong> 600×450px वा त्यसभन्दा ठूलो।
                    </p>

                    <!-- Current Photo Preview -->
                    <?php if ($historyPhoto && file_exists(ROOT_PATH . $historyPhoto)): ?>
                    <div class="mb-3 text-center">
                        <img src="<?php echo SITE_URL . $historyPhoto; ?>"
                             alt="History Photo"
                             class="img-fluid rounded shadow-sm"
                             style="max-height:200px;object-fit:cover;">
                        <div class="mt-2">
                            <form method="POST" action="" class="d-inline"
                                  onsubmit="return confirm('History photo हटाउनुहोस्?');">
                                <input type="hidden" name="action" value="remove_history_photo">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">
                                    <i class="fas fa-trash me-1"></i>Photo हटाउनुहोस्
                                </button>
                            </form>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle me-2"></i>
                        अहिले History section मा default icon देखिन्छ।
                        तल photo upload गर्नुहोस्।
                    </div>
                    <?php endif; ?>

                    <!-- Upload Form -->
                    <form method="POST" action="" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="upload_history_photo">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                        <div class="mb-3">
                            <label class="form-label">
                                <?php echo $historyPhoto ? 'नयाँ Photo छान्नुहोस्' : 'Photo Upload गर्नुहोस्'; ?>
                            </label>
                            <input type="file" name="history_photo" class="form-control"
                                   accept=".jpg,.jpeg,.png,.gif,.webp" required>
                            <small class="text-muted">JPG, PNG, WebP — max 5MB</small>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-upload me-1"></i>
                            <?php echo $historyPhoto ? 'Photo बदल्नुहोस्' : 'Upload गर्नुहोस्'; ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="tab-pane fade <?php echo $panel === 'content' ? 'show active' : ''; ?>" id="about-content-tab" role="tabpanel">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-file-pen me-2"></i>History Content</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="update_history_text">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                        <div class="mb-3">
                            <label class="form-label">स्थापना वर्ष / Established Year</label>
                            <input type="text" name="established_year" class="form-control"
                                   value="<?php echo htmlspecialchars($establishedYear); ?>"
                                   placeholder="जस्तै: २०७५">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">History Content — नेपाली</label>
                            <textarea name="history_content_np" class="form-control" rows="6"
                                      placeholder="हाम्रो सहकारीको इतिहास नेपालीमा..."><?php echo htmlspecialchars($historyNp); ?></textarea>
                            <small class="text-muted">HTML allowed: &lt;p&gt;, &lt;strong&gt;, &lt;ul&gt;, &lt;li&gt;</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">History Content — English</label>
                            <textarea name="history_content_en" class="form-control" rows="6"
                                      placeholder="Our cooperative's history in English..."><?php echo htmlspecialchars($historyEn); ?></textarea>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>Content सुरक्षित गर्नुहोस्
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/admin-footer.php'; ?>
