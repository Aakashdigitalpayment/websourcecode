<?php
/**
 * Universal Print Template Partial
 * Usage: Pass $printData array before including this file
 * 
 * $printData = [
 *   'title'       => 'Loan Application',
 *   'tracking'    => 'LOAN-000123',
 *   'personal'    => [
 *       'Full Name' => 'John Doe',
 *       'Phone' => '9801234567',
 *       'Citizenship' => '12345678901'
 *   ],
 *   'fields'      => [
 *       'Loan Type' => 'Personal',
 *       'Amount' => 'Rs 100,000',
 *       'Duration' => '12 Months'
 *   ],
 *   'attachments' => ['doc1.pdf', 'doc2.jpg'],
 * ];
 * include __DIR__ . '/_partials/print-form.php';
 */

$printData = $printData ?? [];
$orgName = getSetting('organization_name', 'बन्दना सिग्देल बहुउद्देश्यीय सहकारी संस्था');
$logoPath = getSetting('logo', 'assets/images/logo.png');
?>
<!DOCTYPE html>
<html lang="ne">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($printData['title'] ?? 'Form') ?></title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  @page { size: A4; margin: 12mm; }
  @media print {
    body { margin: 0; padding: 0; }
    .no-print { display: none !important; }
    .pf-container { box-shadow: none; page-break-after: always; }
  }
  
  body {
    font-family: 'Noto Sans Devanagari', 'Noto Sans', Arial, sans-serif;
    color: #1a1a1a;
    font-size: 11pt;
    line-height: 1.5;
    background: #f5f5f5;
  }
  
  .pf-container {
    background: white;
    padding: 20mm;
    margin: 10mm auto;
    max-width: 210mm;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
  }
  
  .pf-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    border-bottom: 2px solid #1a5f2a;
    padding-bottom: 10mm;
    margin-bottom: 10mm;
  }
  
  .pf-logo {
    height: 50px;
    max-width: 80px;
  }
  
  .pf-org-info {
    text-align: right;
    flex: 1;
    margin-left: 10mm;
  }
  
  .pf-org-name {
    font-weight: bold;
    font-size: 12pt;
    margin-bottom: 2mm;
  }
  
  .pf-tracking {
    font-size: 10pt;
    color: #666;
    margin: 1mm 0;
  }
  
  .pf-date {
    font-size: 10pt;
    color: #666;
  }
  
  .pf-section {
    margin-bottom: 8mm;
  }
  
  .pf-section-title {
    background: linear-gradient(135deg, #1a5f2a 0%, #2a7d3a 100%);
    color: white;
    padding: 3mm 5mm;
    font-weight: bold;
    font-size: 11pt;
    margin-bottom: 4mm;
    border-radius: 2px;
  }
  
  .pf-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 4mm;
    font-size: 10pt;
  }
  
  .pf-table tr {
    border-bottom: 1px solid #ddd;
  }
  
  .pf-table th {
    background: #f0f0f0;
    padding: 2mm 3mm;
    text-align: left;
    font-weight: bold;
    border: 1px solid #ddd;
  }
  
  .pf-table td {
    padding: 2mm 3mm;
    border: 1px solid #ddd;
    vertical-align: top;
  }
  
  .pf-table td:first-child {
    font-weight: 600;
    width: 35%;
    background: #f9f9f9;
  }
  
  .pf-signatures {
    display: flex;
    justify-content: space-between;
    margin-top: 15mm;
    padding-top: 10mm;
    border-top: 1px solid #999;
  }
  
  .pf-sig-block {
    width: 28%;
    text-align: center;
  }
  
  .pf-sig-line {
    border-top: 1px solid #000;
    height: 40mm;
    margin-bottom: 2mm;
  }
  
  .pf-sig-label {
    font-size: 9pt;
    font-weight: bold;
  }
  
  .pf-seal-area {
    width: 28%;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
  }
  
  .pf-seal-box {
    width: 50mm;
    height: 50mm;
    border: 2px dashed #999;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 2mm;
    font-size: 8pt;
    color: #999;
  }
  
  .pf-footer {
    margin-top: 10mm;
    text-align: center;
    font-size: 9pt;
    color: #666;
    border-top: 1px solid #ddd;
    padding-top: 5mm;
  }
  
  .no-print-btn {
    text-align: center;
    padding: 10mm 0;
    margin-bottom: 5mm;
    gap: 5mm;
  }
  
  .btn-print {
    background: #1a5f2a;
    color: white;
    border: none;
    padding: 6mm 12mm;
    border-radius: 4px;
    font-size: 10pt;
    cursor: pointer;
    font-weight: bold;
    margin-right: 5mm;
  }
  
  .btn-print:hover {
    background: #0d4620;
  }
  
  .btn-close-print {
    background: #6c757d;
    color: white;
    border: none;
    padding: 6mm 12mm;
    border-radius: 4px;
    font-size: 10pt;
    cursor: pointer;
  }
  
  .btn-close-print:hover {
    background: #5a6268;
  }
  
  .pf-empty-msg {
    text-align: center;
    padding: 20mm;
    color: #999;
  }
</style>
</head>
<body>

<div class="no-print-btn">
  <button class="btn-print" onclick="window.print(); return false;">
    <i class="fas fa-print"></i> छाप्नुहोस् (Print)
  </button>
  <button class="btn-close-print" onclick="window.close();">
    बन्द गर्नुहोस् (Close)
  </button>
</div>

<div class="pf-container">
  
  <!-- Header -->
  <div class="pf-header">
    <?php if (!empty($logoPath) && file_exists(trim($logoPath, '/'))): ?>
      <img src="<?= htmlspecialchars($logoPath) ?>" class="pf-logo" alt="Logo">
    <?php endif; ?>
    <div class="pf-org-info">
      <div class="pf-org-name"><?= htmlspecialchars($orgName) ?></div>
      <div class="pf-tracking" style="font-weight: bold; margin-top: 2mm;">
        <?= htmlspecialchars($printData['title'] ?? 'Form') ?>
      </div>
      <div class="pf-tracking">
        Tracking ID: <?= htmlspecialchars($printData['tracking'] ?? 'N/A') ?>
      </div>
      <div class="pf-date">
        Date: <?= date('Y-m-d'); ?>
      </div>
    </div>
  </div>
  
  <!-- Personal Details -->
  <?php if (!empty($printData['personal'])): ?>
    <div class="pf-section">
      <div class="pf-section-title">व्यक्तिगत विवरण (Personal Details)</div>
      <table class="pf-table">
        <?php foreach ((array)$printData['personal'] as $key => $value): ?>
          <tr>
            <td><?= htmlspecialchars($key) ?></td>
            <td><?= htmlspecialchars($value ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endif; ?>
  
  <!-- Form Fields -->
  <?php if (!empty($printData['fields'])): ?>
    <div class="pf-section">
      <div class="pf-section-title">फारम विवरण (Form Details)</div>
      <table class="pf-table">
        <?php foreach ((array)$printData['fields'] as $key => $value): ?>
          <tr>
            <td><?= htmlspecialchars($key) ?></td>
            <td><?= htmlspecialchars($value ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endif; ?>
  
  <!-- Attachments -->
  <?php if (!empty($printData['attachments'])): ?>
    <div class="pf-section">
      <div class="pf-section-title">संलग्न कागजात (Attachments)</div>
      <table class="pf-table">
        <?php foreach ((array)$printData['attachments'] as $idx => $file): ?>
          <tr>
            <td><?= ($idx + 1) ?></td>
            <td><?= htmlspecialchars(basename($file)) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endif; ?>
  
  <!-- Signatures -->
  <div class="pf-signatures">
    <div class="pf-sig-block">
      <div class="pf-sig-line"></div>
      <div class="pf-sig-label">आवेदकको हस्ताक्षर<br>(Applicant Signature)</div>
    </div>
    <div class="pf-sig-block">
      <div class="pf-sig-line"></div>
      <div class="pf-sig-label">प्रशासकको हस्ताक्षर<br>(Admin Signature)</div>
    </div>
    <div class="pf-seal-area">
      <div class="pf-seal-box">संस्थाको मुहर<br>(Organization Seal)</div>
      <div class="pf-sig-label">मिति / Date</div>
    </div>
  </div>
  
  <!-- Footer -->
  <div class="pf-footer">
    © <?= date('Y') ?> <?= htmlspecialchars($orgName) ?> | यो कागजात केवल आन्तरिक उपयोगको लागि हो।
  </div>
  
</div>

</body>
</html>
