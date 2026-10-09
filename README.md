# Aakash Cooperative — Web Application

**आकाश सहकारी** को लागि pure **PHP 8.0+ / MySQL** digital platform।  
Bootstrap 5 UI · कुनै build step छैन।

Public website · Admin panel · Member portal — एउटै MySQL database।

**Docs rule:** product / theme / SSOT truth lives **only in this file**.  
Scoped notes (not duplicates): `deploy/README.md` (Nginx), `assets/css/app-sections/README.md` (CSS extract shadows).  
Admin how-to UI: `admin/help-guide.php` (keep in sync with the SSOT table below).

---

## Tech stack

| Layer | Technology |
|-------|------------|
| Language | PHP **8.2** (8.0 minimum) |
| Database | MySQL / MariaDB · `utf8mb4_unicode_ci` |
| Frontend | Bootstrap 5 + `assets/css/` patches + Font Awesome 6 (self-hosted) |
| Fonts | Inter · Plus Jakarta Sans · Noto Sans Devanagari — **one** Google Fonts load via `coopThemeGoogleFontsHtml()` in `includes/theme-assets.php` |
| Icons | Lucide (`assets/vendor/lucide.min.js` + `lucide-icon-utils.css` in late bundles) + Font Awesome 6 |
| JS | Vanilla JS (no React / Vue; jQuery only when Nepali datepicker needed) |
| Hosting | cPanel / Apache · PHP-FPM (Nginx: see `deploy/README.md`) |
| Cache bust | `filemtime()` on CSS/JS links via `coopThemeLink()` |

---

## Project layout

```
/
├── _bootstrap.php          # Boot — sessions, encoding, config
├── index.php               # Public homepage
├── *.php                   # Public pages (about, services, team, gallery…)
├── install.php             # First-run installer — DELETE after use
├── cron-cleanup.php        # Daily cron
├── sw.js / manifest.php    # PWA (legacy /manifest.json rewrites here)
│
├── admin/                  # Admin panel (+ help-guide.php)
├── member/                 # Member portal
├── includes/               # Shared PHP (config, header, footer, theme-assets…)
├── core/                   # Cross-cutting helpers
├── assets/css|js|vendor/   # Front-end assets
├── database/install.sql    # Fresh-install schema
├── deploy/                 # Nginx snippets + README
└── scripts/                # Smoke tests + CSS bundle builders
```

---

## CSS architecture (important)

All CSS is loaded by `includes/theme-assets.php` → `coopThemeHeadAssets($panel)`.  
**Do not hard-code `<link>` tags** for theme CSS — use this loader.

### Load order (simplified)

```
Bootstrap + Font Awesome (vendor)
Google Fonts (coopThemeGoogleFontsHtml — do not add a second stack)
app-public.css | app-admin.css | app-member.css   ← panel base (do not rewrite for polish)
global.css · forms-tables.css
(admin-only mid patches)
global-theme.php          ← DB brand colours (inline)
★ ONE *-late-bundle.css   ← built from polish sources (see below)
```

**Late polish sources** (`premium-ui.css`, `*-shell-polish.css`, `ui-readability-safe-patch.css`,
`admin-ux-deep-patch.css`, `final-ui-polish.css`, …) are **not** linked individually.
Edit the source file, then run:

```bash
python3 scripts/build-css-late-bundles.py
```

Do **not** hand-edit `assets/css/*-late-bundle.css` (AUTO-GENERATED).
`python3 scripts/build-css-late-bundles.py --check` (also run by `smoke-css-order.php`) fails if a bundle drifted from its sources.

**Dead-rule pruning of the frozen bases** (the one sanctioned edit to `app-*.css`):
`python3 scripts/prune-app-css-dead-rules.py app-core.css` (dry run) → `--write`. A rule goes only when every
selector consists solely of classes that appear nowhere in PHP/JS/JSON/vendor JS (dash-prefixes and
`'badge-' . $x` concatenations count as used). Removed rules are listed in `scripts/reports/<sheet>.pruned.txt`.
After `--write`, regenerate the shadows (`scripts/extract-app-css-section.py --write --all-{first,second,third}-pr`)
and verify with full-page screenshot diffs (public + member + admin, 1366 and 390) before committing.

`assets/css/app-sections/` holds **non-live** extract shadows of frozen `app-*.css` blocks — see that folder’s README; never load them in production theme order.

### Safe theme / UX rules

- **New visual polish** → `final-ui-polish.css` (or panel `*-shell-polish.css` / `admin-ux-deep-patch.css`).
- **Do not rewrite** legacy `app-public.css` / `app-admin.css` for routine polish.
- Prefer additive patches; keep Devanagari safe (`min-height` + padding, not fixed heights).
- Team photo cards (board / management / committees) share one size via `final-ui-polish.css`.
- Public section titles / CTAs (reports, news “थप पढ्नुहोस्”, about intro, career hero, sahakari-patro) stay **center-aligned** with the rest of the card system; history divider stays under its title (left with the heading).
- Brand tokens: Admin Settings → `global-theme.php` (`--primary-color`, `--secondary-color`, …). Contact panel icons use `--contact-icon-on-primary` (secondary hue, **WCAG-safe** on the green panel).

---

## Performance notes (current)

- Homepage + navbar/footer data: short TTL file cache (`includes/simple-cache.php`); clear via `clearHomepageCache()` on admin CRUD.
- **Cache key SSOT:** `coop_homepage_cache_keys()` in `simple-cache.php` — add new public `getCachedData('…')` keys there (smoke-public-cache enforces).
- Notice ticker/popup cache key is **date-stamped** (`nav_notices_extra_v2_YYYY-MM-DD`); clear also covers yesterday/tomorrow for midnight/TZ edges.
- Growing lists: public pagination (notices, news, gallery) + admin hard `LIMIT`s.
- Public content pages skip unused jQuery/datepicker and form-validation JS where safe.
- Schema helpers: `dbTableExists()` / `dbColumnExists()` avoid repeated `SHOW` probes.

---

## Product SSOT notes (keep in sync with admin Help Guide)

| Area | Rule |
|------|------|
| **Member ID** | `sadasyata_number` / CSV `member_id` — single key across Members, KYM, import |
| **Names** | `full_name` / `members.name` = English (CVV); `name_np` = Nepali (KYM `full_name`) |
| **Import DOB** | CSV `dob` / `dob_bs` = **बि.सं.** → stored as AD in `members.dob` (+ KYM `dob_bs` filled); use `dob_ad` only for Gregorian sheets |
| **Dates in UI** | Nepali lang → Nepali datepicker (BS); DB DATE columns stay Gregorian AD |
| **Brand colours** | Admin Settings → `global-theme.php`. Contact icons: `--contact-icon-on-primary` (brand secondary + WCAG on green) |
| **Fonts / Lucide** | Fonts only via `theme-assets.php`; Lucide size utils in late bundles — no second icon/font stacks on pages |
| **Welfare types** | `welfare_claim_types` / member-welfare catalog only — no duplicate type fields on IP |
| **Institutional राहत** | Pre-portal totals: Admin → **राहत Opening** (`admin/institutional-welfare-opening.php`). Monthly IP form auto-fills month-new + cumulative (**editable**). Public prefers saved snapshot |
| **Notice popup** | Admin Notices → Show as popup → optional **पप-अप समाप्त मिति (बि.सं.)** → DB `popup_expires_at` (AD). Empty = no expiry. After that day, public popup auto-hides (no re-edit) |
| **मासिक बचत** | `members.monthly_saving_regular` (1 = नियमित, 0 = नियमित नभएको, NULL = नतोकिएको) — one helper `includes/member-monthly-saving.php`. Edited from Members, KYM (writes the linked member), member import column `monthly_saving`, **field-only import** (Member ID + monthly_saving; touches nothing else) and Program Registration Desk (inline save) |
| **Notice date** | Exception to AD storage: `notices.notice_date` holds **बि.सं.** (admin form is BS). Read via `coop_notice_date_bs()` (display) / `coop_notice_date_ad()` (sitemap, JSON-LD); save via `coop_notice_date_normalize_input()` — legacy AD rows still display correctly |
| **Admin buttons & layout** | One button system for every admin page (`body[class*="admin-page-"]` in `admin-ux-deep-patch.css`): solid green/red/amber/slate, neutral outline, green/red/amber outline; custom gradient buttons (`.dash-btn-*`) keep their look. `.admin-wrapper > .main-content { min-width:0 }` keeps pages within the screen (wide tables scroll inside their wrapper) |
| **Admin forms (add / edit)** | One form system for every admin form, tab pane and modal (`ADMIN FORM SYSTEM` block at the end of `admin-ux-deep-patch.css`): labels (one size, no uppercase, no per-page green/grey), controls, help text, switch chips, the five form-header classes, section titles, save/cancel action row and page tabs. Scoped to POST forms — GET filter bars are untouched. New admin forms need no page CSS: `form` + `row g-3` + `.form-label` + `.form-switch` + a last `div` holding the submit button |
| **Program module UI** | Form sections, switches, `prog-*` helpers for `admin-page-program*` pages. All time fields are one select style: `programScheduleTimeSelectOptions()` (event) / `programWindowTimeSelectHtml()` (window + QR, posts `H:i`) |
| **Custom roles (अनुमति matrix)** | Superadmin → `admin/admin-roles.php`: per sidebar menu हेर्ने/थप्ने/सम्पादन/हटाउने; assign in Admin व्यवस्थापन (`admin_users.custom_role_id`). Engine `includes/admin-permissions.php` (registry = sidebar; sub-pages via aliases), enforced in `admin-page-boot.php`; `has_role()` follows the matrix for those users. Superadmin pages never grantable. Users without a custom role unchanged |
| **Login lockout** | `checkLoginAttempts()` is per account: same user+IP 5 / same user 10 / same IP across users 30 (spray) in 15 min — one person's typos never lock the office network |
| **Admin roles** | System/config pages (Settings, App Features, AI/Notification settings & templates, Audit/Error log) need **admin+** — one map `coop_admin_page_min_role()` in `includes/auth-roles.php`, enforced by `admin-page-boot.php`; sidebar hides them via `coop_admin_nav_allowed()`. Editor/Staff keep content pages |
| **Reports / IP member gate** | `access_level` + `includes/public-member-access.php`; files via `report-file.php` / `institutional-profile-file.php` |
| **Content stores** | `useful_links` (footer/admin Useful Links); public FAQs vs `chatbot_faqs` (Help Center) — see `includes/data-ssot.php`; do not dual-write legacy tables |

Admin how-to: `admin/help-guide.php` (sections कल्याण, Members import, Notices popup, Settings → Institutional).

---

## JavaScript (`assets/js/`)

| File | Purpose |
|------|---------|
| `main.js` | UI, early loader hide, AOS fail-safe |
| `search-improved.js` | Search overlay |
| `form-validation.js` | Form pages only (skipped on many content pages) |
| `init-uniformity.js` | Datepicker init + a11y helpers |
| `kyc-capture.js` | KYC camera capture |
| `pwa-register.js` | Service worker |
| `modal-focus-trap.js` | Modal a11y |
| `totp-qr.js` | QR drawn locally (+ `assets/vendor/qrcode-generator.js`): 2FA setup (`twoFaQrImgTag()`), any `img[data-qr]` (`coop_qr_img_tag()` in `includes/qr-local.php`, e.g. program attendance QR) and `window.coopQrDataUrl()` — never send QR data to an external API |

Icon swaps after page load (menu/x, eye, sun/moon, bell): use `window.coopSetLucide(holder, name)` from `coopThemeLucideInit()` — Lucide turns `<i>` into a read-only `<svg>`.

---

## Database & setup (नयाँ client)

सरल path — घुमाउरो wizard चाहिँदैन:

1. Upload repo → `public_html/`
2. cPanel मा MySQL database + user बनाउने
3. `includes/database.local.php.example` → `database.local.php` (credentials)
4. `includes/superadmin-config.local.php.example` → `superadmin-config.local.php` (password)
5. `/admin/` मा login — tables/columns auto (`ensure*Tables`)

Optional: browser wizard `install.php` (पछि lock/delete)। Emergency मात्र: `admin/db-setup.php`।  
PHP 8.2 recommended (8.0+). Cron: `php /path/to/cron-cleanup.php` daily.

---

## Counts (approx.)

| Item | Count |
|------|-------|
| PHP files | ~380 |
| CSS files (`assets/css/**/*.css`) | ~75 (incl. late bundles + app-sections shadows) |
| JS files (`assets/js/*.js`) | 13 |
| DB tables (`install.sql`) | ~103 |

---

## Related docs (only these — no extra product MD)

| Path | Purpose |
|------|---------|
| **`README.md`** (this file) | Product + CSS + SSOT — **single source** |
| `admin/help-guide.php` | Admin click-path how-to (Nepali/EN UI) |
| `deploy/README.md` | Nginx security include only |
| `assets/css/app-sections/README.md` | Non-live CSS extract tooling only |

---

*Internal property of Aakash Cooperative — Not for redistribution.*  
**Last updated: 2026-09-18** (popup expiry, राहत Opening, import DOB BS, brand/contact icons, center UI polish)
| **Admin spacing** | One gap token: `--adm-gap` (1rem) between every top-level block, `--adm-gap-lg` (1.5rem) after stat strips, page padding from `.page-content` only (inner `.container-fluid` padding zeroed) — `ADMIN SPACING RHYTHM` block in `admin-ux-deep-patch.css`. Do not add `mb-*` / `py-*` utilities on page-level blocks to tune spacing; change the token |
| **Member spacing** | `--mem-gap` (1rem) between every top-level block of `.mem-main-content` (`member-shell-polish.css`); page markup should not carry `mb-*` / `mt-*` on those blocks |
| **Spacing scale (single source)** | `global.css` `--space-*` feeds `--adm-gap` / `--adm-gap-lg` (admin), `--mem-gap` (member) and `--pub-section-y` (public `.section-padding` rhythm, `final-ui-polish.css`). Intentional bands keep their own padding: page banners, `.cta-section`, filter bars (`py-4`), page-specific heroes |
| **Component tokens (single source)** | `global.css`: `--card-radius` (12px, every card on every panel via `final-ui-polish.css`; member `--mem-radius` reads it), `--control-radius` (10px). Admin: `--adm-control-h` (40px) = one height for inputs, selects, filter controls and buttons (`admin-ux-deep-patch.css`). Runtime audit: all design tokens resolve to the same value on public, member and admin |
