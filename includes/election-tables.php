<?php
/**
 * निर्वाचन जानकारी — तालिकाहरू (schema lock भए पनि idempotent create)
 */
if (!function_exists('ensureElectionTables')) {
    function ensureElectionTables(?PDO $db = null): void
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
            $db->exec("CREATE TABLE IF NOT EXISTS election_cycles (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title_np VARCHAR(200) NOT NULL,
                title_en VARCHAR(200) NOT NULL DEFAULT '',
                intro_np TEXT NULL,
                intro_en TEXT NULL,
                period_label VARCHAR(80) NULL DEFAULT NULL,
                date_from DATE NULL DEFAULT NULL,
                date_to DATE NULL DEFAULT NULL,
                is_published TINYINT(1) NOT NULL DEFAULT 0,
                show_in_navbar TINYINT(1) NOT NULL DEFAULT 0,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_ec_pub_nav (is_published, show_in_navbar),
                INDEX idx_ec_sort (sort_order, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $db->exec("CREATE TABLE IF NOT EXISTS election_milestones (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cycle_id INT NOT NULL,
                event_date DATE NULL DEFAULT NULL,
                title_np VARCHAR(220) NOT NULL,
                title_en VARCHAR(220) NOT NULL DEFAULT '',
                detail_np TEXT NULL,
                detail_en TEXT NULL,
                attachment VARCHAR(500) NULL DEFAULT NULL,
                display_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_em_cycle (cycle_id),
                INDEX idx_em_cycle_ord (cycle_id, display_order, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $done = true;
        } catch (Throwable $e) {
            /* ignore — अर्को request मा पुनः प्रयास हुन सक्छ */
        }
    }
}

/**
 * निर्वाचन — मतदान सम्बन्धी तालिकाहरू (पद, उम्मेदवार, मत, सबमिशन)
 * Idempotent: पहिले नै बनिएको भए केही गर्दैन।
 */
if (!function_exists('ensureElectionVotingTables')) {
    function ensureElectionVotingTables(?PDO $db = null): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        if (!$db && function_exists('getDB')) {
            try { $db = getDB(); } catch (Throwable $e) { return; }
        }
        if (!$db instanceof PDO) {
            return;
        }
        try {
            /* election_cycles मा मतदान window र committee link को लागि columns थप */
            $cols = $db->query("SHOW COLUMNS FROM election_cycles")->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
            $colSet = array_flip($cols);
            $alters = [];
            if (!isset($colSet['vote_start_at'])) $alters[] = "ADD COLUMN vote_start_at DATETIME NULL DEFAULT NULL";
            if (!isset($colSet['vote_end_at']))   $alters[] = "ADD COLUMN vote_end_at DATETIME NULL DEFAULT NULL";
            if (!isset($colSet['voting_enabled'])) $alters[] = "ADD COLUMN voting_enabled TINYINT(1) NOT NULL DEFAULT 0";
            if (!isset($colSet['results_finalized'])) $alters[] = "ADD COLUMN results_finalized TINYINT(1) NOT NULL DEFAULT 0";
            if (!empty($alters)) {
                $db->exec("ALTER TABLE election_cycles " . implode(', ', $alters));
            }

            /* पद master (एकपटक बनाएर सबै चक्रमा reuse — committees जस्तै pattern) */
            $db->exec("CREATE TABLE IF NOT EXISTS election_posts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                designation_id INT NULL DEFAULT NULL,
                title_np VARCHAR(160) NOT NULL,
                title_en VARCHAR(160) NOT NULL DEFAULT '',
                committee_type_id INT NULL DEFAULT NULL,
                default_seats INT NOT NULL DEFAULT 1,
                default_max_votes INT NOT NULL DEFAULT 1,
                display_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_epost_active (is_active, display_order),
                INDEX idx_epost_ctype (committee_type_id),
                INDEX idx_epost_designation (designation_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            /* Backward compatibility: add designation_id if table already exists */
            try {
                $epostCols = $db->query("SHOW COLUMNS FROM election_posts")->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
                if (!in_array('designation_id', $epostCols, true)) {
                    $db->exec("ALTER TABLE election_posts ADD COLUMN designation_id INT NULL DEFAULT NULL AFTER id");
                }
            } catch (Throwable $e) { /* ignore */ }

            /* पद (positions) — हरेक चक्रको आफ्नै पद list, seats r max_votes सँगै */
            $db->exec("CREATE TABLE IF NOT EXISTS election_positions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cycle_id INT NOT NULL,
                title_np VARCHAR(160) NOT NULL,
                title_en VARCHAR(160) NOT NULL DEFAULT '',
                seats INT NOT NULL DEFAULT 1,
                max_votes_per_voter INT NOT NULL DEFAULT 1,
                committee_type_id INT NULL DEFAULT NULL,
                display_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ep_cycle (cycle_id),
                INDEX idx_ep_cycle_ord (cycle_id, display_order, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            /* उम्मेदवार */
            $db->exec("CREATE TABLE IF NOT EXISTS election_candidates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cycle_id INT NOT NULL,
                position_id INT NOT NULL,
                name VARCHAR(160) NOT NULL,
                name_en VARCHAR(160) NOT NULL DEFAULT '',
                photo VARCHAR(500) NULL DEFAULT NULL,
                bio_np TEXT NULL,
                bio_en TEXT NULL,
                phone VARCHAR(20) NULL DEFAULT NULL,
                email VARCHAR(120) NULL DEFAULT NULL,
                address VARCHAR(255) NULL DEFAULT NULL,
                symbol_no VARCHAR(20) NULL DEFAULT NULL,
                display_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_ec_cycle (cycle_id),
                INDEX idx_ec_position (position_id),
                INDEX idx_ec_pos_ord (position_id, display_order, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            /* एक सदस्यले एक चक्रमा एकपटक मात्र submit गर्न पाउने track */
            $db->exec("CREATE TABLE IF NOT EXISTS election_vote_submissions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cycle_id INT NOT NULL,
                member_id INT NOT NULL,
                submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                source VARCHAR(30) NOT NULL DEFAULT 'member_portal',
                proof_type VARCHAR(30) NOT NULL DEFAULT '',
                verified_by_admin_id INT NULL DEFAULT NULL,
                verified_by_name VARCHAR(120) NOT NULL DEFAULT '',
                note VARCHAR(500) NOT NULL DEFAULT '',
                ip VARCHAR(64) NULL DEFAULT NULL,
                user_agent VARCHAR(255) NULL DEFAULT NULL,
                UNIQUE KEY uniq_cycle_member (cycle_id, member_id),
                INDEX idx_evs_cycle (cycle_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            /* व्यक्तिगत मत record (count को लागि; member_id पनि राख्या — audit) */
            $db->exec("CREATE TABLE IF NOT EXISTS election_votes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cycle_id INT NOT NULL,
                position_id INT NOT NULL,
                candidate_id INT NOT NULL,
                member_id INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_member_pos_cand (member_id, position_id, candidate_id),
                INDEX idx_ev_cycle (cycle_id),
                INDEX idx_ev_candidate (candidate_id),
                INDEX idx_ev_position (position_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            /* Performance indexes — ठूलो data हुँदा aggregate/lookup छिटो होस् */
            $addIdx = static function (PDO $db, string $table, string $idx, string $cols): void {
                try {
                    $exists = $db->prepare("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=?");
                    $exists->execute([$table, $idx]);
                    if ((int)$exists->fetchColumn() === 0) {
                        $db->exec("CREATE INDEX {$idx} ON {$table} ({$cols})");
                    }
                } catch (Throwable $e) { /* ignore */ }
            };
            $addIdx($db, 'election_votes', 'idx_ev_cycle_cand', 'cycle_id, candidate_id');
            $addIdx($db, 'election_votes', 'idx_ev_cycle_pos', 'cycle_id, position_id');
            $addIdx($db, 'election_candidates', 'idx_ec_cycle_active', 'cycle_id, is_active');
            $addIdx($db, 'election_positions', 'idx_ep_cycle_active', 'cycle_id, is_active');

            try {
                $evsCols = $db->query("SHOW COLUMNS FROM election_vote_submissions")->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
                $evsColSet = array_flip($evsCols);
                $evsAlters = [];
                if (!isset($evsColSet['source'])) $evsAlters[] = "ADD COLUMN source VARCHAR(30) NOT NULL DEFAULT 'member_portal' AFTER submitted_at";
                if (!isset($evsColSet['proof_type'])) $evsAlters[] = "ADD COLUMN proof_type VARCHAR(30) NOT NULL DEFAULT '' AFTER source";
                if (!isset($evsColSet['verified_by_admin_id'])) $evsAlters[] = "ADD COLUMN verified_by_admin_id INT NULL DEFAULT NULL AFTER proof_type";
                if (!isset($evsColSet['verified_by_name'])) $evsAlters[] = "ADD COLUMN verified_by_name VARCHAR(120) NOT NULL DEFAULT '' AFTER verified_by_admin_id";
                if (!isset($evsColSet['note'])) $evsAlters[] = "ADD COLUMN note VARCHAR(500) NOT NULL DEFAULT '' AFTER verified_by_name";
                if ($evsAlters) {
                    $db->exec("ALTER TABLE election_vote_submissions " . implode(', ', $evsAlters));
                }
                $addIdx($db, 'election_vote_submissions', 'idx_evs_cycle_source', 'cycle_id, source');
            } catch (Throwable $e) { /* ignore */ }

            /* election_positions मा post_id (master link) — idempotent ALTER */
            try {
                $epCols = $db->query("SHOW COLUMNS FROM election_positions")->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
                if (!in_array('post_id', $epCols, true)) {
                    $db->exec("ALTER TABLE election_positions ADD COLUMN post_id INT NULL DEFAULT NULL AFTER cycle_id");
                }
                $addIdx($db, 'election_positions', 'idx_ep_post', 'post_id');
            } catch (Throwable $e) { /* ignore */ }

            $done = true;
        } catch (Throwable $e) {
            /* ignore */
        }
    }
}

/**
 * Nepal Time (Asia/Kathmandu) मा हाल मतदान window खुलेको छ कि भनेर check
 */
if (!function_exists('isElectionVotingOpen')) {
    function isElectionVotingOpen(array $cycle): bool
    {
        if (empty($cycle['voting_enabled'])) return false;
        $start = trim((string)($cycle['vote_start_at'] ?? ''));
        $end   = trim((string)($cycle['vote_end_at'] ?? ''));
        if ($start === '' || $end === '') return false;
        try {
            $tz = new DateTimeZone('Asia/Kathmandu');
            $now = new DateTime('now', $tz);
            $s = new DateTime($start, $tz);
            $e = new DateTime($end, $tz);
            return ($now >= $s && $now <= $e);
        } catch (Throwable $e) {
            return false;
        }
    }
}

/**
 * Designations master (positions) — admin use across staff/team/committees.
 * This file is loaded by multiple admin pages, so helpers live here.
 */
if (!function_exists('ensureDesignationsTable')) {
    function ensureDesignationsTable(?PDO $db = null): void
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
            $db->exec("CREATE TABLE IF NOT EXISTS designations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title_np VARCHAR(160) NOT NULL,
                title_en VARCHAR(160) NOT NULL DEFAULT '',
                category VARCHAR(50) NOT NULL DEFAULT 'committee',
                display_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_desig_cat_ord (category, display_order, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

            // Backward compatible: add missing columns if schema is older.
            $cols = $db->query("SHOW COLUMNS FROM designations")->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
            $colSet = array_flip($cols);

            if (!isset($colSet['title_np'])) {
                $db->exec("ALTER TABLE designations ADD COLUMN title_np VARCHAR(160) NOT NULL DEFAULT ''");
            }
            if (!isset($colSet['title_en'])) {
                $db->exec("ALTER TABLE designations ADD COLUMN title_en VARCHAR(160) NOT NULL DEFAULT ''");
            }
            if (!isset($colSet['category'])) {
                $db->exec("ALTER TABLE designations ADD COLUMN category VARCHAR(50) NOT NULL DEFAULT 'committee'");
            }
            if (!isset($colSet['display_order'])) {
                $db->exec("ALTER TABLE designations ADD COLUMN display_order INT NOT NULL DEFAULT 0");
            }
            if (!isset($colSet['is_active'])) {
                $db->exec("ALTER TABLE designations ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1");
            }
            if (!isset($colSet['updated_at'])) {
                $db->exec("ALTER TABLE designations ADD COLUMN updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP");
            }

            $done = true;
        } catch (Throwable $e) {
            // Best-effort: if schema creation fails, callers will just show empty dropdowns.
        }
    }
}

if (!function_exists('designationCategories')) {
    function designationCategories(): array
    {
        // Keys must match what admin pages pass into fetchDesignations(...).
        return [
            'committee' => 'समिति',
            'staff' => 'कर्मचारी',
        ];
    }
}

if (!function_exists('fetchDesignations')) {
    function fetchDesignations(?PDO $db = null, array $categories = []): array
    {
        if (!($db instanceof PDO) && function_exists('getDB')) {
            try {
                $db = getDB();
            } catch (Throwable $e) {
                return [];
            }
        }

        if (!($db instanceof PDO)) {
            return [];
        }

        ensureDesignationsTable($db);

        $cats = [];
        foreach ($categories as $c) {
            if (is_string($c) && trim($c) !== '') {
                $cats[] = trim($c);
            }
        }

        try {
            if (empty($cats)) {
                return $db->query(
                    "SELECT id, title_np, title_en, category, display_order, is_active
                     FROM designations
                     WHERE is_active=1
                     ORDER BY category, display_order, id"
                )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            }

            $placeholders = implode(',', array_fill(0, count($cats), '?'));
            $stmt = $db->prepare(
                "SELECT id, title_np, title_en, category, display_order, is_active
                 FROM designations
                 WHERE is_active=1 AND category IN ($placeholders)
                 ORDER BY category, display_order, id"
            );
            $stmt->execute($cats);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}
