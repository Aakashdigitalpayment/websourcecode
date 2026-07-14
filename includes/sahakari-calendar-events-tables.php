<?php
/**
 * सहकारी पात्रो — admin-managed calendar events (कार्यक्रम / दिवस / बैठक)
 * Idempotent schema; expands once + monthly (हरेक महिनाको X गते) for a BS year.
 */
if (!function_exists('ensureSahakariCalendarEventTables')) {
    function ensureSahakariCalendarEventTables(?PDO $db = null): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        if (!$db && function_exists('getDB')) {
            try {
                $db = getDB();
            } catch (Throwable $e) {
                return;
            }
        }
        if (!$db instanceof PDO) {
            return;
        }
        try {
            $db->exec("CREATE TABLE IF NOT EXISTS sahakari_calendar_events (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title_np VARCHAR(200) NOT NULL,
                title_en VARCHAR(200) NOT NULL DEFAULT '',
                description_np TEXT NULL,
                description_en TEXT NULL,
                event_type VARCHAR(40) NOT NULL DEFAULT 'program',
                recurrence VARCHAR(20) NOT NULL DEFAULT 'once',
                bs_year SMALLINT NOT NULL,
                bs_month TINYINT NULL,
                bs_day TINYINT NOT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_sce_year (bs_year),
                INDEX idx_sce_active (is_active),
                INDEX idx_sce_ym (bs_year, bs_month),
                INDEX idx_sce_day (bs_day)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $done = true;
        } catch (Throwable $e) {
            error_log('sahakari_calendar_events table: ' . $e->getMessage());
        }
    }
}

if (!function_exists('sahakariCalendarEventTypeLabel')) {
    function sahakariCalendarEventTypeLabel(string $type, bool $en = false): string
    {
        $map = [
            'program' => ['कार्यक्रम', 'Program'],
            'diwash'  => ['दिवस', 'Observance'],
            'board'   => ['Board बैठक', 'Board meeting'],
            'staff'   => ['कर्मचारी बैठक', 'Staff meeting'],
            'meeting' => ['बैठक', 'Meeting'],
            'other'   => ['अन्य', 'Other'],
        ];
        $row = $map[$type] ?? $map['other'];
        return $en ? $row[1] : $row[0];
    }
}

if (!function_exists('sahakariCalendarFetchYearEvents')) {
    /**
     * Active events for a BS year (admin + public use).
     * @return list<array<string,mixed>>
     */
    function sahakariCalendarFetchYearEvents(int $bsYear, ?PDO $db = null, bool $activeOnly = true): array
    {
        $db = $db ?: (function_exists('getDB') ? getDB() : null);
        if (!$db instanceof PDO) {
            return [];
        }
        ensureSahakariCalendarEventTables($db);
        try {
            $sql = 'SELECT * FROM sahakari_calendar_events WHERE bs_year = ?';
            if ($activeOnly) {
                $sql .= ' AND is_active = 1';
            }
            $sql .= ' ORDER BY bs_month IS NULL ASC, bs_month ASC, bs_day ASC, id ASC';
            $st = $db->prepare($sql);
            $st->execute([$bsYear]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('sahakariCalendarEventsByDay')) {
    /**
     * Expand once + monthly events for one BS month → map[day] = events.
     * @return array<int, list<array{id:int,name:string,type:string,event_type:string,recurrence:string}>>
     */
    function sahakariCalendarEventsByDay(int $bsYear, int $bsMonth, int $daysInMonth, ?PDO $db = null, bool $english = false): array
    {
        $byDay = [];
        $rows = sahakariCalendarFetchYearEvents($bsYear, $db, true);
        foreach ($rows as $row) {
            $day = (int)($row['bs_day'] ?? 0);
            if ($day < 1 || $day > 32) {
                continue;
            }
            $rec = (string)($row['recurrence'] ?? 'once');
            $month = isset($row['bs_month']) && $row['bs_month'] !== null && $row['bs_month'] !== ''
                ? (int)$row['bs_month']
                : null;

            $applies = false;
            if ($rec === 'monthly') {
                $applies = ($day <= $daysInMonth);
            } else {
                $applies = ($month === $bsMonth && $day <= $daysInMonth);
            }
            if (!$applies) {
                continue;
            }

            $title = $english
                ? trim((string)(($row['title_en'] ?? '') !== '' ? $row['title_en'] : ($row['title_np'] ?? '')))
                : trim((string)(($row['title_np'] ?? '') !== '' ? $row['title_np'] : ($row['title_en'] ?? '')));
            if ($title === '') {
                continue;
            }

            if (!isset($byDay[$day])) {
                $byDay[$day] = [];
            }
            $byDay[$day][] = [
                'id' => (int)($row['id'] ?? 0),
                'name' => $title,
                'type' => 'sahakari',
                'event_type' => (string)($row['event_type'] ?? 'program'),
                'recurrence' => $rec,
            ];
        }
        ksort($byDay);
        return $byDay;
    }
}
