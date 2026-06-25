<?php
/**
 * Dynamic XML sitemap — SITE_URL अनुसार <loc> (live domain मिल्छ)
 * robots.txt / Search Console: sitemap.php
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: application/xml; charset=UTF-8');

$base = rtrim(SITE_URL, '/');
$today = date('Y-m-d');

/** @return array<int, array{loc:string,lastmod:string,changefreq:string,priority:string}> */
$rows = [];

$add = static function (string $path, string $priority, string $changefreq) use (&$rows, $base, $today): void {
    $path = ltrim($path, '/');
    $loc = $path === '' ? $base . '/' : $base . '/' . $path;
    $rows[] = [
        'loc' => $loc,
        'lastmod' => $today,
        'changefreq' => $changefreq,
        'priority' => $priority,
    ];
};

$staticPhp = [
    /* ── Core / high-priority ───────────────────────────────────────────── */
    ['', '1.0', 'weekly'],
    ['about.php', '0.9', 'monthly'],
    ['services.php', '0.9', 'weekly'],
    ['interest-rates.php', '0.9', 'weekly'],
    ['contact.php', '0.85', 'yearly'],

    /* ── News & announcements ───────────────────────────────────────────── */
    ['notices.php', '0.85', 'weekly'],
    ['news.php', '0.85', 'weekly'],
    ['career.php', '0.85', 'weekly'],
    ['cooperative-programs.php', '0.8', 'weekly'],

    /* ── Member services / CTAs ─────────────────────────────────────────── */
    ['loan-apply.php', '0.85', 'monthly'],
    ['online-account.php', '0.85', 'monthly'],
    ['appointment.php', '0.75', 'monthly'],
    ['digital-services.php', '0.75', 'monthly'],
    ['online-kyc.php', '0.75', 'monthly'],
    ['service-centers.php', '0.75', 'monthly'],
    ['application-tracker.php', '0.75', 'monthly'],
    ['election-information.php', '0.75', 'weekly'],
    ['institutional-profile.php', '0.75', 'monthly'],

    /* ── Organisation info ──────────────────────────────────────────────── */
    ['team.php', '0.75', 'monthly'],
    ['committees.php', '0.7', 'monthly'],
    ['gallery.php', '0.75', 'weekly'],
    ['partner-facilities.php', '0.7', 'monthly'],
    ['downloads.php', '0.7', 'monthly'],

    /* ── Tools & utilities ──────────────────────────────────────────────── */
    ['exchange-rate.php', '0.65', 'daily'],
    ['emi-calculator.php', '0.6', 'yearly'],
    ['date-converter.php', '0.55', 'yearly'],

    /* ── Secondary / engagement ─────────────────────────────────────────── */
    ['faqs.php', '0.7', 'monthly'],
    ['reports.php', '0.65', 'monthly'],
    ['auction.php', '0.65', 'weekly'],
    ['member-welfare.php', '0.65', 'monthly'],
    ['grievance.php', '0.65', 'monthly'],
    ['awards.php', '0.65', 'monthly'],
    ['vendor-enlistment.php', '0.65', 'monthly'],
    ['member-survey.php', '0.65', 'monthly'],
    ['important-links.php', '0.55', 'monthly'],
];

foreach ($staticPhp as [$path, $prio, $freq]) {
    if ($path !== '' && !is_file(__DIR__ . '/' . $path)) {
        continue;
    }
    $add($path, $prio, $freq);
}

try {
    $db = getDB();
    $st = $db->query("SELECT slug, updated_at FROM pages WHERE is_active = 1 AND slug <> ''");
    if ($st) {
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $slug = (string) ($p['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $lm = $today;
            if (!empty($p['updated_at'])) {
                $t = strtotime((string) $p['updated_at']);
                if ($t !== false) {
                    $lm = date('Y-m-d', $t);
                }
            }
            $q = http_build_query(['slug' => $slug], '', '&', PHP_QUERY_RFC3986);
            $rows[] = [
                'loc' => $base . '/page.php?' . $q,
                'lastmod' => $lm,
                'changefreq' => 'monthly',
                'priority' => '0.7',
            ];
        }
    }

    $nst = $db->query("SELECT id, created_at FROM news WHERE is_active = 1 ORDER BY id DESC LIMIT 200");
    if ($nst) {
        foreach ($nst->fetchAll(PDO::FETCH_ASSOC) as $n) {
            $id = (int) ($n['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $lm = $today;
            if (!empty($n['created_at'])) {
                $t = strtotime((string) $n['created_at']);
                if ($t !== false) {
                    $lm = date('Y-m-d', $t);
                }
            }
            $rows[] = [
                'loc' => $base . '/news-detail.php?id=' . $id,
                'lastmod' => $lm,
                'changefreq' => 'weekly',
                'priority' => '0.72',
            ];
        }
    }

    $cst = $db->query("SELECT id, updated_at, created_at FROM careers WHERE is_active = 1 ORDER BY id DESC LIMIT 80");
    if ($cst) {
        foreach ($cst->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $id = (int) ($c['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $raw = $c['updated_at'] ?? $c['created_at'] ?? '';
            $lm = $today;
            if ($raw !== '') {
                $t = strtotime((string) $raw);
                if ($t !== false) {
                    $lm = date('Y-m-d', $t);
                }
            }
            $rows[] = [
                'loc' => $base . '/career-detail.php?id=' . $id,
                'lastmod' => $lm,
                'changefreq' => 'weekly',
                'priority' => '0.78',
            ];
        }
    }
} catch (Throwable $e) {
    /* DB छैन वा टेबल missing — static URLs मात्र */
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

$seen = [];
foreach ($rows as $r) {
    $u = $r['loc'];
    if (isset($seen[$u])) {
        continue;
    }
    $seen[$u] = true;
    echo '  <url>' . "\n";
    echo '    <loc>' . htmlspecialchars($u, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>' . "\n";
    echo '    <lastmod>' . htmlspecialchars($r['lastmod'], ENT_XML1, 'UTF-8') . '</lastmod>' . "\n";
    echo '    <changefreq>' . htmlspecialchars($r['changefreq'], ENT_XML1, 'UTF-8') . '</changefreq>' . "\n";
    echo '    <priority>' . htmlspecialchars($r['priority'], ENT_XML1, 'UTF-8') . '</priority>' . "\n";
    echo '  </url>' . "\n";
}

echo '</urlset>';
