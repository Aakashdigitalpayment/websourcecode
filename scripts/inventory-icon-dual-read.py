#!/usr/bin/env python3
"""Inventory icon dual-read policy (no DB mass rewrite).

Safe track:
  - Render: FA / bare Lucide → Lucide HTML via coop_nav_icon_html
  - Write:  coop_canonical_icon_for_storage (FA spelling / bare Lucide / fab keep)
  - Bang deferred: UPDATE … SET icon = lucide… across rows

Run:
  python3 scripts/inventory-icon-dual-read.py
  python3 scripts/inventory-icon-dual-read.py --write
"""
from __future__ import annotations

import argparse
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPORT_DIR = ROOT / "scripts" / "reports"

ICON_DEFAULT_RE = re.compile(
    r"DEFAULT\s+'((?:fa[srlb]?\s+)?fa-[\w-]+|fab\s+fa-[\w-]+)'",
    re.I,
)
STORAGE_HELPER = "function coop_canonical_icon_for_storage"
RENDER_HELPER = "function coop_nav_icon_html"


def main() -> int:
    ap = argparse.ArgumentParser(description="Icon dual-read inventory (no DB rewrite)")
    ap.add_argument("--write", action="store_true", help="Write scripts/reports/icon-dual-read-plan.json")
    args = ap.parse_args()

    config = (ROOT / "includes" / "config.php").read_text(encoding="utf-8", errors="ignore")
    has_storage = STORAGE_HELPER in config
    has_render = RENDER_HELPER in config
    keeps_fab = "Brand packs" in config and "fab" in config

    defaults: list[dict] = []
    for path in sorted((ROOT / "includes").rglob("*.php")) + sorted((ROOT / "admin" / "includes").rglob("*.php")):
        text = path.read_text(encoding="utf-8", errors="ignore")
        for m in ICON_DEFAULT_RE.finditer(text):
            defaults.append(
                {
                    "file": str(path.relative_to(ROOT)),
                    "default": m.group(1),
                }
            )

    write_sites: list[str] = []
    missing_write: list[str] = []
    icon_post_re = re.compile(r"\$_POST\[['\"](?!clear_site_favicon|site_favicon)[^'\"]*icon[^'\"]*['\"]")
    for p in sorted((ROOT / "admin").glob("*.php")):
        text = p.read_text(encoding="utf-8", errors="ignore")
        rel = str(p.relative_to(ROOT))
        if "coop_canonical_icon_for_storage" in text:
            write_sites.append(rel)
            continue
        if icon_post_re.search(text):
            missing_write.append(rel)

    print("=== Icon dual-read (safe) ===")
    print("  coop_nav_icon_html=", has_render)
    print("  coop_canonical_icon_for_storage=", has_storage)
    print("  fab brands retained=", keeps_fab)
    print(f"  schema FA defaults found={len(defaults)}")
    print(f"  admin write paths wired={len(write_sites)}")
    for w in write_sites:
        print(f"    - {w}")
    print(f"  missing canonicalize on icon POST={missing_write or 'none'}")
    print("\nDeferred bang: FA→Lucide DB row mass UPDATE (live data intact; render dual-reads).")

    if args.write:
        REPORT_DIR.mkdir(parents=True, exist_ok=True)
        out = REPORT_DIR / "icon-dual-read-plan.json"
        payload = {
            "policy": "Dual-read icons — no FA→Lucide DB mass UPDATE in this track",
            "safe": {
                "render": "coop_nav_icon_html / fa_to_lucide",
                "write": "coop_canonical_icon_for_storage (FA normalize + bare Lucide + fab keep)",
                "brands": "fab stays Font Awesome",
            },
            "deferred_bang": "UPDATE icon columns from fas fa-* to bare Lucide names across existing rows",
            "schema_fa_defaults_sample": defaults[:40],
            "schema_fa_defaults_count": len(defaults),
            "write_paths_wired": write_sites,
            "missing_write_canonicalize": missing_write,
        }
        out.write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")
        print(f"\nWrote {out.relative_to(ROOT)}")

    return 0 if has_storage and has_render else 1


if __name__ == "__main__":
    raise SystemExit(main())
