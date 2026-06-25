<?php
/**
 * ════════════════════════════════════════════════════════════
 * COMPONENT REGISTRY — Dev Bandana Cooperative v6.5
 * ════════════════════════════════════════════════════════════
 *
 * सबै centralized UI components को master list।
 * Include गर्नुपर्छ होइन — documentation मात्र।
 *
 * HOW TO USE A COMPONENT
 * ──────────────────────
 * 1. Variable(s) define गर्नुहोस्
 * 2. include गर्नुहोस्
 * 3. Variables automatically unset हुन्छन् (bleed-safe)
 *
 * COMPONENT LIST
 * ──────────────
 *  page-banner.php         Inner page hero banner (title, subtitle, breadcrumb, icon)
 *  section-header.php      Section heading with divider line
 *  flash-message.php       Session/query-string/inline alert display
 *  stat-card.php           Dashboard KPI stat cards (grid)
 *  data-table.php          Responsive table opener (+ data-table-close.php)
 *  data-table-close.php    Closes data-table.php
 *  empty-state.php         "No records" empty state with optional CTA
 *  form-section.php        Form card section opener (+ form-section-close.php)
 *  form-section-close.php  Closes form-section.php
 *  breadcrumb.php          Navigation trail
 *
 * QUICK EXAMPLES
 * ──────────────
 *
 * — Page Banner —
 *   $pageTitle    = 'सदस्यहरू';
 *   $pageSubtitle = 'कुल सदस्य विवरण';
 *   $bannerIcon   = 'fa-users';
 *   $breadcrumbs  = [['label'=>'गृहपृष्ठ','url'=>SITE_URL],['label'=>'सदस्यहरू']];
 *   include __DIR__ . '/page-banner.php';
 *
 * — Flash Message —
 *   include __DIR__ . '/flash-message.php';
 *
 * — Stat Cards —
 *   $statCards = [
 *     ['label'=>'कुल सदस्य','value'=>$members,'icon'=>'fa-users','color'=>'primary','link'=>'members.php'],
 *     ['label'=>'ऋण आवेदन','value'=>$loans,  'icon'=>'fa-hand-holding-usd','color'=>'warning'],
 *   ];
 *   include __DIR__ . '/stat-card.php';
 *
 * — Data Table —
 *   $tableHeaders = ['सि.नं.','नाम','मिति','कार्य'];
 *   $tableId      = 'memberTable';
 *   $tableSearch  = true;
 *   include __DIR__ . '/data-table.php';
 *   foreach ($rows as $i => $row):
 *       echo '<tr>';
 *       echo '<td data-label="सि.नं.">' . ($i+1) . '</td>';
 *       echo '<td data-label="नाम">' . htmlspecialchars($row['name']) . '</td>';
 *       echo '</tr>';
 *   endforeach;
 *   $tableRowCount = count($rows);
 *   include __DIR__ . '/data-table-close.php';
 *
 * — Empty State —
 *   $emptyIcon    = 'fa-inbox';
 *   $emptyTitle   = 'कुनै रेकर्ड छैन';
 *   $emptyMessage = 'नयाँ रेकर्ड थप्नुहोस्।';
 *   $emptyAction  = ['label'=>'नयाँ थप्नुहोस्','url'=>'add.php','icon'=>'fa-plus'];
 *   include __DIR__ . '/empty-state.php';
 *
 * — Form Section —
 *   $formSectionTitle = 'व्यक्तिगत जानकारी';
 *   $formSectionIcon  = 'fa-user';
 *   include __DIR__ . '/form-section.php';
 *   // ... form fields ...
 *   include __DIR__ . '/form-section-close.php';
 *
 * — Section Header —
 *   $sectionTitle    = 'हाम्रा सेवाहरू';
 *   $sectionSubtitle = 'बचत, ऋण र रेमिट्यान्स';
 *   $sectionAlign    = 'center';
 *   include __DIR__ . '/section-header.php';
 *
 * — Breadcrumb —
 *   $breadcrumbs = [
 *     ['label'=>'गृहपृष्ठ','url'=>SITE_URL,'icon'=>'fa-home'],
 *     ['label'=>'प्रोफाइल'],
 *   ];
 *   include __DIR__ . '/breadcrumb.php';
 *
 * ════════════════════════════════════════════════════════════
 */
// This file is documentation only — no executable code.
