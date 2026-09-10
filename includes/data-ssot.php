<?php
/**
 * Data store single sources of truth (SSOT) — documentation + guards.
 *
 * FAQ stores (do not merge tables; do not dual-write):
 * - faqs              → public FAQ page (faqs.php / admin/faqs.php)
 * - chatbot_faqs      → Help Center + AI chat context (admin/help-center.php)
 *
 * Links:
 * - useful_links      → SSOT for footer / admin Useful Links (admin/useful-links.php)
 * - important_links   → legacy schema only; no admin writes. Public
 *                       important-links.php is static curated markup (not this table).
 *
 * Member photos:
 * - Prefer members.kyc_application_id → kyc_applications.photo
 * - Soft email/mobile avatar fallbacks are discouraged (wrong-member risk).
 */
declare(strict_types=1);

if (!defined('COOP_SSOT_PUBLIC_FAQS')) {
    define('COOP_SSOT_PUBLIC_FAQS', 'faqs');
}
if (!defined('COOP_SSOT_CHATBOT_FAQS')) {
    define('COOP_SSOT_CHATBOT_FAQS', 'chatbot_faqs');
}
if (!defined('COOP_SSOT_USEFUL_LINKS')) {
    define('COOP_SSOT_USEFUL_LINKS', 'useful_links');
}
