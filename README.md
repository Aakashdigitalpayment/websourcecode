# Aakash Cooperative — Web Application

**आकाश सहकारी** को लागि pure **PHP 8.0+ / MySQL** digital platform।  
Bootstrap 5 UI · कुनै build step छैन।

Public website · Admin panel · Member portal — एउटै MySQL database।

---

## Tech stack

| Layer | Technology |
|-------|------------|
| Language | PHP **8.2** (8.0 minimum) |
| Database | MySQL / MariaDB · `utf8mb4_unicode_ci` |
| Frontend | Bootstrap 5 + `assets/css/` patches + Font Awesome 6 (self-hosted) |
| Fonts | Inter · Plus Jakarta Sans · Noto Sans Devanagari |
| Icons | Lucide (`assets/vendor/lucide.min.js`) + Font Awesome 6 |
| JS | Vanilla JS (no React / Vue; jQuery only when Nepali datepicker needed) |
| Hosting | cPanel / Apache · PHP-FPM |
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
├── sw.js / manifest.json   # PWA
│
├── admin/                  # Admin panel
├── member/                 # Member portal
├── includes/               # Shared PHP (config, header, footer, theme-assets…)
├── core/                   # Cross-cutting helpers
├── assets/css|js|vendor/   # Front-end assets
└── database/install.sql    # Fresh-install schema
```

---

## CSS architecture (important)

All CSS is loaded by `includes/theme-assets.php` → `coopThemeHeadAssets($panel)`.  
**Do not hard-code `<link>` tags** for theme CSS — use this loader.

### Load order (simplified)

```
Bootstrap + Font Awesome (vendor)
Google Fonts
app-public.css | app-admin.css | app-member.css   ← panel base (do not rewrite for polish)
global.css · forms-tables.css
(admin-only unified / layout / bootstrap overrides)
global-theme.php          ← DB brand colours (inline)
premium-ui.css            ← deferred polish
*-shell-polish.css        ← panel shell polish
ui-readability-safe-patch.css
admin-ux-deep-patch.css   ← admin absolute last (admin only)
final-ui-polish.css       ← ⭐ absolute last on ALL panels
```

### Safe theme / UX rules

- **New visual polish** → `final-ui-polish.css` (or panel `*-shell-polish.css` / `admin-ux-deep-patch.css`).
- **Do not rewrite** legacy `app-public.css` / `app-admin.css` for routine polish.
- Prefer additive patches; keep Devanagari safe (`min-height` + padding, not fixed heights).
- Team photo cards (board / management / committees) share one size via `final-ui-polish.css`.

---

## Performance notes (current)

- Homepage + navbar/footer data: short TTL file cache (`includes/simple-cache.php`); clear via `clearHomepageCache()` on admin CRUD.
- Growing lists: public pagination (notices, news, gallery) + admin hard `LIMIT`s.
- Public content pages skip unused jQuery/datepicker and form-validation JS where safe.
- Schema helpers: `dbTableExists()` / `dbColumnExists()` avoid repeated `SHOW` probes.

Branch used for this work: `feat/ai-chat-speed-security`.

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

## Database & setup

1. Upload to `public_html/`.
2. Copy `includes/database.dist.php` → `includes/database.local.php` (gitignored) and set credentials.
3. Run `install.php` once → then **delete** it.
4. PHP 8.2 recommended (8.0+).
5. Cron: `php /path/to/cron-cleanup.php` daily.

Auto-migration: `includes/ensure-tables.php` / `admin/includes/ensure-admin-tables.php` create missing tables safely.

---

## Counts (approx.)

| Item | Count |
|------|-------|
| PHP files | ~279 |
| CSS files (`assets/css/*.css`) | 26 |
| JS files (`assets/js/*.js`) | 12 |
| DB tables (`install.sql`) | 74 |

---

*Internal property of Aakash Cooperative — Not for redistribution.*  
**Last updated: 2026-07-29**
