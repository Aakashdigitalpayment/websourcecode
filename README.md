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
- Notice ticker/popup cache key is **date-stamped** (`nav_notices_extra_v2_YYYY-MM-DD`) so popup expiry rolls over at midnight.
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
