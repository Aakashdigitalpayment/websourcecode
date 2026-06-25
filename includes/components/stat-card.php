<?php
/**
 * ════════════════════════════════════════════════════════════
 * STAT CARD — Dashboard KPI Card Component
 * Public, Admin, Member तीनैमा एउटै stat card
 * ════════════════════════════════════════════════════════════
 *
 * Use गर्ने तरिका:
 *   <?php
 *   $statCards = [
 *     [
 *       'label'  => 'कुल सदस्य',
 *       'value'  => $stats['members'],
 *       'icon'   => 'users',       // Lucide icon (without fa- prefix)
 *       'color'  => 'primary',   // primary | success | warning | danger | info | secondary
 *       'link'   => 'members.php',  // optional
 *       'badge'  => 'नयाँ',         // optional small badge text
 *       'trend'  => '+12%',          // optional trend text
 *     ],
 *     ...
 *   ];
 *   $statColClass = 'col-6 col-md-3';  // Bootstrap grid class (default: col-6 col-md-3)
 *   include __DIR__ . '/../includes/components/stat-card.php';
 *   ?>
 *
 * Constraint: PHP backend logic नछुने।
 * ════════════════════════════════════════════════════════════
 */
if (!isset($statCards) || !is_array($statCards)) $statCards = [];

/* v12 — stat-mini layout: uniform with kyc-applications.php reference design */
$_iconMap = [
    'primary'   => ['bg' => 'color-mix(in srgb, var(--primary-color, #1a5f2a) 15%, white)', 'color' => 'var(--primary-color, #1a5f2a)'],
    'success'   => ['bg' => '#dcfce7',  'color' => '#15803d'],
    'danger'    => ['bg' => '#fee2e2',  'color' => '#b91c1c'],
    'warning'   => ['bg' => '#fef9c3',  'color' => '#b45309'],
    'info'      => ['bg' => '#e0f2fe',  'color' => '#0369a1'],
    'secondary' => ['bg' => '#f3f4f6',  'color' => '#6b7280'],
];
?>
<div class="stat-mini-row mb-4">
<?php foreach ($statCards as $_card):
    $_color = $_card['color'] ?? 'primary';
    $_cm    = $_iconMap[$_color] ?? $_iconMap['primary'];
    $_link  = htmlspecialchars($_card['link']  ?? '', ENT_QUOTES, 'UTF-8');
    $_label = htmlspecialchars($_card['label'] ?? '', ENT_QUOTES, 'UTF-8');
    $_value = htmlspecialchars((string)($_card['value'] ?? '0'), ENT_QUOTES, 'UTF-8');
    $_iconRaw = trim((string)($_card['icon'] ?? 'bar-chart-3'));
    if (function_exists('fa_to_lucide') && preg_match('/(^fa-|\bfa[bsr]?\s)/i', $_iconRaw)) {
        if (preg_match('/fa-[a-z0-9-]+/i', $_iconRaw, $m)) {
            $_iconRaw = $m[0];
        }
        $_iconRaw = (string)fa_to_lucide($_iconRaw);
    }
    $_iconRaw = preg_replace('/^fa-/', '', $_iconRaw) ?: 'bar-chart-3';
    $_icon  = htmlspecialchars($_iconRaw, ENT_QUOTES, 'UTF-8');
    $_badge = !empty($_card['badge']) ? htmlspecialchars($_card['badge'], ENT_QUOTES, 'UTF-8') : '';
    $_trend = !empty($_card['trend']) ? htmlspecialchars($_card['trend'], ENT_QUOTES, 'UTF-8') : '';
    $_tag   = $_link ? 'a' : 'div';
    $_href  = $_link ? ' href="' . $_link . '"' : '';
?>
    <<?php echo $_tag; ?><?php echo $_href; ?> class="stat-mini text-decoration-none">
        <div class="sm-icon" style="background:<?php echo $_cm['bg']; ?>;color:<?php echo $_cm['color']; ?>;"><i class="lucide-icon" aria-hidden="true" data-lucide="<?php echo $_icon; ?>"></i></div>
        <div class="sm-val"><?php echo $_value; ?></div>
        <div class="sm-lbl">
            <?php echo $_label; ?>
            <?php if ($_badge): ?><span class="stat-card-badge"><?php echo $_badge; ?></span><?php endif; ?>
            <?php if ($_trend): ?><small class="d-block" style="color:#9ca3af;font-size:.65rem;margin-top:1px;"><?php echo $_trend; ?></small><?php endif; ?>
        </div>
    </<?php echo $_tag; ?>>
<?php endforeach; ?>
</div>
<?php
unset($statCards, $statColClass, $_iconMap, $_card, $_color, $_cm, $_link, $_label, $_value, $_icon, $_badge, $_trend, $_tag, $_href);
?>
