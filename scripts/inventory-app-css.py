#!/usr/bin/env python3
"""Read-only inventory for frozen app-*.css panel bases.

Does NOT split or rewrite app-* files. Use this to plan a future split PR:
  - section marker counts / sizes
  - existing *-page.css extract surface
  - late-bundle sources (must never include app-*)

Run:
  python3 scripts/inventory-app-css.py
  python3 scripts/inventory-app-css.py --write   # writes scripts/reports/app-css-split-plan.json
"""
from __future__ import annotations

import argparse
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CSS = ROOT / "assets" / "css"
REPORT_DIR = ROOT / "scripts" / "reports"

APP_SHEETS = ("app-core.css", "app-public.css", "app-admin.css", "app-member.css")
SECTION_RE = re.compile(r"(?m)^/\*[^\n]*(SECTION|═+|──+|===)")
SECTION_FILE_RE = re.compile(
    r"(?m)^/\*\s*=+\s*([a-z0-9._-]+\.css)\s*=+\s*\*/|^/\*\s*SECTION INDEX[^\n]*$",
    re.I,
)
NAMED_SECTION_RE = re.compile(r"(?m)^/\*\s*=+\s*([a-z0-9._-]+\.css)\s*=+\s*\*/")


def sheet_section_sizes(data: str) -> list[dict]:
    """Byte spans between named /* ========== name.css ========== */ markers."""
    matches = list(NAMED_SECTION_RE.finditer(data))
    out: list[dict] = []
    if not matches:
        return out
    for i, m in enumerate(matches):
        start = m.start()
        end = matches[i + 1].start() if i + 1 < len(matches) else len(data)
        out.append(
            {
                "name": m.group(1),
                "start": start,
                "bytes": end - start,
            }
        )
    return out


def main() -> int:
    ap = argparse.ArgumentParser(description="Inventory frozen app-* CSS (no rewrite)")
    ap.add_argument(
        "--write",
        action="store_true",
        help="Write scripts/reports/app-css-split-plan.json for a future split PR",
    )
    args = ap.parse_args()

    print("=== Frozen app-* panel bases (do not rewrite) ===")
    total = 0
    sheets: dict[str, dict] = {}
    for name in APP_SHEETS:
        p = CSS / name
        if not p.is_file():
            print(f"MISSING {name}")
            return 1
        data = p.read_text(encoding="utf-8", errors="ignore")
        size = p.stat().st_size
        total += size
        frozen = "FROZEN PANEL BASE" in data[:400]
        sections = len(SECTION_RE.findall(data))
        named = sheet_section_sizes(data)
        sheets[name] = {
            "bytes": size,
            "sections_approx": sections,
            "frozen": frozen,
            "named_sections": named,
            "named_section_count": len(named),
        }
        print(f"  {name}: {size:,} bytes  sections≈{sections}  named={len(named)}  frozen={frozen}")
        if named:
            top = sorted(named, key=lambda x: x["bytes"], reverse=True)[:5]
            for row in top:
                print(f"      top {row['name']}: {row['bytes']:,} bytes")
    print(f"  TOTAL app-*: {total:,} bytes")

    pages = sorted(CSS.glob("*-page.css"))
    print(f"\n=== Extracted *-page.css ({len(pages)}) ===")
    page_total = 0
    page_list: list[dict] = []
    page_names = set()
    for p in pages:
        page_total += p.stat().st_size
        page_list.append({"name": p.name, "bytes": p.stat().st_size})
        page_names.add(p.name)
        print(f"  {p.name}: {p.stat().st_size:,}")
    print(f"  TOTAL page CSS: {page_total:,} bytes")

    # Rank named app-* sections not yet mirrored by *-page.css (plan only — no extract).
    split_candidates: list[dict] = []
    for sheet_name, meta in sheets.items():
        for sec in meta.get("named_sections") or []:
            sec_name = str(sec.get("name") or "")
            if not sec_name.endswith(".css"):
                continue
            # Skip if an extracted page/shell already covers this name
            if sec_name in page_names:
                continue
            if (CSS / sec_name).is_file():
                continue
            split_candidates.append(
                {
                    "from_sheet": sheet_name,
                    "section": sec_name,
                    "bytes": int(sec.get("bytes") or 0),
                    "start": int(sec.get("start") or 0),
                }
            )
    split_candidates.sort(key=lambda r: r["bytes"], reverse=True)
    mega = {
        "style.css",
        "public-modern.css",
        "admin.css",
        "admin-modern.css",
        "theme-overrides-v4.css",
        "unified-portal.css",
        "member.css",
    }
    first_pr = [
        c
        for c in split_candidates
        if c["section"] not in mega and int(c["bytes"]) <= 40000
    ][:15]
    print(f"\n=== Split candidates (named sections w/o extracted twin) top 12 ===")
    for row in split_candidates[:12]:
        print(f"  {row['from_sheet']} → {row['section']}: {row['bytes']:,} bytes")
    print(f"\n=== First-PR safe extract candidates (≤40KB, non-mega) ===")
    for row in first_pr:
        print(f"  {row['from_sheet']} → {row['section']}: {row['bytes']:,} bytes")

    build = ROOT / "scripts" / "build-css-late-bundles.py"
    print("\n=== Late-bundle policy ===")
    policy = {
        "never_include_app_star": False,
        "concat_app_sheets": {},
    }
    if build.is_file():
        b = build.read_text(encoding="utf-8", errors="ignore")
        policy["never_include_app_star"] = "Never include app-public" in b
        print("  never_include_app_star=", policy["never_include_app_star"])
        for sheet in APP_SHEETS:
            present = f'"{sheet}"' in b
            policy["concat_app_sheets"][sheet] = present
            print(f"  concat {sheet}=", present)
    print("\nNext split PR: extract by SECTION INDEX only; keep app-* as thin import shim.")
    print("Shadow extract (no rewrite): python3 scripts/extract-app-css-section.py --write --all-first-pr --all-second-pr")
    print("Shadow verify: python3 scripts/extract-app-css-section.py --verify")
    print("Large bodies (style/public-modern/admin.css…) deferred — mid-size SECOND_PR only beyond first_pr.")
    print("Do not rewrite app-* bodies for polish — use *-page.css / shell-polish / final-ui-polish.")
    print("Bangs deferred: app-* body rewrite (shadow extract OK), privilege LEVEL mass UPDATE, FA→Lucide name rewrite.")
    print("Safe alts: section shadows; alias-only roles; icon FA spelling canonicalize + dual-read.")

    if args.write:
        REPORT_DIR.mkdir(parents=True, exist_ok=True)
        out_path = REPORT_DIR / "app-css-split-plan.json"
        payload = {
            "policy": "FROZEN PANEL BASE — inventory only; no body rewrite in this track",
            "next_pr": "Extract first_pr_candidates by named SECTION markers; leave app-* as thin import shim",
            "deferred_bangs": {
                "fab_brands": "keep Font Awesome Brands (Lucide has no brand set)",
                "icon_picker_storage": "continue storing FA class strings; Lucide preview only",
                "app_star_rewrite": "frozen — use extract-app-css-section.py shadows; no app-* body rewrite yet",
                "role_privilege_mass_update": "deferred — alias-only superadmin→super_admin normalize only (not level changes)",
                "fa_to_lucide_db_row_rewrite": "deferred — FA spelling via coop_canonicalize_icon_db_rows; Lucide name rewrite still deferred",
            },
            "safe_alternatives": {
                "app_star": "extract-app-css-section.py shadows + *-page.css / shell-polish; never edit app-* bodies for polish",
                "roles": "coop_normalize_admin_role_aliases + admin_canonical_db_role on writes/login",
                "icons": "coop_nav_icon_html render + coop_canonical_icon_for_storage writes + coop_canonicalize_icon_db_rows (FA spelling)",
            },
            "shadow_extract": "scripts/extract-app-css-section.py --write --all-first-pr --all-second-pr → assets/css/app-sections/; --verify for sha match",
            "icon_db_tool": "scripts/inventory-icon-db-canonicalize.php",
            "total_app_bytes": total,
            "sheets": sheets,
            "page_css": page_list,
            "page_css_total_bytes": page_total,
            "split_candidates": split_candidates[:40],
            "first_pr_candidates": first_pr,
            "late_bundle": policy,
        }
        out_path.write_text(json.dumps(payload, indent=2) + "\n", encoding="utf-8")
        print(f"\nWrote {out_path.relative_to(ROOT)}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
