#!/usr/bin/env python3
"""Deep CSS / theme / font / Lucide inventory (read-only by default).

Does NOT rewrite app-*.css or mass-edit DB icon/role rows.
Safe improve surface only: polish sources, lucide-icon-utils, late-bundle rebuild.

Run:
  python3 scripts/inventory-css-deep-audit.py
  python3 scripts/inventory-css-deep-audit.py --write
"""
from __future__ import annotations

import argparse
import hashlib
import json
import re
from collections import Counter, defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CSS = ROOT / "assets" / "css"
REPORT = ROOT / "scripts" / "reports" / "css-deep-audit.json"
BUILD = ROOT / "scripts" / "build-css-late-bundles.py"

REF_RE = re.compile(r"assets/css/([A-Za-z0-9._-]+\.css)")
FONT_RE = re.compile(r"font-family:\s*([^;}{]+)")
CLASS_RE = re.compile(r"\.([a-zA-Z_][\w-]*)")
FA_I_RE = re.compile(r'<i[^>]+class="[^"]*\b(fas|far|fal)\b')
LUCIDE_MARK_RE = re.compile(
    r'data-lucide=|<i[^>]*class="[^"]*lucide|coop_nav_icon_html|lucide-icon'
)


def php_files() -> list[Path]:
    out: list[Path] = []
    for p in ROOT.rglob("*.php"):
        if any(x in p.parts for x in ("vendor", "node_modules", ".git", "scripts")):
            continue
        out.append(p)
    return out


def referenced_css() -> set[str]:
    refs: set[str] = set()
    for p in php_files():
        try:
            t = p.read_text(encoding="utf-8", errors="ignore")
        except OSError:
            continue
        refs.update(REF_RE.findall(t))
    if BUILD.is_file():
        refs.update(re.findall(r'"([A-Za-z0-9._-]+\.css)"', BUILD.read_text(encoding="utf-8")))
    refs.update(
        [
            "public-late-bundle.css",
            "admin-late-bundle.css",
            "member-late-bundle.css",
            "minimal-late-bundle.css",
            "lucide-icon-utils.css",
        ]
    )
    return refs


def classes_in(name: str) -> set[str]:
    p = CSS / name
    if not p.is_file():
        return set()
    return set(CLASS_RE.findall(p.read_text(encoding="utf-8", errors="ignore")))


def build_report() -> dict:
    rows: list[dict] = []
    imp_total = 0
    for f in sorted(CSS.glob("*.css")):
        text = f.read_text(encoding="utf-8", errors="ignore")
        imp = len(re.findall(r"!important", text))
        imp_total += imp
        rows.append(
            {
                "name": f.name,
                "bytes": f.stat().st_size,
                "lines": text.count("\n") + 1,
                "frozen": f.name.startswith("app-"),
                "is_bundle": "late-bundle" in f.name,
                "is_page": f.name.endswith("-page.css"),
                "is_polish": "polish" in f.name or "patch" in f.name,
                "is_app": f.name.startswith("app-"),
                "font_face": len(re.findall(r"@font-face", text)),
                "important": imp,
                "lucide": len(re.findall(r"lucide", text, re.I)),
                "fas_far": len(re.findall(r"\.(fas|far|fal|fad)\b|fa-[a-z0-9-]+", text)),
            }
        )

    refs = referenced_css()
    unreferenced = [
        r["name"]
        for r in rows
        if r["name"] not in refs and not r["is_bundle"]
    ]

    lucide_php = 0
    fas_static = 0
    rogue_gf: list[str] = []
    for p in php_files():
        try:
            t = p.read_text(encoding="utf-8", errors="ignore")
        except OSError:
            continue
        lucide_php += len(LUCIDE_MARK_RE.findall(t))
        fas_static += len(FA_I_RE.findall(t))
        if "fonts.googleapis.com" in t and "coopThemeGoogleFonts" not in t:
            # CSP allowlist in config is expected; skip style-src only lines
            if "style-src" in t and "fonts.googleapis.com" in t and t.count("fonts.googleapis.com") <= 2:
                continue
            rogue_gf.append(str(p.relative_to(ROOT)))

    font_counter: Counter[str] = Counter()
    for f in CSS.glob("*.css"):
        for m in FONT_RE.findall(f.read_text(encoding="utf-8", errors="ignore")):
            font_counter[m.strip()] += 1

    hashes: dict[str, list[str]] = defaultdict(list)
    for f in CSS.glob("*.css"):
        h = hashlib.sha256(f.read_bytes()).hexdigest()[:12]
        hashes[h].append(f.name)
    coll = {h: v for h, v in hashes.items() if len(v) > 1}

    overlap_pairs = []
    identical_cross: list[dict] = []
    for a, b in [
        ("public-shell-polish.css", "final-ui-polish.css"),
        ("admin-shell-polish.css", "final-ui-polish.css"),
        ("member-shell-polish.css", "final-ui-polish.css"),
        ("premium-ui.css", "final-ui-polish.css"),
        ("admin-shell-polish.css", "admin-ux-deep-patch.css"),
        ("admin-ux-deep-patch.css", "final-ui-polish.css"),
    ]:
        ca, cb = classes_in(a), classes_in(b)
        inter = len(ca & cb)
        overlap_pairs.append(
            {
                "a": a,
                "b": b,
                "a_n": len(ca),
                "b_n": len(cb),
                "overlap": inter,
                "pct_a": round(100 * inter / max(1, len(ca)), 1),
            }
        )
        # Exact rule-body identity for shared selectors (safe dedupe candidates)
        pa, pb = CSS / a, CSS / b
        if pa.is_file() and pb.is_file():
            def bodies(path: Path) -> dict[str, str]:
                text = path.read_text(encoding="utf-8", errors="ignore")
                text = re.sub(r"/\*.*?\*/", "", text, flags=re.S)
                out: dict[str, str] = {}
                for m in re.finditer(r"([^{}@][^{]*)\{([^{}]*)\}", text):
                    sel = re.sub(r"\s+", " ", m.group(1)).strip()
                    if not sel or sel.startswith("@"):
                        continue
                    body = ";".join(
                        sorted(
                            d.strip().lower()
                            for d in m.group(2).split(";")
                            if ":" in d
                        )
                    )
                    if body:
                        out[sel] = body
                return out

            ba, bb = bodies(pa), bodies(pb)
            for sel in set(ba) & set(bb):
                if ba[sel] == bb[sel]:
                    identical_cross.append({"a": a, "b": b, "selector": sel[:160]})

    return {
        "generated": "css deep audit",
        "policy": {
            "frozen_app": True,
            "no_fa_db_mass_rewrite": True,
            "no_privilege_role_mass_update": True,
            "lucide_utils": "assets/css/lucide-icon-utils.css via late-bundles",
            "font_ssot": "coopThemeGoogleFontsHtml + global.css --font-primary/--font-heading",
            "css_dedupe": "shell polish = panel base; final-ui-polish = intentional last override; remove identical mid-layer copies only",
        },
        "css_file_count": len(rows),
        "total_bytes": sum(r["bytes"] for r in rows),
        "important_total": imp_total,
        "frozen_app": [r["name"] for r in rows if r["frozen"]],
        "late_bundles": [r["name"] for r in rows if r["is_bundle"]],
        "page_css_count": sum(1 for r in rows if r["is_page"]),
        "unreferenced_css": unreferenced,
        "lucide_markers_php_approx": lucide_php,
        "static_fas_far_i_tags": fas_static,
        "top_fonts": font_counter.most_common(20),
        "rogue_google_font_loads": rogue_gf[:40],
        "hash_collisions": coll,
        "selector_overlap": overlap_pairs,
        "identical_cross_file_rules": identical_cross[:80],
        "identical_cross_file_count": len(identical_cross),
        "top_important": sorted(rows, key=lambda r: -r["important"])[:15],
        "largest": sorted(rows, key=lambda r: -r["bytes"])[:15],
        "css_files": rows,
    }


def main() -> int:
    ap = argparse.ArgumentParser(description="Deep CSS/theme/font/Lucide audit")
    ap.add_argument("--write", action="store_true", help="Write css-deep-audit.json")
    args = ap.parse_args()
    report = build_report()
    print("=== CSS deep audit (safe improve inventory) ===")
    print(f"files={report['css_file_count']} bytes={report['total_bytes']} !important={report['important_total']}")
    print(f"page_css={report['page_css_count']} frozen={report['frozen_app']}")
    print(f"unreferenced={report['unreferenced_css'] or 'none'}")
    print(f"lucide_php≈{report['lucide_markers_php_approx']} static_fas_i={report['static_fas_far_i_tags']}")
    print(f"rogue_google_fonts={report['rogue_google_font_loads'] or 'none'}")
    print(f"hash_collisions={report['hash_collisions'] or 'none'}")
    print(f"identical_cross_file_rules={report.get('identical_cross_file_count', 0)}")
    if args.write:
        REPORT.parent.mkdir(parents=True, exist_ok=True)
        REPORT.write_text(json.dumps(report, indent=2) + "\n", encoding="utf-8")
        print(f"wrote {REPORT.relative_to(ROOT)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
