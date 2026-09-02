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
