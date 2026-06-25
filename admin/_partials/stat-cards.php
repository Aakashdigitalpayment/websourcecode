<?php
/**
 * Uniform Stat/Count Cards Partial
 * Usage: Include this file and pass $statCards array before
 * 
 * $statCards = [
 *   ['icon'=>'fa-clock', 'label'=>'Pending', 'value'=>45, 'bg'=>'warning', 'href'=>'?status=pending'],
 *   ['icon'=>'fa-check', 'label'=>'Approved', 'value'=>120, 'bg'=>'success', 'href'=>'?status=approved'],
 * ];
 * include __DIR__ . '/_partials/stat-cards.php';
 */
?>
<div class="row g-3 mb-3 stat-uniform-row">
  <?php if (!empty($statCards)) { ?>
    <?php foreach ($statCards as $card): ?>
      <div class="col-6 col-sm-4 col-md-3 col-lg-2">
        <a href="<?= isset($card['href']) ? htmlspecialchars($card['href']) : '#' ?>"
           class="card border-0 shadow-sm text-center py-3 stat-uniform-card text-decoration-none h-100"
           data-bg="<?= $card['bg'] ?? 'primary' ?>"
           title="<?= htmlspecialchars($card['label'] ?? '') ?>">
          <div class="stat-uniform-icon">
            <i class="fa <?= htmlspecialchars($card['icon'] ?? 'fa-circle') ?>"></i>
          </div>
          <div class="stat-uniform-value">
            <?= number_format((int)($card['value'] ?? 0)) ?>
          </div>
          <div class="stat-uniform-label small text-muted">
            <?= htmlspecialchars($card['label'] ?? 'Label') ?>
          </div>
        </a>
      </div>
    <?php endforeach; ?>
  <?php } else { ?>
    <div class="col-12">
      <div class="alert alert-info">No stat cards configured</div>
    </div>
  <?php } ?>
</div>
