<?php
require_once __DIR__ . '/_bootstrap.php'; // bootstrap → config auto-loaded
require_once 'includes/service-products-tables.php';
$pageTitle = isEnglish() ? 'Services' : 'सेवाहरू';
require_once 'includes/header.php';
$L = getLangStrings();

// Get services (schema-safe for older DBs)
try {
    $db = getDB();
    ensureServiceProductsTables($db);
    $hasIsNew = false;
    $hasNewUntil = false;
    try {
        $col = $db->query("SHOW COLUMNS FROM services LIKE 'is_new'");
        $hasIsNew = ($col && $col->fetch() !== false);
    } catch (Throwable $e) {
        $hasIsNew = false;
    }
    try {
        $col = $db->query("SHOW COLUMNS FROM services LIKE 'new_until'");
        $hasNewUntil = ($col && $col->fetch() !== false);
    } catch (Throwable $e) {
        $hasNewUntil = false;
    }

    if ($hasIsNew && $hasNewUntil) {
        $sql = "SELECT *,
            (CASE WHEN is_new = 1 AND (new_until IS NULL OR new_until >= CURDATE()) THEN 1 ELSE 0 END) as show_new_badge
            FROM services WHERE is_active = 1 ORDER BY display_order";
    } elseif ($hasIsNew) {
        $sql = "SELECT *, (CASE WHEN is_new = 1 THEN 1 ELSE 0 END) as show_new_badge
            FROM services WHERE is_active = 1 ORDER BY display_order";
    } else {
        $sql = "SELECT *, 0 as show_new_badge
            FROM services WHERE is_active = 1 ORDER BY display_order";
    }
    $services = $db->query($sql)->fetchAll();

    $serviceCategories = [];
    try {
        $serviceCategories = $db->query("SELECT * FROM service_categories WHERE is_active = 1 ORDER BY display_order, id")->fetchAll();
    } catch (Throwable $e) {
        $serviceCategories = [];
    }

    $serviceProducts = [];
    if (!empty($services)) {
        $serviceIds = array_values(array_filter(array_map(fn($r) => (int)($r['id'] ?? 0), $services), fn($v) => $v > 0));
        if (!empty($serviceIds)) {
            $ph = implode(',', array_fill(0, count($serviceIds), '?'));
            $pst = $db->prepare("SELECT service_id, title_np, title_en, description_np, description_en
                                 FROM service_products
                                 WHERE is_active = 1 AND service_id IN ($ph)
                                 ORDER BY service_id, display_order, id");
            $pst->execute($serviceIds);
            foreach (($pst->fetchAll() ?: []) as $pr) {
                $sid = (int)($pr['service_id'] ?? 0);
                if (!isset($serviceProducts[$sid])) $serviceProducts[$sid] = [];
                $serviceProducts[$sid][] = $pr;
            }
        }
    }

    $servicesByCategory = [];
    foreach ($services as $service) {
        $catId = (int)($service['service_category_id'] ?? 0);
        if (!isset($servicesByCategory[$catId])) {
            $servicesByCategory[$catId] = [];
        }
        $servicesByCategory[$catId][] = $service;
    }

    // Only keep categories that have active services
    $serviceCategories = array_values(array_filter($serviceCategories, static function(array $cat) use ($servicesByCategory) {
        return !empty($servicesByCategory[(int)($cat['id'] ?? 0)] ?? []);
    }));
} catch (Throwable $e) {
    $services = [];
    $serviceCategories = [];
    $serviceProducts = [];
    $servicesByCategory = [];
}

if (!function_exists('service_anchor_id')) {
    function service_anchor_id(array $service, int $fallbackIndex = 0): string
    {
        $id = (int)($service['id'] ?? 0);
        $title = trim((string)($service['title'] ?? ''));
        $titleNp = trim((string)($service['title_np'] ?? ''));
        $label = $title !== '' ? $title : $titleNp;
        $norm = mb_strtolower($label !== '' ? $label : $titleNp, 'UTF-8');
        if (mb_strpos($norm, 'बचत') !== false || mb_strpos($norm, 'saving') !== false) return 'saving';
        if (mb_strpos($norm, 'ऋण') !== false || mb_strpos($norm, 'loan') !== false || mb_strpos($norm, 'rin') !== false) return 'loan';
        if (mb_strpos($norm, 'रेमिट') !== false || mb_strpos($norm, 'remit') !== false) return 'remittance';
        $ascii = trim(strtolower((string)preg_replace('/[^a-z0-9]+/i', '-', $title)), '-');
        if ($ascii !== '') return $ascii;
        if ($id > 0) return 'service-' . $id;
        return 'service-' . $fallbackIndex;
    }
}
?>

<!-- Page Banner -->
<section class="page-banner">
    <div class="container">
        <h1><?php echo isEnglish() ? 'Our Services' : 'हाम्रो सेवाहरू'; ?></h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="<?php echo SITE_URL; ?>"><?php echo $L['home']; ?></a></li>
                <li class="breadcrumb-item active"><?php echo isEnglish() ? 'Services' : 'सेवाहरू'; ?></li>
            </ol>
        </nav>
    </div>
</section>

<!-- Services Section -->
<section class="services-page section-padding">
    <div class="container">
        <div class="section-header text-center mb-5" data-aos="fade-up">
            <div class="section-badge-wrap">
                <span class="section-badge"><i class="fas fa-concierge-bell"></i> <?php echo isEnglish() ? 'Our Services' : 'हाम्रा सेवाहरू'; ?></span>
            </div>
            <h2><?php echo isEnglish() ? 'Services We Provide' : 'हामीले प्रदान गर्ने सेवाहरू'; ?></h2>
            <div class="section-divider"></div>
            <p><?php echo isEnglish() ? 'We are here to fulfill your financial needs' : 'तपाईंको वित्तीय आवश्यकताहरू पूरा गर्न हामी यहाँ छौं'; ?></p>
        </div>

        <?php if (!empty($serviceCategories)): ?>
        <div class="service-category-nav mb-4">
            <div class="d-flex flex-wrap gap-2 justify-content-center">
                <?php foreach ($serviceCategories as $cat): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="#category-<?php echo (int)$cat['id']; ?>">
                        <?php echo isEnglish() ? ($cat['name_en'] ?: $cat['name']) : ($cat['name_np'] ?: $cat['name']); ?>
                    </a>
                <?php endforeach; ?>
                <?php if (!empty($servicesByCategory[0])): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="#category-0"><?php echo isEnglish() ? 'Other Services' : 'अन्य सेवाहरू'; ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="row">
            <?php if (!empty($serviceCategories) || !empty($servicesByCategory[0])): ?>
                <?php foreach ($serviceCategories as $catIndex => $category): ?>
                    <?php $catId = (int)($category['id'] ?? 0); ?>
                    <div class="col-12 mb-5" data-aos="fade-up" data-aos-delay="<?php echo ($catIndex ?? 0) * 70; ?>" id="category-<?php echo $catId; ?>">
                        <div class="service-category-header p-4 rounded-3 bg-white border shadow-sm">
                            <div class="service-category-header-inner d-flex align-items-center justify-content-center gap-3">
                                <div class="service-category-icon rounded-circle bg-primary text-white d-flex align-items-center justify-content-center" style="width:56px;height:56px;">
                                    <i class="<?php echo htmlspecialchars($category['icon'] ?: 'fas fa-th-large'); ?> fs-4"></i>
                                </div>
                                <div class="service-category-header-text">
                                    <h3 class="mb-1"><?php echo isEnglish() ? ($category['name_en'] ?: $category['name']) : ($category['name_np'] ?: $category['name']); ?></h3>
                                    <?php if (!empty($category['description'])): ?><p class="mb-0 text-muted"><?php echo htmlspecialchars(isEnglish() ? ($category['description'] ?? '') : ($category['description_np'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php $catServices = $servicesByCategory[$catId] ?? []; ?>
                    <?php foreach ($catServices as $index => $service): ?>
                    <div class="col-lg-4 col-md-6 mb-4" data-aos="fade-up" data-aos-delay="<?php echo (($catIndex * 10 + $index) ?? 0) * 30; ?>" id="<?php echo htmlspecialchars(service_anchor_id($service, (int)$index)); ?>">
                        <div class="service-detail-card">
                            <?php if (!empty($service['show_new_badge'])): ?>
                            <span class="new-badge"><?php echo isEnglish() ? 'New' : 'नयाँ'; ?></span>
                            <?php endif; ?>
                            <div class="service-icon-lg">
                                <i class="<?php echo $service['icon']; ?>"></i>
                            </div>
                            <h4><?php echo isEnglish() ? ($service['title'] ?: $service['title_np']) : ($service['title_np'] ?: $service['title']); ?></h4>
                            <p><?php echo isEnglish() ? ($service['description'] ?: $service['description_np']) : ($service['description_np'] ?: $service['description']); ?></p>
                            <?php $sProducts = $serviceProducts[(int)($service['id'] ?? 0)] ?? []; ?>
                            <?php if (!empty($sProducts)): ?>
                                <button class="btn btn-sm btn-outline-primary mt-2 service-more-btn" type="button"
                                        data-bs-toggle="collapse" data-bs-target="#service-products-<?php echo (int)$service['id']; ?>"
                                        aria-expanded="false" aria-controls="service-products-<?php echo (int)$service['id']; ?>">
                                    <?php echo isEnglish() ? 'More Products' : 'थप उत्पादहरू'; ?> <i class="lucide-icon ms-1" aria-hidden="true" data-lucide="chevron-down"></i>
                                </button>
                                <div class="collapse mt-3" id="service-products-<?php echo (int)$service['id']; ?>">
                                    <ul class="service-features mb-0">
                                        <?php foreach ($sProducts as $sp): ?>
                                            <li>
                                                <i class="fas fa-check"></i>
                                                <span>
                                                    <?php echo htmlspecialchars(isEnglish() ? (($sp['title_en'] ?: $sp['title_np']) ?? '') : (($sp['title_np'] ?: $sp['title_en']) ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                                    <?php
                                                    $pDesc = isEnglish() ? (($sp['description_en'] ?: $sp['description_np']) ?? '') : (($sp['description_np'] ?: $sp['description_en']) ?? '');
                                                    if (trim((string)$pDesc) !== ''): ?>
                                                    <small class="d-block text-muted"><?php echo htmlspecialchars((string)$pDesc, ENT_QUOTES, 'UTF-8'); ?></small>
                                                    <?php endif; ?>
                                                </span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($service['image'])): ?>
                                <img src="<?php echo $service['image']; ?>" loading="lazy" alt="<?php echo htmlspecialchars($service['title'] ?: $service['title_np'], ENT_QUOTES, 'UTF-8'); ?>" class="img-fluid mt-3 rounded">
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <?php if (!empty($servicesByCategory[0])): ?>
                    <div class="col-12 mb-5" data-aos="fade-up" data-aos-delay="200">
                        <div class="service-category-header p-4 rounded-3 bg-white border shadow-sm">
                            <div class="service-category-header-inner d-flex align-items-center justify-content-center gap-3">
                                <div class="service-category-icon rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center" style="width:56px;height:56px;">
                                    <i class="fas fa-list"></i>
                                </div>
                                <div class="service-category-header-text">
                                    <h3 class="mb-1"><?php echo isEnglish() ? 'Other Services' : 'अन्य सेवाहरू'; ?></h3>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php foreach ($servicesByCategory[0] as $index => $service): ?>
                    <div class="col-lg-4 col-md-6 mb-4" data-aos="fade-up" data-aos-delay="<?php echo ($index ?? 0) * 30; ?>" id="<?php echo htmlspecialchars(service_anchor_id($service, (int)$index)); ?>">
                        <div class="service-detail-card">
                            <?php if (!empty($service['show_new_badge'])): ?>
                            <span class="new-badge"><?php echo isEnglish() ? 'New' : 'नयाँ'; ?></span>
                            <?php endif; ?>
                            <div class="service-icon-lg">
                                <i class="<?php echo $service['icon']; ?>"></i>
                            </div>
                            <h4><?php echo isEnglish() ? ($service['title'] ?: $service['title_np']) : ($service['title_np'] ?: $service['title']); ?></h4>
                            <p><?php echo isEnglish() ? ($service['description'] ?: $service['description_np']) : ($service['description_np'] ?: $service['description']); ?></p>
                            <?php $sProducts = $serviceProducts[(int)($service['id'] ?? 0)] ?? []; ?>
                            <?php if (!empty($sProducts)): ?>
                                <button class="btn btn-sm btn-outline-primary mt-2 service-more-btn" type="button"
                                        data-bs-toggle="collapse" data-bs-target="#service-products-<?php echo (int)$service['id']; ?>"
                                        aria-expanded="false" aria-controls="service-products-<?php echo (int)$service['id']; ?>">
                                    <?php echo isEnglish() ? 'More Products' : 'थप उत्पादहरू'; ?> <i class="lucide-icon ms-1" aria-hidden="true" data-lucide="chevron-down"></i>
                                </button>
                                <div class="collapse mt-3" id="service-products-<?php echo (int)$service['id']; ?>">
                                    <ul class="service-features mb-0">
                                        <?php foreach ($sProducts as $sp): ?>
                                            <li>
                                                <i class="fas fa-check"></i>
                                                <span>
                                                    <?php echo htmlspecialchars(isEnglish() ? (($sp['title_en'] ?: $sp['title_np']) ?? '') : (($sp['title_np'] ?: $sp['title_en']) ?? ''), ENT_QUOTES, 'UTF-8'); ?>
                                                    <?php
                                                    $pDesc = isEnglish() ? (($sp['description_en'] ?: $sp['description_np']) ?? '') : (($sp['description_np'] ?: $sp['description_en']) ?? '');
                                                    if (trim((string)$pDesc) !== ''): ?>
                                                    <small class="d-block text-muted"><?php echo htmlspecialchars((string)$pDesc, ENT_QUOTES, 'UTF-8'); ?></small>
                                                    <?php endif; ?>
                                                </span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($service['image'])): ?>
                                <img src="<?php echo $service['image']; ?>" loading="lazy" alt="<?php echo htmlspecialchars($service['title'] ?: $service['title_np'], ENT_QUOTES, 'UTF-8'); ?>" class="img-fluid mt-3 rounded">
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            <?php else: ?>
                <!-- Default Services if none in database -->
                <div class="col-lg-4 col-md-6 mb-4" id="saving">
                    <div class="service-detail-card">
                        <div class="service-icon-lg">
                            <i class="fas fa-piggy-bank"></i>
                        </div>
                        <h4>बचत खाता</h4>
                        <p>हाम्रो बचत खाताहरूमा आकर्षक ब्याज दर र सुरक्षित बचतको ग्यारेन्टी पाउनुहोस्। विभिन्न प्रकारका बचत योजनाहरू उपलब्ध छन्।</p>
                        <ul class="service-features">
                            <li><i class="fas fa-check"></i> नियमित बचत खाता</li>
                            <li><i class="fas fa-check"></i> मुद्दती बचत खाता</li>
                            <li><i class="fas fa-check"></i> बाल बचत खाता</li>
                            <li><i class="fas fa-check"></i> महिला बचत खाता</li>
                        </ul>
                    </div>
                </div>

                <div class="col-lg-4 col-md-6 mb-4" id="loan">
                    <div class="service-detail-card">
                        <div class="service-icon-lg">
                            <i class="fas fa-hand-holding-usd"></i>
                        </div>
                        <h4>ऋण सेवा</h4>
                        <p>तपाईंको विभिन्न आवश्यकताहरूको लागि सजिलो र छिटो ऋण सेवा प्राप्त गर्नुहोस्।</p>
                        <ul class="service-features">
                            <li><i class="fas fa-check"></i> व्यक्तिगत ऋण</li>
                            <li><i class="fas fa-check"></i> व्यवसायिक ऋण</li>
                            <li><i class="fas fa-check"></i> घर कर्जा</li>
                            <li><i class="fas fa-check"></i> शैक्षिक ऋण</li>
                        </ul>
                    </div>
                </div>

                <div class="col-lg-4 col-md-6 mb-4" id="fixed-deposit">
                    <div class="service-detail-card">
                        <div class="service-icon-lg">
                            <i class="lucide-icon" aria-hidden="true" data-lucide="lock"></i>
                        </div>
                        <h4>मुद्दती निक्षेप</h4>
                        <p>उच्च ब्याज दरमा तपाईंको पैसालाई मुद्दती निक्षेपमा राख्नुहोस्।</p>
                        <ul class="service-features">
                            <li><i class="fas fa-check"></i> ३ महिना मुद्दती</li>
                            <li><i class="fas fa-check"></i> ६ महिना मुद्दती</li>
                            <li><i class="fas fa-check"></i> १ वर्ष मुद्दती</li>
                            <li><i class="fas fa-check"></i> २ वर्ष मुद्दती</li>
                        </ul>
                    </div>
                </div>

                <div class="col-lg-4 col-md-6 mb-4" id="remittance">
                    <div class="service-detail-card">
                        <div class="service-icon-lg">
                            <i class="fas fa-exchange-alt"></i>
                        </div>
                        <h4>रेमिट्यान्स</h4>
                        <p>विदेशबाट पठाइएको पैसा सजिलै र छिटो प्राप्त गर्नुहोस्।</p>
                        <ul class="service-features">
                            <li><i class="fas fa-check"></i> द्रुत सेवा</li>
                            <li><i class="fas fa-check"></i> सुरक्षित लेनदेन</li>
                            <li><i class="fas fa-check"></i> प्रतिस्पर्धी दर</li>
                        </ul>
                    </div>
                </div>

                <div class="col-lg-4 col-md-6 mb-4" id="mobile-banking">
                    <div class="service-detail-card">
                        <div class="service-icon-lg">
                            <i class="fas fa-mobile-alt"></i>
                        </div>
                        <h4>मोबाइल बैंकिङ</h4>
                        <p>आफ्नो मोबाइलबाट जुनसुकै समय आफ्नो खाता पहुँच गर्नुहोस्।</p>
                        <ul class="service-features">
                            <li><i class="fas fa-check"></i> २४/७ पहुँच</li>
                            <li><i class="fas fa-check"></i> सजिलो प्रयोग</li>
                            <li><i class="fas fa-check"></i> सुरक्षित</li>
                        </ul>
                    </div>
                </div>

                <div class="col-lg-4 col-md-6 mb-4" id="insurance">
                    <div class="service-detail-card">
                        <div class="service-icon-lg">
                            <i class="lucide-icon" aria-hidden="true" data-lucide="shield-alt"></i>
                        </div>
                        <h4>बीमा सेवा</h4>
                        <p>तपाईंको बचतको लागि बीमा कभरेज प्राप्त गर्नुहोस्।</p>
                        <ul class="service-features">
                            <li><i class="fas fa-check"></i> जीवन बीमा</li>
                            <li><i class="fas fa-check"></i> बचत बीमा</li>
                            <li><i class="fas fa-check"></i> दुर्घटना बीमा</li>
                        </ul>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</section>

<script>
(function () {
    function focusServiceFromHash() {
        var hash = (location.hash || '').replace(/^#/, '');
        if (!hash) {
            document.querySelectorAll('.service-detail-card').forEach(function (card) {
                var col = card.closest('[id]');
                if (col) {
                    col.style.display = '';
                    col.classList.remove('service-item--focused');
                }
                card.classList.remove('is-focused');
            });
            document.querySelectorAll('.service-category-header').forEach(function (h) {
                var wrap = h.closest('.col-12');
                if (wrap) wrap.style.display = '';
            });
            return;
        }
        var target = document.getElementById(hash);
        if (!target) return;

        document.querySelectorAll('.service-detail-card').forEach(function (card) {
            var col = card.closest('[id]');
            if (!col) return;
            var match = col.id === hash;
            col.style.display = match ? '' : 'none';
            col.classList.toggle('service-item--focused', match);
            card.classList.toggle('is-focused', match);
        });

        /* Hide category headers when focusing a single service */
        document.querySelectorAll('.service-category-header').forEach(function (h) {
            var wrap = h.closest('.col-12');
            if (wrap) wrap.style.display = 'none';
        });

        setTimeout(function () {
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }, 50);
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', focusServiceFromHash);
    else focusServiceFromHash();
    window.addEventListener('hashchange', focusServiceFromHash);
})();
</script>
<style>
.service-detail-card.is-focused {
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary-color, #1a5f2a) 35%, transparent), 0 12px 28px rgba(0,0,0,.08);
    border-radius: 12px;
}
.service-item--focused { scroll-margin-top: 110px; }
</style>

<!-- CTA Section -->
<section class="cta-section">
    <div class="container">
        <div class="cta-content">
            <div class="row align-items-center">
                <div class="col-lg-8">
                    <h2><?php echo isEnglish() ? 'Have Questions?' : 'कुनै प्रश्न छ?'; ?></h2>
                    <p><?php echo isEnglish() ? 'Contact us for more information about our services.' : 'हाम्रो सेवाहरूको बारेमा थप जानकारीको लागि हामीलाई सम्पर्क गर्नुहोस्।'; ?></p>
                </div>
                <div class="col-lg-4 text-lg-end">
                    <a href="contact.php" class="btn btn-light btn-lg"><?php echo $L['contact_us']; ?></a>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require_once 'includes/footer.php'; ?>
