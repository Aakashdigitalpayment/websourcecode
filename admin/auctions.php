<?php
$pageTitle   = 'लिलामी व्यवस्थापन';
$currentPage = 'auctions';
require_once 'includes/admin-header.php';
require_once 'includes/admin-ui.php';

$db = getDB();
checkCSRF();

require_once __DIR__ . '/../includes/auction-tables.php';
ensureAuctionTables($db);

/* ─────────────────────────────────
   STATUS + LABEL maps
───────────────────────────────── */
$statusLabels = [
    'upcoming'  => ['np' => 'आगामी',   'class' => 'info'],
    'ongoing'   => ['np' => 'जारी',    'class' => 'warning'],
    'completed' => ['np' => 'सम्पन्न', 'class' => 'success'],
    'cancelled' => ['np' => 'रद्द',    'class' => 'danger'],
];

/* ─────────────────────────────────
   POST HANDLERS
───────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        /* ── Save (add / edit) ── */
        if (isset($_POST['save_auction'])) {
            $id              = (int)($_POST['id'] ?? 0);
            $title           = clean_text($_POST['title'] ?? '');
            $title_en        = clean_text($_POST['title_en'] ?? '');
            $description     = clean_text($_POST['description'] ?? '');
            $description_en  = clean_text($_POST['description_en'] ?? '');
            $property_type   = clean_text($_POST['property_type'] ?? '');
            $location        = clean_text($_POST['location'] ?? '');
            $google_map_link = clean_text($_POST['google_map_link'] ?? '');
            $google_map_embed = $_POST['google_map_embed'] ?? '';
            $area_bigha      = floatval($_POST['area_bigha'] ?? 0);
            $area_ropani     = floatval($_POST['area_ropani'] ?? 0);
            $area_aana       = floatval($_POST['area_aana'] ?? 0);
            $area_paisa      = floatval($_POST['area_paisa'] ?? 0);
            $area            = clean_text($_POST['area'] ?? '');
            $minimum_price   = floatval($_POST['minimum_price'] ?? 0);
            $auction_date    = clean_text($_POST['auction_date'] ?? '');
            $auction_time    = clean_text($_POST['auction_time'] ?? '');
            $contact_person  = clean_text($_POST['contact_person'] ?? '');
            $contact_phone   = preg_replace('/[^0-9]/', '', clean_text($_POST['contact_phone'] ?? '', 20));
            $status          = clean_text($_POST['status'] ?? 'upcoming');
            $is_active       = isset($_POST['is_active']) ? 1 : 0;

            if (!isset($statusLabels[$status])) $status = 'upcoming';

            /* Main image upload */
            $image = clean_text($_POST['existing_image'] ?? '');
            if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === 0) {
                $up = uploadFile($_FILES['image'], 'auctions');
                if ($up['success']) $image = $up['path'];
            }

            /* Additional images upload */
            $existingImages   = json_decode($_POST['existing_images'] ?? '[]', true) ?: [];
            $additionalImages = $existingImages;
            if (!empty($_FILES['additional_images']['name']) && is_array($_FILES['additional_images']['name'])) {
                foreach ($_FILES['additional_images']['name'] as $i => $fname) {
                    if ($_FILES['additional_images']['error'][$i] === 0) {
                        $tmp = [
                            'name'     => $fname,
                            'type'     => $_FILES['additional_images']['type'][$i],
                            'tmp_name' => $_FILES['additional_images']['tmp_name'][$i],
                            'error'    => $_FILES['additional_images']['error'][$i],
                            'size'     => $_FILES['additional_images']['size'][$i],
                        ];
                        $up = uploadFile($tmp, 'auctions');
                        if ($up['success']) $additionalImages[] = $up['path'];
                    }
                }
            }
            $imagesJson = json_encode($additionalImages);

            /* Document upload (PDF / Word) */
            $document = clean_text($_POST['existing_document'] ?? '');
            if (!empty($_FILES['document']['name']) && $_FILES['document']['error'] === 0) {
                $docExt = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
                if (in_array($docExt, ['pdf', 'doc', 'docx'], true)) {
                    $up = uploadFile($_FILES['document'], 'auctions');
                    if ($up['success']) {
                        $document = $up['path'];
                    } else {
                        setFlash('error', 'Document upload असफल: ' . ($up['message'] ?? 'Invalid file'));
                        redirect('auctions.php' . ($id > 0 ? ('?action=edit&id=' . $id) : '?action=add'));
                    }
                } else {
                    setFlash('error', 'Document का लागि केवल PDF, DOC, DOCX मात्र अनुमति छ।');
                    redirect('auctions.php' . ($id > 0 ? ('?action=edit&id=' . $id) : '?action=add'));
                }
            }

            /* Build area text from structured fields */
            $areaParts = [];
            if ($area_bigha > 0)  $areaParts[] = number_format($area_bigha, 2, '.', '') . ' बिगाहा';
            if ($area_ropani > 0) $areaParts[] = number_format($area_ropani, 2, '.', '') . ' रोपनी';
            if ($area_aana > 0)   $areaParts[] = number_format($area_aana, 2, '.', '') . ' आना';
            if ($area_paisa > 0)  $areaParts[] = number_format($area_paisa, 2, '.', '') . ' पैसा';
            if (!empty($areaParts)) $area = implode(' ', $areaParts);

            if ($id > 0) {
                $stmt = $db->prepare("UPDATE auction_notices SET title=?,title_en=?,description=?,description_en=?,property_type=?,location=?,google_map_link=?,google_map_embed=?,area_bigha=?,area_ropani=?,area_aana=?,area_paisa=?,area=?,minimum_price=?,auction_date=?,auction_time=?,contact_person=?,contact_phone=?,image=?,images=?,document=?,status=?,is_active=?,updated_at=NOW() WHERE id=?");
                $stmt->execute([$title,$title_en,$description,$description_en,$property_type,$location,$google_map_link,$google_map_embed,$area_bigha,$area_ropani,$area_aana,$area_paisa,$area,$minimum_price,$auction_date,$auction_time,$contact_person,$contact_phone,$image,$imagesJson,$document,$status,$is_active,$id]);
                setFlash('success', 'लिलामी सूचना अपडेट भयो।');
            } else {
                $tracking = 'AUC-' . str_pad(time() % 1000000, 6, '0', STR_PAD_LEFT) . '-' . date('ym');
                $stmt = $db->prepare("INSERT INTO auction_notices (tracking_number,title,title_en,description,description_en,property_type,location,google_map_link,google_map_embed,area_bigha,area_ropani,area_aana,area_paisa,area,minimum_price,auction_date,auction_time,contact_person,contact_phone,image,images,document,status,is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->execute([$tracking,$title,$title_en,$description,$description_en,$property_type,$location,$google_map_link,$google_map_embed,$area_bigha,$area_ropani,$area_aana,$area_paisa,$area,$minimum_price,$auction_date,$auction_time,$contact_person,$contact_phone,$image,$imagesJson,$document,$status,$is_active]);
                setFlash('success', 'नयाँ लिलामी सूचना थपियो।');
            }
            redirect('auctions.php');
        }

        /* ── Delete ── */
        if (isset($_POST['delete_auction'])) {
            $id = (int)($_POST['auction_id'] ?? 0);
            $db->prepare("DELETE FROM auction_notices WHERE id = ?")->execute([$id]);
            setFlash('success', 'लिलामी मेटाइयो।');
            redirect('auctions.php');
        }

        /* ── Quick status update ── */
        if (isset($_POST['quick_status'])) {
            $id = (int)($_POST['auction_id'] ?? 0);
            $st = clean_text($_POST['status'] ?? 'upcoming');
            if (!isset($statusLabels[$st])) $st = 'upcoming';
            $db->prepare("UPDATE auction_notices SET status=?, updated_at=NOW() WHERE id=?")->execute([$st, $id]);
            setFlash('success', 'स्थिति अपडेट भयो।');
            redirect('auctions.php');
        }

    } catch (Exception $e) {
        setFlash('error', 'कार्य गर्दा त्रुटि भयो: ' . $e->getMessage());
        redirect('auctions.php');
    }
}

/* ─────────────────────────────────
   ROUTING
───────────────────────────────── */
$action = $_GET['action'] ?? 'list';
if (!in_array($action, ['list', 'add', 'edit'], true)) {
    $action = 'list';
}
$id     = (int)($_GET['id'] ?? 0);

/* ══════════════════════════════════════════════════════════
   ADD / EDIT FORM
   ══════════════════════════════════════════════════════════ */
if ($action === 'edit' || $action === 'add') {
    $auction = null;
    if ($action === 'edit' && $id > 0) {
        try {
            $s = $db->prepare("SELECT * FROM auction_notices WHERE id = ?");
            $s->execute([$id]);
            $auction = $s->fetch();
        } catch (Throwable $e) { error_log("[auctions.php] " . $e->getMessage()); }
        if (!$auction) {
            setFlash('error', 'लिलामी फेला परेन।');
            redirect('auctions.php');
        }
    }
    $formTitle = $auction ? 'लिलामी सम्पादन' : 'नयाँ लिलामी थप्नुहोस्';
    $isEdit    = (bool)$auction;
?>


<div class="card admin-table-card mb-4">
    <div class="card-header gradient-card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <h5 class="mb-0"><i class="fas fa-gavel me-2"></i><?php echo $formTitle; ?></h5>
        <a href="auctions.php" class="btn btn-outline-light btn-sm"><i class="fas fa-arrow-left me-1"></i>सूचीमा फर्कनुहोस्</a>
    </div>
    <div class="card-body">
        <?php if ($isEdit): ?>
        <div class="alert alert-light border d-flex align-items-center gap-3 py-2 mb-4">
            <span class="text-muted small">Tracking No:</span>
            <strong class="font-monospace text-primary"><?php echo htmlspecialchars($auction['tracking_number'] ?? 'N/A'); ?></strong>
            <span class="badge bg-<?php echo $statusLabels[$auction['status']]['class'] ?? 'secondary'; ?>">
                <?php echo $statusLabels[$auction['status']]['np'] ?? htmlspecialchars($auction['status']); ?>
            </span>
        </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="auctionForm" class="needs-validation" novalidate>
            <?php echo csrfField(); ?>
            <input type="hidden" name="save_auction" value="1">
            <input type="hidden" name="id" value="<?php echo (int)($auction['id'] ?? 0); ?>">
            <input type="hidden" name="existing_image" value="<?php echo htmlspecialchars($auction['image'] ?? ''); ?>">
            <input type="hidden" name="existing_images" value="<?php echo htmlspecialchars($auction['images'] ?? '[]'); ?>">
            <input type="hidden" name="existing_document" value="<?php echo htmlspecialchars($auction['document'] ?? ''); ?>">

            <!-- ① मुख्य जानकारी -->
            <div class="info-section">
                <h6 class="section-heading"><i class="fas fa-file-alt"></i> मुख्य जानकारी</h6>
                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">शीर्षक (नेपाली) <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" required
                               value="<?php echo htmlspecialchars($auction['title'] ?? ''); ?>"
                               placeholder="उदा: काठमाडौं महानगर-१२ को लिलामी जग्गा">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Title (English) <small class="text-muted">(वैकल्पिक)</small></label>
                        <input type="text" name="title_en" class="form-control"
                               value="<?php echo htmlspecialchars($auction['title_en'] ?? ''); ?>"
                               placeholder="Land Auction - Kathmandu-12">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">सम्पत्ति प्रकार</label>
                        <select name="property_type" class="form-select">
                            <option value="">-- छान्नुहोस् --</option>
                            <?php
                            $ptypes = ['जग्गा','घर तथा जग्गा','अपार्टमेन्ट','व्यावसायिक घर','गाडी','मेशिनरी','अन्य'];
                            $curPt  = $auction['property_type'] ?? '';
                            foreach ($ptypes as $pt): ?>
                            <option value="<?php echo $pt; ?>" <?php echo $curPt === $pt ? 'selected' : ''; ?>><?php echo $pt; ?></option>
                            <?php endforeach; ?>
                            <?php if ($curPt && !in_array($curPt, $ptypes)): ?>
                            <option value="<?php echo htmlspecialchars($curPt); ?>" selected><?php echo htmlspecialchars($curPt); ?></option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-semibold">स्थान / ठेगाना</label>
                        <input type="text" name="location" class="form-control"
                               value="<?php echo htmlspecialchars($auction['location'] ?? ''); ?>"
                               placeholder="उदा: काठमाडौं महानगरपालिका वडा नं. १२">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">विवरण (नेपाली)</label>
                        <textarea name="description" class="form-control" rows="4"
                                  placeholder="सम्पत्तिको विस्तृत विवरण, सीमाना, विशेषता..."><?php echo htmlspecialchars($auction['description'] ?? ''); ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Description (English) <small class="text-muted">(वैकल्पिक)</small></label>
                        <textarea name="description_en" class="form-control" rows="4"
                                  placeholder="Detailed property description..."><?php echo htmlspecialchars($auction['description_en'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- ② जग्गाको क्षेत्रफल — structured -->
            <div class="info-section">
                <h6 class="section-heading"><i class="fas fa-ruler-combined"></i> जग्गाको क्षेत्रफल (Terai / Hilly मापन)</h6>
                <div class="row g-3">
                    <div class="col-6 col-md-3">
                        <label class="form-label fw-semibold">बिगाहा <span class="area-unit">(Bigha)</span></label>
                        <input type="number" name="area_bigha" class="form-control" min="0" step="0.25"
                               value="<?php echo htmlspecialchars($auction['area_bigha'] ?? '0'); ?>"
                               placeholder="0">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label fw-semibold">रोपनी <span class="area-unit">(Ropani)</span></label>
                        <input type="number" name="area_ropani" class="form-control" min="0" step="0.5"
                               value="<?php echo htmlspecialchars($auction['area_ropani'] ?? '0'); ?>"
                               placeholder="0">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label fw-semibold">आना <span class="area-unit">(Aana)</span></label>
                        <input type="number" name="area_aana" class="form-control" min="0" max="16" step="1"
                               value="<?php echo htmlspecialchars($auction['area_aana'] ?? '0'); ?>"
                               placeholder="0">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label fw-semibold">पैसा <span class="area-unit">(Paisa)</span></label>
                        <input type="number" name="area_paisa" class="form-control" min="0" max="4" step="1"
                               value="<?php echo htmlspecialchars($auction['area_paisa'] ?? '0'); ?>"
                               placeholder="0">
                    </div>
                    <div class="col-12">
                        <label class="form-label fw-semibold">अतिरिक्त क्षेत्रफल विवरण <small class="text-muted">(माथि नभरेको भए यहाँ भर्नुहोस्)</small></label>
                        <input type="text" name="area" class="form-control" id="areaTextInput"
                               value="<?php echo htmlspecialchars($auction['area'] ?? ''); ?>"
                               placeholder="उदा: ५ आना २ पैसा / ३ रोपनी">
                        <small class="text-muted"><i class="fas fa-info-circle me-1"></i>माथि बिगाहा/रोपनी/आना/पैसा भर्नुभयो भने Save गर्दा automatically update हुन्छ।</small>
                    </div>
                </div>
            </div>

            <!-- ③ मूल्य, मिति र सम्पर्क -->
            <div class="info-section">
                <h6 class="section-heading"><i class="fas fa-calendar-alt"></i> मूल्य, मिति र सम्पर्क</h6>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">न्यूनतम मूल्य (रु.) <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text fw-bold">रु.</span>
                            <input type="number" name="minimum_price" class="form-control" min="0" step="1000"
                                   value="<?php echo htmlspecialchars($auction['minimum_price'] ?? ''); ?>"
                                   placeholder="5000000">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">लिलामी मिति</label>
                        <div class="input-group">
                            <input type="text" name="auction_date" class="form-control nepali-datepicker"
                                   placeholder="YYYY-MM-DD"
                                   value="<?php echo htmlspecialchars($auction['auction_date'] ?? ''); ?>">
                            <span class="input-group-text"><i class="fas fa-calendar-alt"></i></span>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">समय</label>
                        <?php $auctionTimeValue = trim((string)($auction['auction_time'] ?? '')); $auctionTimeOptions = function_exists('getOfficeTimeOptions') ? getOfficeTimeOptions(30) : []; ?>
                        <select name="auction_time" class="form-select">
                            <option value="">— समय छान्नुहोस् —</option>
                            <?php foreach ($auctionTimeOptions as $optVal => $optLabel): ?>
                            <option value="<?php echo htmlspecialchars($optVal, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $auctionTimeValue === $optVal ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($optLabel, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                            <?php endforeach; ?>
                            <?php if ($auctionTimeValue !== '' && !isset($auctionTimeOptions[$auctionTimeValue])): ?>
                            <option value="<?php echo htmlspecialchars($auctionTimeValue, ENT_QUOTES, 'UTF-8'); ?>" selected>
                                <?php echo htmlspecialchars($auctionTimeValue, ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                            <?php endif; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">सम्पर्क व्यक्ति</label>
                        <input type="text" name="contact_person" class="form-control"
                               value="<?php echo htmlspecialchars($auction['contact_person'] ?? ''); ?>"
                               placeholder="जिम्मेवार कर्मचारीको नाम">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">सम्पर्क फोन</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fas fa-phone"></i></span>
                            <input type="text" name="contact_phone" class="form-control"
                                   value="<?php echo htmlspecialchars($auction['contact_phone'] ?? ''); ?>"
                                   placeholder="98XXXXXXXX">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">स्थिति</label>
                        <select name="status" class="form-select">
                            <?php foreach ($statusLabels as $key => $lbl): ?>
                            <option value="<?php echo $key; ?>"
                                <?php echo ($auction['status'] ?? 'upcoming') === $key ? 'selected' : ''; ?>>
                                <?php echo $lbl['np']; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div class="form-check mb-1">
                            <input type="checkbox" name="is_active" class="form-check-input" id="chkActive"
                                   <?php echo ($auction['is_active'] ?? 1) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="chkActive">
                                <strong>सक्रिय</strong> — Website मा देखाउने
                            </label>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ④ फोटो, कागजपत्र र Google Map -->
            <div class="info-section">
                <h6 class="section-heading"><i class="fas fa-images"></i> फोटो, कागजपत्र र Google Map</h6>
                <div class="row g-3">

                    <!-- Main Photo -->
                    <div class="col-md-6">
                        <label class="form-label fw-semibold"><i class="fas fa-image text-primary me-1"></i>मुख्य फोटो</label>
                        <input type="file" name="image" id="mainImgInput" class="form-control" accept="image/*,.webp,.png,.jpg,.jpeg">
                        <?php if (!empty($auction['image'])): ?>
                        <div class="mt-2" id="mainImgPreview">
                            <img src="<?php echo SITE_URL . htmlspecialchars($auction['image']); ?>" id="mainImgPreviewImg" class="img-thumbnail" style="max-height:120px;" alt="Preview">
                        </div>
                        <?php else: ?>
                        <div class="mt-2 d-none" id="mainImgPreview">
                            <img src="" id="mainImgPreviewImg" class="img-thumbnail" style="max-height:120px;" alt="Preview">
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Additional Photos -->
                    <div class="col-md-6">
                        <label class="form-label fw-semibold"><i class="fas fa-images text-info me-1"></i>थप फोटोहरू</label>
                        <input type="file" name="additional_images[]" class="form-control" accept="image/*,.webp,.png,.jpg,.jpeg" multiple>
                        <?php
                        $exImgs = json_decode($auction['images'] ?? '[]', true) ?: [];
                        if (!empty($exImgs)): ?>
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <?php foreach ($exImgs as $im): ?>
                            <img src="<?php echo SITE_URL . htmlspecialchars($im); ?>" class="img-thumb" alt="img">
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Document -->
                    <div class="col-md-6">
                        <label class="form-label fw-semibold"><i class="fas fa-file-pdf text-danger me-1"></i>कागजपत्र (PDF/DOC/DOCX)</label>
                        <input type="file" name="document" class="form-control" accept=".pdf,.doc,.docx,application/pdf,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document">
                        <?php if (!empty($auction['document'])): ?>
                        <div class="doc-preview">
                            <i class="fas fa-file-alt text-danger"></i>
                            <a href="<?php echo SITE_URL . htmlspecialchars($auction['document']); ?>" target="_blank" rel="noopener">
                                हालको कागजपत्र हेर्नुहोस्
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Google Map Link -->
                    <div class="col-md-6">
                        <label class="form-label fw-semibold"><i class="fab fa-google text-danger me-1"></i>Google Map Link</label>
                        <input type="url" name="google_map_link" class="form-control"
                               value="<?php echo htmlspecialchars($auction['google_map_link'] ?? ''); ?>"
                               placeholder="https://maps.google.com/...">
                    </div>

                    <!-- Google Map Embed -->
                    <div class="col-12">
                        <label class="form-label fw-semibold"><i class="fas fa-map-marked-alt text-primary me-1"></i>Google Map Embed (iframe)</label>
                        <textarea name="google_map_embed" class="form-control" rows="3"
                                  placeholder="<iframe ...></iframe>"><?php echo htmlspecialchars($auction['google_map_embed'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-save me-1"></i><?php echo $isEdit ? 'अपडेट गर्नुहोस्' : 'सेभ गर्नुहोस्'; ?>
                </button>
                <a href="auctions.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i>फिर्ता जानुहोस्
                </a>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('mainImgInput')?.addEventListener('change', function () {
    var file = this.files && this.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function (e) {
        var img = document.getElementById('mainImgPreviewImg');
        var wrap = document.getElementById('mainImgPreview');
        if (img && wrap) {
            img.src = e.target.result;
            wrap.classList.remove('d-none');
        }
    };
    reader.readAsDataURL(file);
});
</script>

<?php
} else {
/* ══════════════════════════════════════════════════════════
   LIST VIEW
   ══════════════════════════════════════════════════════════ */
$filterStatus = $_GET['status'] ?? '';
if ($filterStatus !== '' && !isset($statusLabels[$filterStatus])) {
    $filterStatus = '';
}
$search       = mb_substr(trim((string)($_GET['search'] ?? '')), 0, 200, 'UTF-8');
$where        = '1=1';
$params       = [];
if ($filterStatus && isset($statusLabels[$filterStatus])) {
    $where  .= ' AND status = ?';
    $params[] = $filterStatus;
}
if ($search !== '') {
    $where  .= ' AND (title LIKE ? OR title_en LIKE ? OR location LIKE ? OR tracking_number LIKE ?)';
    $t = "%$search%";
    $params = array_merge($params, [$t,$t,$t,$t]);
}

$auctions = [];
$counts   = ['total'=>0,'upcoming'=>0,'ongoing'=>0,'completed'=>0,'cancelled'=>0];
try {
    $stmt = $db->prepare("SELECT * FROM auction_notices WHERE $where ORDER BY auction_date DESC, created_at DESC");
    $stmt->execute($params);
    $auctions = $stmt->fetchAll();
    $cntStmt  = $db->query("SELECT status, COUNT(*) as c FROM auction_notices GROUP BY status");
    if ($cntStmt) {
        while ($r = $cntStmt->fetch()) {
            if (isset($counts[$r['status']])) $counts[$r['status']] = (int)$r['c'];
        }
    }
    $counts['total'] = $counts['upcoming'] + $counts['ongoing'] + $counts['completed'] + $counts['cancelled'];
} catch (Throwable $e) { error_log("[auctions.php] " . $e->getMessage()); }

echo adminPageHeader(
    'लिलामी व्यवस्थापन', 'fa-gavel',
    'सहकारीका लिलामी सूचनाहरूको व्यवस्थापन',
    '<a href="auctions.php?action=add" class="btn btn-success btn-sm"><i class="fas fa-plus me-1"></i>नयाँ लिलामी थप्नुहोस्</a>'
    . ' ' . adminStatLink('?status=upcoming','info','आगामी',$counts['upcoming'])
    . ' ' . adminStatLink('?status=ongoing','warning','जारी',$counts['ongoing'])
    . ' ' . adminStatLink('auctions.php','secondary','जम्मा',$counts['total'])
);
$_f = getFlash(); if ($_f) echo adminAlert($_f['type'], $_f['message']);
?>

<!-- Stat Mini Row -->
<div class="stat-mini-row no-print">
    <a href="auctions.php" class="stat-mini <?php echo !$filterStatus&&!$search?'active-filter':''; ?>">
        <div class="sm-icon ic-total"><i class="fas fa-gavel"></i></div>
        <div class="sm-val"><?php echo $counts['total']; ?></div>
        <div class="sm-lbl">जम्मा लिलामी</div>
    </a>
    <a href="?status=upcoming" class="stat-mini <?php echo $filterStatus==='upcoming'?'active-filter':''; ?>">
        <div class="sm-icon ic-pending"><i class="fas fa-clock"></i></div>
        <div class="sm-val"><?php echo $counts['upcoming']; ?></div>
        <div class="sm-lbl">आगामी</div>
    </a>
    <a href="?status=ongoing" class="stat-mini <?php echo $filterStatus==='ongoing'?'active-filter':''; ?>">
        <div class="sm-icon ic-approved"><i class="fas fa-play-circle"></i></div>
        <div class="sm-val"><?php echo $counts['ongoing']; ?></div>
        <div class="sm-lbl">जारी</div>
    </a>
    <a href="?status=completed" class="stat-mini <?php echo $filterStatus==='completed'?'active-filter':''; ?>">
        <div class="sm-icon auc-icon-completed-bg"><i class="fas fa-check-double auc-icon-completed-fg"></i></div>
        <div class="sm-val"><?php echo $counts['completed']; ?></div>
        <div class="sm-lbl">सम्पन्न</div>
    </a>
    <a href="?status=cancelled" class="stat-mini <?php echo $filterStatus==='cancelled'?'active-filter':''; ?>">
        <div class="sm-icon ic-rejected"><i class="fas fa-ban"></i></div>
        <div class="sm-val"><?php echo $counts['cancelled']; ?></div>
        <div class="sm-lbl">रद्द</div>
    </a>
</div>

<!-- Filter Bar -->
<div class="adm-filter-bar no-print">
    <form method="GET" class="adm-filter-form">
        <div class="afb-group">
            <label>स्थिति</label>
            <select name="status" class="afb-select">
                <option value="">सबै स्थिति</option>
                <?php foreach ($statusLabels as $k => $l): ?>
                <option value="<?php echo $k; ?>" <?php echo $filterStatus===$k?'selected':''; ?>><?php echo $l['np']; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="afb-group afb-search">
            <label>खोज्नुहोस्</label>
            <div class="afb-search-wrap">
                <i class="fas fa-search afb-search-icon"></i>
                <input type="text" name="search" class="afb-input" value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="शीर्षक, स्थान, Tracking No...">
            </div>
        </div>
        <button type="submit" class="afb-btn-search"><i class="fas fa-search me-1"></i>खोज</button>
        <?php if ($filterStatus || $search): ?>
        <a href="auctions.php" class="afb-btn-reset"><i class="fas fa-times me-1"></i>रिसेट</a>
        <?php endif; ?>
    </form>
</div>

<!-- Table -->
<div class="app-table">
    <div class="tbl-header-bar">
        <span class="tbl-title"><i class="fas fa-gavel me-2"></i>लिलामी सूची</span>
        <span class="tbl-count"><?php echo count($auctions); ?> लिलामी</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Tracking</th>
                        <th>शीर्षक / स्थान</th>
                        <th>क्षेत्रफल</th>
                        <th>न्यूनतम मूल्य</th>
                        <th>लिलामी मिति</th>
                        <th>स्थिति</th>
                        <th>बोलपत्र</th>
                        <th>कार्य</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($auctions as $auc):
                        $bidCount = 0;
                        try {
                            $bs = $db->prepare("SELECT COUNT(*) FROM auction_bids WHERE auction_id = ?");
                            $bs->execute([$auc['id']]);
                            $bidCount = (int)$bs->fetchColumn();
                        } catch (Throwable $e) { error_log("[auctions.php] " . $e->getMessage()); }

                        /* Build area display */
                        $areaParts = [];
                        if (!empty($auc['area_bigha'])  && $auc['area_bigha']  > 0) $areaParts[] = number_format((float)$auc['area_bigha'],2,'.',',') . ' बि.';
                        if (!empty($auc['area_ropani']) && $auc['area_ropani'] > 0) $areaParts[] = number_format((float)$auc['area_ropani'],2,'.',',') . ' रो.';
                        if (!empty($auc['area_aana'])   && $auc['area_aana']   > 0) $areaParts[] = (int)$auc['area_aana'] . ' आ.';
                        if (!empty($auc['area_paisa'])  && $auc['area_paisa']  > 0) $areaParts[] = (int)$auc['area_paisa'] . ' पै.';
                        $areaDisplay = !empty($areaParts) ? implode(' ', $areaParts) : htmlspecialchars($auc['area'] ?? '—');
                    ?>
                    <tr>
                        <td>
                            <span class="font-monospace text-primary small"><?php echo htmlspecialchars($auc['tracking_number'] ?? '—'); ?></span>
                            <?php if (!$auc['is_active']): ?>
                            <br><span class="badge bg-secondary auc-badge-xxs">निष्क्रिय</span>
                            <?php endif; ?>
                            <?php if (!empty($auc['document'])): ?>
                            <br><a href="<?php echo SITE_URL . htmlspecialchars($auc['document']); ?>" target="_blank"
                               title="Document" class="badge bg-danger text-decoration-none mt-1">
                                <i class="fas fa-file-pdf"></i> Doc
                            </a>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?php echo htmlspecialchars($auc['title']); ?></strong>
                            <?php if (!empty($auc['location'])): ?>
                            <br><small class="text-muted"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($auc['location']); ?></small>
                            <?php endif; ?>
                            <?php if (!empty($auc['google_map_link'])): ?>
                            <br><a href="<?php echo htmlspecialchars($auc['google_map_link']); ?>" target="_blank"
                               class="badge bg-danger text-decoration-none mt-1 auc-badge-xs">
                                <i class="fab fa-google me-1"></i>Map
                            </a>
                            <?php endif; ?>
                        </td>
                        <td class="text-nowrap"><?php echo $areaDisplay; ?></td>
                        <td class="fw-semibold text-success text-nowrap">रु. <?php echo number_format((float)$auc['minimum_price']); ?></td>
                        <td><?php echo htmlspecialchars($auc['auction_date'] ?? '—'); ?></td>
                        <td>
                            <span class="badge bg-<?php echo $statusLabels[$auc['status']]['class'] ?? 'secondary'; ?>">
                                <?php echo $statusLabels[$auc['status']]['np'] ?? htmlspecialchars($auc['status']); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($bidCount > 0): ?>
                            <a href="auction-bids.php?auction_id=<?php echo $auc['id']; ?>" class="badge bg-primary text-decoration-none">
                                <i class="fas fa-list-ol me-1"></i><?php echo $bidCount; ?> बोलपत्र
                            </a>
                            <?php else: ?>
                            <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="d-flex gap-1 flex-wrap">
                                <a href="auctions.php?action=edit&id=<?php echo $auc['id']; ?>"
                                   class="btn btn-sm btn-primary" title="सम्पादन">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <!-- Quick Status -->
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-label="Swap" title="Swap">
                                        <i class="fas fa-exchange-alt"></i>
                                    </button>
                                    <ul class="dropdown-menu">
                                        <li><span class="dropdown-item-text small text-muted fw-bold">स्थिति बदल्नुहोस्</span></li>
                                        <?php foreach ($statusLabels as $sKey => $sLbl): ?>
                                        <?php if ($sKey !== $auc['status']): ?>
                                        <li>
                                            <form method="POST" class="d-inline">
                                                <?php echo csrfField(); ?>
                                                <input type="hidden" name="quick_status" value="1">
                                                <input type="hidden" name="auction_id" value="<?php echo $auc['id']; ?>">
                                                <input type="hidden" name="status" value="<?php echo $sKey; ?>">
                                                <button type="submit" class="dropdown-item">
                                                    <span class="badge bg-<?php echo $sLbl['class']; ?> me-1">&nbsp;</span><?php echo $sLbl['np']; ?>
                                                </button>
                                            </form>
                                        </li>
                                        <?php endif; ?>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                <!-- Delete -->
                                <form method="POST" class="d-inline"
                                      onsubmit="return confirm('के तपाईं पक्का हुनुहुन्छ? यो लिलामी र सम्बन्धित बोलपत्रहरू हट्नेछन्।')">
                                    <?php echo csrfField(); ?>
                                    <input type="hidden" name="delete_auction" value="1">
                                    <input type="hidden" name="auction_id" value="<?php echo $auc['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="मेटाउनुहोस्">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($auctions)): ?>
                    <tr><td colspan="8" class="text-center py-5 text-muted">
                        <i class="fas fa-gavel fa-3x mb-3 d-block opacity-25"></i>
                        कुनै लिलामी छैन। <a href="auctions.php?action=add" class="text-primary">नयाँ थप्नुहोस्</a>।
                    </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
</div>

<?php } ?>

<?php require_once 'includes/admin-footer.php'; ?>
