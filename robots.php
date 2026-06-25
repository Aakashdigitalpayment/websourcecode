<?php
/**
 * Dynamic robots.txt — Sitemap URL मा SITE_URL (subdir सहित) मिल्छ
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';

while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: text/plain; charset=UTF-8');

$sitemap = rtrim(SITE_URL, '/') . '/sitemap.xml';

/* ── All crawlers ─────────────────────────────────────────────────────────── */
echo "User-agent: *\n";
echo "Allow: /\n\n";

/* portals & internal dirs */
echo "Disallow: /admin/\n";
echo "Disallow: /member/\n";
echo "Disallow: /includes/\n";
echo "Disallow: /database/\n";
echo "Disallow: /scripts/\n";
echo "Disallow: /logs/\n\n";

/* sensitive upload subdirs */
echo "Disallow: /assets/uploads/kyc/\n";
echo "Disallow: /assets/uploads/loan/\n";
echo "Disallow: /assets/uploads/welfare_claims/\n";
echo "Disallow: /assets/uploads/digital_services/\n";
echo "Disallow: /assets/uploads/grievances/\n";
echo "Disallow: /assets/uploads/appointments/\n\n";

/* utility / token-gated / maintenance pages */
echo "Disallow: /install.php\n";
echo "Disallow: /cron-cleanup.php\n";
echo "Disallow: /attend.php\n";
echo "Disallow: /program-attendance-verify.php\n";
echo "Disallow: /tracker-id-card.php\n";
echo "Disallow: /verify-security.php\n";
echo "Disallow: /verify.php\n\n";

/* ── Search engines: allow full crawl (no extra restrictions) ─────────────── */
echo "User-agent: Googlebot\n";
echo "Allow: /\n\n";

echo "User-agent: Googlebot-Image\n";
echo "Allow: /assets/images/\n";
echo "Allow: /assets/uploads/gallery/\n";
echo "Allow: /assets/uploads/news/\n";
echo "Allow: /assets/uploads/notices/\n";
echo "Disallow: /assets/uploads/kyc/\n";
echo "Disallow: /assets/uploads/loan/\n";
echo "Disallow: /assets/uploads/welfare_claims/\n";
echo "Disallow: /assets/uploads/digital_services/\n\n";

echo "User-agent: Bingbot\n";
echo "Allow: /\n";
echo "Crawl-delay: 5\n\n";

/* ── Block known bad bots ─────────────────────────────────────────────────── */
echo "User-agent: AhrefsBot\n";
echo "Disallow: /\n\n";

echo "User-agent: SemrushBot\n";
echo "Disallow: /\n\n";

echo "User-agent: DotBot\n";
echo "Disallow: /\n\n";

echo "User-agent: MJ12bot\n";
echo "Disallow: /\n\n";

/* ── Sitemap ──────────────────────────────────────────────────────────────── */
echo 'Sitemap: ' . $sitemap . "\n";
