<?php
/** Shared helpers for program report pages */
require_once __DIR__ . '/../../includes/program-tables.php';
require_once __DIR__ . '/../../includes/program-attendance-helpers.php';

function programReportsInit(): PDO
{
    $db = getDB();
    ensureProgramTables($db);
    return $db;
}

function programReportsProgramList(PDO $db): array
{
    return $db->query("SELECT id, title, program_type, is_multi_location, event_date FROM upcoming_programs ORDER BY COALESCE(event_date,'9999-12-31') DESC, id DESC LIMIT 300")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function programReportsSelectedProgram(PDO $db, int $programId): ?array
{
    return $programId > 0 ? programFetchById($db, $programId) : null;
}

function programReportsCsvHeaders(string $filename): void
{
    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF";
}

function programReportsFormatAttendedAt(?string $dt): string
{
    return programFormatAttendedAt($dt);
}

const PROGRAM_REPORT_DISPLAY_LIMIT = 500;

/** Program picker + CSV button used by every report page. */
function programReportsFilterCard(array $programs, int $selectedId, string $emptyLabel = '— कार्यक्रम छान्नुहोस् —', bool $csv = true): string
{
    $html = '<div class="card admin-table-card mb-3"><div class="card-body"><form method="GET" class="row g-2 align-items-center">'
        . '<div class="col-md-8"><select name="program_id" class="form-select" aria-label="कार्यक्रम" onchange="this.form.submit()">'
        . '<option value="">' . htmlspecialchars($emptyLabel) . '</option>';
    foreach ($programs as $p) {
        $pid = (int)($p['id'] ?? 0);
        $html .= '<option value="' . $pid . '"' . ($selectedId === $pid ? ' selected' : '') . '>' . htmlspecialchars(programReportsProgramLabel($p)) . '</option>';
    }
    $html .= '</select></div>';
    if ($csv && $selectedId > 0) {
        $html .= '<div class="col-md-4"><a class="btn btn-success" href="?program_id=' . $selectedId . '&amp;export=csv"><i class="lucide-icon me-1" data-lucide="download" aria-hidden="true"></i>CSV (सबै)</a></div>';
    }
    return $html . '</form></div></div>';
}

/** "showing 500 of 3200 — CSV has all" note when the on-screen table is capped. */
function programReportsLimitNote(int $shown, int $total): string
{
    if ($total <= $shown) {
        return '';
    }
    return '<div class="alert alert-info py-2 small mb-2">पहिलो ' . $shown . ' / जम्मा ' . $total . ' देखाइएको — पूरा सूचीका लागि CSV डाउनलोड गर्नुहोस्।</div>';
}

/** Stream rows to CSV; $map turns one DB row into a CSV line. */
function programReportsCsvStream(string $filename, array $header, iterable $rows, callable $map): void
{
    programReportsCsvHeaders($filename);
    $out = fopen('php://output', 'w');
    fputcsv($out, $header);
    foreach ($rows as $r) {
        fputcsv($out, $map($r));
    }
    fclose($out);
    exit;
}

function programReportsCsvMethodLabel(?string $method): string
{
    return programAttendanceMethodLabel($method, true);
}

/** Dropdown label that tells same-titled programs apart. */
function programReportsProgramLabel(array $p): string
{
    $meta = array_filter([
        ($p['program_type'] ?? 'General') !== 'General' ? (string)($p['program_type'] ?? '') : '',
        (string)($p['event_date'] ?? ''),
    ]);
    return (string)($p['title'] ?? '') . ($meta ? ' — ' . implode(' · ', $meta) : '') . ' (#' . (int)($p['id'] ?? 0) . ')';
}

/** Tab bar shared by all report pages; carries the selected program across tabs. */
function programReportsTabs(string $current, int $programId = 0): string
{
    $noProgramParam = ['program-reports-member', 'program-reports-history'];
    $tabs = [
        'program-reports-consolidated' => ['समेकित', 'bar-chart-3'],
        'program-reports-location'     => ['स्थानअनुसार', 'map'],
        'program-reports-breakdown'    => ['लिङ्ग / Desk / Staff', 'pie-chart'],
        'program-reports-absent'       => ['अनुपस्थित', 'user-x'],
        'program-reports-member'       => ['सदस्यअनुसार', 'users'],
        'program-reports-history'      => ['सदस्य इतिहास', 'history'],
        'program-reports-duplicates'   => ['दोहोरो प्रयास', 'shield-alert'],
    ];
    $q = $programId > 0 ? '?program_id=' . $programId : '';
    $html = '<ul class="nav nav-pills flex-wrap gap-1 mb-3">';
    foreach ($tabs as $page => [$label, $icon]) {
        $active = $page === $current;
        $file = $page === 'program-reports-history' ? 'program-member-history' : $page;
        $html .= '<li class="nav-item"><a class="nav-link py-1 px-3' . ($active ? ' active' : ' bg-light') . '" href="' . $file . '.php' . (in_array($page, $noProgramParam, true) ? '' : $q) . '">'
            . '<i class="lucide-icon me-1" data-lucide="' . $icon . '" aria-hidden="true"></i>' . htmlspecialchars($label) . '</a></li>';
    }
    if ($programId > 0 && $current !== '') {
        $html .= '<li class="nav-item ms-auto"><a class="nav-link py-1 px-3 bg-light" href="program-detail.php?id=' . $programId . '"><i class="lucide-icon me-1" data-lucide="arrow-left" aria-hidden="true"></i>कार्यक्रम hub</a></li>';
    }
    return $html . '</ul>';
}
