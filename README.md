# Aakash Cooperative — Web Application

**आकाश सहकारी (Aakash Cooperative)** को लागि बनाइएको complete digital platform।  
Pure **PHP 8.0+ / MySQL** — Bootstrap 5 UI — कुनै build step छैन।

Public website · Admin panel · Member portal — एउटै MySQL database।

---

## 1. Tech Stack

| Layer | Technology |
|-------|------------|
| Language | PHP **8.2** (8.0 minimum) |
| Database | MySQL / MariaDB · `utf8mb4_unicode_ci` |
| Frontend | Bootstrap 5 + custom CSS (`assets/css/`) + Font Awesome 6 |
| Fonts | **Inter** (body) · **Plus Jakarta Sans** (headings) · **Noto Sans Devanagari** (नेपाली) |
| Icons | Lucide (self-hosted via `assets/vendor/lucide.min.js`) + Font Awesome 6 |
| JS | Vanilla JS — no React / Vue / jQuery |
| Hosting | cPanel shared hosting (Apache / Nginx + PHP-FPM) |
| Build | **None.** Cache-busting via `filemtime()` on every CSS link |
| Version | **7.0** (Premium UI · 2026-06-25) |

---

## 2. Project Structure

```
/
├── _bootstrap.php               # PHP bootloader — sessions, encoding, config
├── index.php                    # Public homepage
├── *.php                        # 50+ public entry-points (about, services, news…)
├── install.php                  # ⚠ First-run installer — DELETE after first use
├── cron-cleanup.php             # Daily cron — run via cPanel Cron Jobs
├── sw.js                        # PWA service worker
├── manifest.json                # PWA manifest
├── version.txt                  # App version string
│
├── admin/                       # Admin panel (90+ PHP entry-points)
│   ├── index.php                # Admin login page
│   ├── dashboard.php            # Dashboard with stat cards
│   ├── pages.php                # CMS page editor
│   ├── hrm-dashboard.php        # HRM module entry
│   ├── election-*.php           # Election management
│   ├── _partials/               # header.php, footer.php (members/applications)
│   ├── includes/                # admin-header.php, admin-ui.php, admin-footer.php
│   └── api/                     # Admin AJAX endpoints
│
├── member/                      # Member portal (30+ PHP entry-points)
│   ├── index.php                # Member dashboard
│   ├── login.php / logout.php
│   ├── kyc.php                  # KYC application
│   ├── loan-apply.php
│   ├── welfare.php
│   ├── election-vote.php        # Secure online voting
│   └── includes/                # chrome.php, chrome-foot.php, helpers
│
├── includes/                    # Shared PHP includes (52 files)
│   ├── config.php               # Global config loader
│   ├── database.dist.php        # DB template → rename to database.local.php
│   ├── header.php               # Public site HTML head + nav (1763 lines)
│   ├── footer.php               # Public site footer
│   ├── theme-assets.php         # ⭐ CSS load-order controller (all panels)
│   ├── auth-roles.php           # ACL / permission helpers
│   ├── safe-query.php           # SQL injection protection
│   ├── credentials-crypto.php   # PII encryption helpers
│   ├── nepali-bs-convert.php    # BS ↔ AD calendar
│   ├── nepal-address.php        # Province / district / municipality data
│   └── components/              # Reusable PHP/HTML components (stat-card.php…)
│
├── core/
│   └── init.php                 # Cross-cutting helpers (re-exported by _bootstrap.php)
│
├── assets/
│   ├── css/                     # 18 CSS files (see Section 3)
│   ├── js/                      # 11 JS files (see Section 4)
│   ├── images/                  # Logos, favicons, placeholders
│   └── vendor/                  # bootstrap.min.css, lucide.min.js, datatables/…
│
├── database/
│   └── install.sql              # 74 CREATE TABLE statements — fresh-install schema
│
└── public/                      # Static fallbacks (e.g. icon.svg favicon)
```
---

## 3. CSS Architecture (CRITICAL — read before touching CSS)

All CSS is loaded by `includes/theme-assets.php` → `coopThemeHeadAssets($panel)`.  
**Never hard-code `<link>` tags** for CSS — always go through this function.

### Full load order (every page)

```
Bootstrap 5 (vendor)
Font Awesome 6 (CDN)
Google Fonts: Inter · Plus Jakarta Sans · Noto Sans Devanagari
Nepali Datepicker CSS (vendor)
app-core.css           ← universal base (tokens, animations, shared components)
─── panel-specific (exactly one) ───────────────────────────────────
app-public.css         ← public site  (23 845 lines)
app-admin.css          ← admin panel  (17 041 lines)
app-member.css         ← member portal (6 782 lines)
─────────────────────────────────────────────────────────────────────
global.css             ← shared CSS variables + base resets
forms-tables.css       ← form + table shared styles
admin-ui-unified.css   ← admin UI component system
admin-auth-login-fixes.css  ← admin login page fixes       (admin-auth only)
ui-ux-enhancements.css ← cross-panel UX polish
admin-layout-icon-fixes.css ← admin icon layout            (admin/shell only)
bootstrap-admin-overrides.css ← Bootstrap reset for admin  (admin/shell only)
admin-icon-colors-priority.css ← icon colour patch         (admin/shell only)
*-shell-polish.css     ← public / member / admin shell polish (panel-specific)
global-theme.php       ← ⭐ DB-driven brand colours (inline <style>, LAST-1)
premium-ui.css         ← ⭐⭐ Premium font + shape polish (LAST — wins all)
```

### CSS variable sources

| File | Owns |
|------|------|
| `global.css` | `--primary`, `--secondary`, `--bg-page`, `--font-primary`, `--radius-md`… |
| `assets/css/global-theme.php` | DB-driven `--primary-color`, `--text-on-*`, surface/text tokens (inline, dynamic) |
| `premium-ui.css` | `--prem-font-head`, `--prem-font-body`, `--prem-sh-*` (own namespace, never conflicts) |

### Rules

- **Add new theme tweaks** → `premium-ui.css` (loaded last, owns nothing critical).
- **Panel-specific layout** → respective `app-*.css`.
- **Never** add ad-hoc `<style>` tags in PHP pages — use the cascade.
- **Never** add `!important` to `app-public.css` / `app-admin.css` — it will be overridden by files loaded later.
- `premium-ui.css` uses `!important` only on `font-family` (needed to beat 'Mukta' in older rules).

### Devanagari safety rules

- Never set fixed `height: Xpx` on buttons/badges/chips — use `min-height` + `padding-block`.
- Icon size inside dropdowns/buttons must be capped at `0.92em–0.95em`.
- Always include `Noto Sans Devanagari` as a fallback in every font-family stack.

---

## 4. JavaScript Files (`assets/js/`)

| File | Purpose |
|------|---------|
| `main.js` | General UI — scroll effects, dropdowns, AOS init |
| `v9-mobile-fix.js` | Mobile nav drawer — controls `.nav-open`, `.mobile-nav-open` |
| `search-improved.js` | Search overlay + voice search |
| `scroll-accessibility.js` | Floating accessibility panel |
| `coop-mobile.js` | PWA-related mobile UI |
| `form-validation.js` | Client-side form validation |
| `kyc-capture.js` | KYC photo capture (camera API) |
| `pull-to-refresh.js` | Mobile pull-to-refresh |
| `pwa-register.js` | Service worker registration |
| `init-uniformity.js` | Page-load UI init (Lucide icon bootstrap) |
| `nepali.datepicker.min.js` | Nepali BS date picker (vendor, minified) |

**JS-controlled CSS classes (never override in CSS):**  
`.nav-open` · `.mobile-nav-open` · `.scrolled` · `.open` · `.active` (sidebar) · `.listening` (voice)

---

## 5. Key PHP Components

| Component / Class | Where used |
|-------------------|-----------|
| `aside.sidebar` + `nav.sidebar-nav` | Admin sidebar (admin-header.php) |
| `header.admin-header.admin-header--compact` | Admin top bar |
| `main.main-content` | Admin content area |
| `.stat-mini-row` > `.stat-mini` > `.sm-icon` / `.sm-val` / `.sm-lbl` | Dashboard stat cards (stat-card.php component) |
| `.pfl-header-wrapper` + `.pfl-top-bar` + `.pfl-main-header` | Public sticky header |
| `.pfl-brand-area` + `.pfl-brand-content` | Public logo/brand area |
| `.pfl-nav-area` > `.main-nav` > `.nav-menu` | Public navigation |
| `header.mp-header` + `.mp-brand` + `.mp-company` | Member portal header |
| `.mem-wrapper` > `.mem-card` | Member portal content cards |
| `coopThemeHeadAssets($panel)` | CSS loader — call in every page `<head>` |

---

## 6. Database

- **Fresh install:** run `database/install.sql` (74 CREATE TABLE statements) via `install.php`.
- **Auto-migration:** `includes/ensure-tables.php` and `admin/includes/ensure-admin-tables.php` create missing tables on-the-fly — safe to run on existing installs.
- **Connection:** `includes/database.local.php` (gitignored) — copy from `database.dist.php` and fill credentials.

---

## 7. Setup (cPanel Deployment)

1. **Upload** all files to `public_html/`.
2. **Database:**
   - Create MySQL database + user via cPanel "MySQL Databases".
   - Copy `includes/database.dist.php` → `includes/database.local.php` and fill credentials.
   - Open `https://yourdomain/install.php` once — runs `database/install.sql`.
   - **⚠ Delete `install.php` immediately after first successful run.**
3. **PHP version:** cPanel → Software → Select PHP Version → **PHP 8.2** (8.0 minimum).
4. **First admin login:** see `install.php` output for seeded credentials. Change via `/admin/settings.php`.
5. **Cron job:** `php /home/USERNAME/public_html/cron-cleanup.php` — run daily via cPanel Cron Jobs.
6. **PWA:** `sw.js` served from site root — no extra config needed.

---

## 8. File Naming Conventions

- **PHP files:** `kebab-case.php` — e.g. `member-welfare.php`, not `memberWelfare.php`
- **Shared helpers:** `includes/`
- **Admin-only helpers:** `admin/includes/`
- **Logic files — never touch CSS/HTML:** `config.php`, `auth-roles.php`, `safe-query.php`, `credentials-crypto.php`, `ensure-tables.php`, `init.php`

---

## 9. Production Cache Busting

CSS links are generated by `coopThemeLink()` with `?v=<filemtime>`.  
After deploying new CSS — **no manual cache action needed** — `filemtime` auto-changes the version string.

---

## 10. Project Counts (v7.0)

| Item | Count |
|------|-------|
| PHP files | 271 |
| CSS files | 18 |
| JS files | 11 |
| DB tables (install.sql) | 74 |

---

*Internal property of Aakash Cooperative — Not for redistribution.*  
**Last updated: 2026-06-25 · v7.0 Premium UI**
