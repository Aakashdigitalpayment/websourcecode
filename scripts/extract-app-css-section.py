#!/usr/bin/env python3
"""Shadow-extract named SECTION blocks from frozen app-*.css (safe improve).

Does NOT rewrite or delete app-* bodies. Writes a mirror under
assets/css/app-sections/ for a future thin-import shim PR.

Run:
  python3 scripts/extract-app-css-section.py                         # dry-run smallest
  python3 scripts/extract-app-css-section.py --write --all-first-pr   # all first_pr shadows
  python3 scripts/extract-app-css-section.py --write --all-second-pr  # mid-size second wave
  python3 scripts/extract-app-css-section.py --write --section mem-utils.css
  python3 scripts/extract-app-css-section.py --verify                 # sha match check
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CSS = ROOT / "assets" / "css"
OUT = CSS / "app-sections"
REPORT = ROOT / "scripts" / "reports" / "app-css-section-shadows.json"

NAMED_SECTION_RE = re.compile(r"(?m)^/\*\s*=+\s*([a-z0-9._-]+\.css)\s*=+\s*\*/")
APP_SHEETS = ("app-core.css", "app-public.css", "app-admin.css", "app-member.css")
FIRST_PR = {
    "header-v2.css",
    "member-portal-v2.css",
    "auth-portals-unified.css",
    "coop-clean.css",
    "design-tokens.css",
    "animations.css",
    "mobile-comprehensive-fixes.css",
    "admin-mobile.css",
    "theme-variables.css",
    "mobile-menu-improvements.css",
    "mem-utils.css",
    "admin-tokens.css",
}
# Mid-size named sections (<~100KB). Large bodies (style/public-modern/admin.css…) stay deferred.
SECOND_PR = {
    "coop-core.css",
    "member.css",
    "unified-portal.css",
}
SHADOW_BANNER_RE = re.compile(r"(?s)^/\* SHADOW EXTRACT from .*?\*/\n")


def sections_in(sheet: Path) -> list[dict]:
    data = sheet.read_text(encoding="utf-8", errors="ignore")
    matches = list(NAMED_SECTION_RE.finditer(data))
    out: list[dict] = []
    for i, m in enumerate(matches):
        start = m.start()
        end = matches[i + 1].start() if i + 1 < len(matches) else len(data)
        chunk = data[start:end]
        out.append(
            {
                "name": m.group(1),
                "from_sheet": sheet.name,
                "start": start,
                "bytes": end - start,
                "sha256": hashlib.sha256(chunk.encode("utf-8")).hexdigest()[:16],
                "chunk": chunk,
            }
        )
    return out


def shadow_rel(s: dict) -> str:
    return f"assets/css/app-sections/{s['from_sheet'].replace('.css', '')}--{s['name']}"


def banner_for(s: dict) -> str:
    return (
        f"/* SHADOW EXTRACT from {s['from_sheet']} — do not edit app-* for polish.\n"
        f" * Source bytes={s['bytes']} sha256={s['sha256']}\n"
        f" * Future PR: thin @import shim; keep app-* frozen until verified.\n"
        f" * Regenerate: python3 scripts/extract-app-css-section.py --write --all-first-pr\n"
        f" */\n"
    )


def verify_shadows(all_secs: list[dict]) -> int:
    """Confirm existing shadow files match live app-* section bytes/sha."""
    by_key = {(s["from_sheet"], s["name"]): s for s in all_secs}
    files = sorted(OUT.glob("*.css")) if OUT.is_dir() else []
    if not files:
        print("VERIFY FAIL: no shadows in assets/css/app-sections/")
        return 1
    ok = 0
    bad = 0
    print("=== app-* section shadow VERIFY ===")
    for path in files:
        # app-core--design-tokens.css
        stem = path.name
        if "--" not in stem:
            print(f"  skip unexpected {stem}")
            continue
        sheet_stub, section = stem.split("--", 1)
        from_sheet = sheet_stub + ".css"
        key = (from_sheet, section)
        if key not in by_key:
            print(f"  FAIL {stem}: section missing in {from_sheet}")
            bad += 1
            continue
        s = by_key[key]
        text = path.read_text(encoding="utf-8", errors="ignore")
        body = SHADOW_BANNER_RE.sub("", text, count=1)
        live_sha = s["sha256"]
        file_sha = hashlib.sha256(body.encode("utf-8")).hexdigest()[:16]
        if body != s["chunk"] or file_sha != live_sha:
            print(f"  FAIL {stem}: sha file={file_sha} live={live_sha}")
            bad += 1
            continue
        print(f"  OK {stem} sha={live_sha}")
        ok += 1
    # Require every FIRST_PR + SECOND_PR section that exists in app-* to have a shadow
    required = FIRST_PR | SECOND_PR
    missing = []
    for s in all_secs:
        if s["name"] not in required:
            continue
        rel = ROOT / shadow_rel(s)
        if not rel.is_file():
            missing.append(shadow_rel(s))
    for m in missing:
        print(f"  FAIL missing shadow {m}")
        bad += 1
    print(f"verify ok={ok} bad={bad}")
    return 0 if bad == 0 else 1


def main() -> int:
    ap = argparse.ArgumentParser(description="Shadow-extract app-* CSS sections (no body rewrite)")
    ap.add_argument("--write", action="store_true", help="Write assets/css/app-sections/* mirrors")
    ap.add_argument("--section", action="append", default=[], help="Section name(s), e.g. mem-utils.css")
    ap.add_argument(
        "--all-first-pr",
        action="store_true",
        help="Select every FIRST_PR section present in app-* sheets",
    )
    ap.add_argument(
        "--all-second-pr",
        action="store_true",
        help="Select every SECOND_PR (mid-size) section present in app-* sheets",
    )
    ap.add_argument(
        "--verify",
        action="store_true",
        help="Check existing shadows match live app-* section sha (no write)",
    )
    ap.add_argument(
        "--first-pr-smallest",
        action="store_true",
        default=True,
        help="Default: extract smallest first_pr candidate when --section omitted",
    )
    args = ap.parse_args()

    all_secs: list[dict] = []
    for name in APP_SHEETS:
        p = CSS / name
        if p.is_file():
            all_secs.extend(sections_in(p))

    if args.verify:
        return verify_shadows(all_secs)

    wanted = list(args.section)
    if args.all_first_pr and args.all_second_pr:
        wanted = sorted(FIRST_PR | SECOND_PR)
    elif args.all_first_pr:
        wanted = sorted(FIRST_PR)
    elif args.all_second_pr:
        wanted = sorted(SECOND_PR)
    elif not wanted:
        cands = [s for s in all_secs if s["name"] in FIRST_PR]
        cands.sort(key=lambda s: s["bytes"])
        if cands:
            wanted = [cands[0]["name"]]

    selected = [s for s in all_secs if s["name"] in wanted]
    if not selected:
        print("No matching sections for", wanted)
        return 1

    print("=== app-* section shadow extract (safe — no app-* rewrite) ===")
    written: list[dict] = []
    for s in selected:
        rel = shadow_rel(s)
        body = banner_for(s) + s["chunk"]
        print(f"  {s['from_sheet']} → {s['name']}: {s['bytes']:,} bytes sha={s['sha256']}")
        meta = {
            "from_sheet": s["from_sheet"],
            "section": s["name"],
            "bytes": s["bytes"],
            "sha256": s["sha256"],
            "shadow": rel,
        }
        if args.write:
            OUT.mkdir(parents=True, exist_ok=True)
            out_path = ROOT / rel
            out_path.write_text(body, encoding="utf-8")
            print(f"    wrote {rel}")
        else:
            print("    (dry-run — pass --write to create shadow file)")
        written.append(meta)

    REPORT.parent.mkdir(parents=True, exist_ok=True)
    REPORT.write_text(
        json.dumps(
            {
                "policy": "Shadow extract only — app-* bodies remain FROZEN",
                "wrote": bool(args.write),
                "all_first_pr": bool(args.all_first_pr),
                "all_second_pr": bool(args.all_second_pr),
                "sections": written,
            },
            indent=2,
        )
        + "\n",
        encoding="utf-8",
    )
    print(f"report {REPORT.relative_to(ROOT)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
