<?php
/**
 * ════════════════════════════════════════════════════════════
 * DATA TABLE CLOSE — Closes table opened by data-table.php
 * ════════════════════════════════════════════════════════════
 *
 * data-table.php को साथ प्रयोग गर्नुहोस्:
 *
 *   include __DIR__ . '/../includes/components/data-table-close.php';
 *
 * Optional: $tableRowCount (total rows rendered) set गर्नुहोस्
 * ════════════════════════════════════════════════════════════
 */
$_emptyMsg  = $GLOBALS['__dt_empty'] ?? 'कुनै रेकर्ड फेला परेन।';
$_emptyCols = $GLOBALS['__dt_cols']  ?? 5;
$_rowCount  = isset($tableRowCount) ? (int)$tableRowCount : -1;
unset($GLOBALS['__dt_empty'], $GLOBALS['__dt_cols']);
?>
<?php if ($_rowCount === 0): ?>
<tr>
    <td colspan="<?php echo $_emptyCols; ?>" class="text-center text-muted py-5">
        <i class="lucide-icon" aria-hidden="true" data-lucide="inbox"></i>
        <span style="font-size:.88rem;"><?php echo htmlspecialchars($_emptyMsg, ENT_QUOTES, 'UTF-8'); ?></span>
    </td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>
<?php unset($_emptyMsg, $_emptyCols, $_rowCount, $tableRowCount); ?>
