# app-* section shadows (not live CSS)

Mirrors of named `/* === name.css === */` blocks inside frozen `app-*.css`.

- **Do not** load these in production theme order.
- **Do not** edit `app-core/public/admin/member.css` bodies for polish.
- First wave: `python3 scripts/extract-app-css-section.py --write --all-first-pr`
- Second wave (mid-size): `python3 scripts/extract-app-css-section.py --write --all-second-pr`
- Both: `python3 scripts/extract-app-css-section.py --write --all-first-pr --all-second-pr`
- Verify: `python3 scripts/extract-app-css-section.py --verify`

Large named bodies (`style.css`, `public-modern.css`, `admin.css`, `admin-modern.css`, `theme-overrides-v4.css`, …) stay deferred until a thin-import shim PR. Until then app-* remains the live SSOT.
