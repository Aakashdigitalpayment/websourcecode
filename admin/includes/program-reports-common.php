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
    echo "\xEF\xBB\xBF";
}

function programReportsMemberPhotoUrl(?string $photo): string
{
    $photo = trim((string)$photo);
    if ($photo === '') {
        return '';
    }
    if (function_exists('coop_public_download_url')) {
        return coop_public_download_url($photo);
    }
    return (defined('SITE_URL') ? SITE_URL : '../') . ltrim($photo, '/');
}
