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

function programReportsMemberPhotoUrl(?string $photo): string
{
    return programMemberPhotoUrl($photo);
}

function programReportsFormatAttendedAt(?string $dt): string
{
    return programFormatAttendedAt($dt);
}

function programReportsRenderProgramSelect(array $programs, int $selectedId, string $emptyLabel = '— कार्यक्रम —'): void
{
    echo '<select name="program_id" class="form-select" onchange="this.form.submit()">';
    echo '<option value="">' . htmlspecialchars($emptyLabel) . '</option>';
    foreach ($programs as $p) {
        $pid = (int)($p['id'] ?? 0);
        $sel = $selectedId === $pid ? ' selected' : '';
        echo '<option value="' . $pid . '"' . $sel . '>' . htmlspecialchars((string)($p['title'] ?? '')) . '</option>';
    }
    echo '</select>';
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
    $tabs = [
        'program-reports-consolidated' => ['समेकित', 'bar-chart-3'],
        'program-reports-location'     => ['स्थानअनुसार', 'map'],
        'program-reports-absent'       => ['अनुपस्थित', 'user-x'],
        'program-reports-member'       => ['सदस्यअनुसार', 'users'],
        'program-reports-duplicates'   => ['दोहोरो प्रयास', 'shield-alert'],
    ];
    $q = $programId > 0 ? '?program_id=' . $programId : '';
    $html = '<ul class="nav nav-pills flex-wrap gap-1 mb-3">';
    foreach ($tabs as $page => [$label, $icon]) {
        $active = $page === $current;
        $html .= '<li class="nav-item"><a class="nav-link py-1 px-3' . ($active ? ' active' : ' bg-light') . '" href="' . $page . '.php' . ($page === 'program-reports-member' ? '' : $q) . '">'
            . '<i class="lucide-icon me-1" data-lucide="' . $icon . '" aria-hidden="true"></i>' . htmlspecialchars($label) . '</a></li>';
    }
    if ($programId > 0 && $current !== '') {
        $html .= '<li class="nav-item ms-auto"><a class="nav-link py-1 px-3 bg-light" href="program-detail.php?id=' . $programId . '"><i class="lucide-icon me-1" data-lucide="arrow-left" aria-hidden="true"></i>कार्यक्रम hub</a></li>';
    }
    return $html . '</ul>';
}
