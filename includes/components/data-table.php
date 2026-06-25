<?php
/**
 * ════════════════════════════════════════════════════════════
 * DATA TABLE — Responsive Mobile-friendly Table Component
 * ════════════════════════════════════════════════════════════
 *
 * USAGE — यसलाई wrapper को रूपमा प्रयोग गर्नुहोस्:
 *
 *   <?php
 *   $tableHeaders = ['सि.नं.', 'नाम', 'ठेगाना', 'मिति', 'कार्य'];
 *   $tableId      = 'membersTable';    // DataTables id (optional)
 *   $tableClass   = '';                // extra class (optional)
 *   $tableEmpty   = 'कुनै रेकर्ड छैन।'; // empty state message (optional)
 *   $tableSearch  = true;              // show search input (optional, default false)
 *   include __DIR__ . '/../includes/components/data-table.php';
 *   ?>
 *   <!-- tbody rows यहाँ -->
 *   <tr>
 *     <td data-label="सि.नं.">1</td>
 *     <td data-label="नाम">राम बहादुर</td>
 *     ...
 *   </tr>
 *   <?php include __DIR__ . '/../includes/components/data-table-close.php'; ?>
 *
 * Mobile card-view: data-label attribute अनिवार्य।
 * Constraint: PHP backend logic नछुने।
 * ════════════════════════════════════════════════════════════
 */
if (!isset($tableHeaders) || !is_array($tableHeaders)) $tableHeaders = [];
if (!isset($tableId))     $tableId     = '';
if (!isset($tableClass))  $tableClass  = '';
if (!isset($tableEmpty))  $tableEmpty  = 'कुनै रेकर्ड फेला परेन।';
if (!isset($tableSearch)) $tableSearch = false;
if (!isset($tableSortable)) $tableSortable = false;
if (!isset($tablePaginated)) $tablePaginated = false;

$_idAttr    = $tableId    ? " id=\"{$tableId}\""    : '';
$_classAttr = $tableClass ? " {$tableClass}"          : '';
$_sortClass = $tableSortable ? ' table-sortable' : '';
?>
<?php if ($tableSearch): ?>
<div class="table-search-wrapper mb-3 d-flex justify-content-end">
    <div class="table-search-box">
        <span class="table-search-icon"><i class="lucide-icon" aria-hidden="true" data-lucide="search"></i></span>
        <input type="text" class="table-search-input"
               placeholder="खोज्नुहोस्…"
               data-table-id="<?php echo htmlspecialchars($tableId ?: 'dataTable', ENT_QUOTES); ?>"
               aria-label="<?php echo htmlspecialchars(adminUiT('तालिका खोज्नुहोस्', 'Search table'), ENT_QUOTES); ?>">
    </div>
</div>
<?php endif; ?>
<div class="table-scroll-wrapper">
<table<?php echo $_idAttr; ?> class="table table-striped table-hover align-middle coop-table table-responsive-stack<?php echo $_classAttr . $_sortClass; ?>" role="table">
<thead>
    <tr>
    <?php foreach ($tableHeaders as $_h): ?>
        <th><?php echo htmlspecialchars((string)$_h, ENT_QUOTES, 'UTF-8'); ?></th>
    <?php endforeach; ?>
    </tr>
</thead>
<tbody>
<?php
// Caller inserts rows here.
// data-table-close.php closes tbody/table/div.
// Store empty message for use in close component:
$GLOBALS['__dt_empty']  = $tableEmpty;
$GLOBALS['__dt_cols']   = count($tableHeaders);
unset($tableHeaders, $tableId, $tableClass, $tableEmpty, $tableSearch, $_idAttr, $_classAttr, $_h);
?>
