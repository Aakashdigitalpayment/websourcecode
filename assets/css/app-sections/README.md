# app-* section shadows (not live CSS)

Non-live extract mirrors of named blocks inside frozen `app-*.css`.

**Product / theme SSOT:** root [`README.md`](../../../README.md) only — do not duplicate polish rules here.

- **Do not** load these in production theme order.
- **Do not** edit `app-core/public/admin/member.css` bodies for polish (use late-bundle sources + `python3 scripts/build-css-late-bundles.py`).
- Extract waves: `python3 scripts/extract-app-css-section.py --write --all-first-pr` | `--all-second-pr` | `--all-third-pr` (or all three flags together).
- Verify: `python3 scripts/extract-app-css-section.py --verify`

Large named bodies over the 110KB third-wave cap stay deferred until a thin-import shim. Until then `app-*` remains the live panel-base SSOT.
