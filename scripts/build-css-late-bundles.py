#!/usr/bin/env python3
"""Local regenerator for panel late CSS bundles (order-preserving concat)."""
from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CSS = ROOT / "assets" / "css"

BUNDLES: dict[str, list[str]] = {
    "public-late-bundle.css": [
        "premium-ui.css",
        "ui-ux-polish.css",
        "mobile-premium-polish.css",
        "public-shell-polish.css",
        "ui-readability-safe-patch.css",
        "final-ui-polish.css",
    ],
    "admin-late-bundle.css": [
        "premium-ui.css",
        "ui-ux-polish.css",
        "mobile-premium-polish.css",
        "admin-shell-polish.css",
        "admin-bootstrap-unified-patch.css",
        "ui-readability-safe-patch.css",
        "admin-ux-deep-patch.css",
        "final-ui-polish.css",
    ],
    "member-late-bundle.css": [
        "premium-ui.css",
        "ui-ux-polish.css",
        "mobile-premium-polish.css",
        "member-shell-polish.css",
        "ui-readability-safe-patch.css",
        "final-ui-polish.css",
    ],
    "minimal-late-bundle.css": [
        "public-shell-polish.css",
        "minimal-pages-patch.css",
        "ui-readability-safe-patch.css",
        "final-ui-polish.css",
    ],
}


def main() -> None:
    for out_name, parts in BUNDLES.items():
        lines = [
            f"/* AUTO-GENERATED: {out_name} — do not edit by hand.",
            " * Source order (preserved cascade):",
        ]
        lines.extend(f" *  - {p}" for p in parts)
        lines.append(" * Regenerate locally: python3 scripts/build-css-late-bundles.py")
        lines.append(" */")
        lines.append("")
        chunks = ["\n".join(lines) + "\n"]
        for p in parts:
            path = CSS / p
            if not path.is_file():
                raise SystemExit(f"missing source: {path}")
            body = path.read_text(encoding="utf-8")
            chunks.append(f"\n/* ===== BEGIN {p} ===== */\n")
            chunks.append(body.rstrip() + "\n")
            chunks.append(f"/* ===== END {p} ===== */\n")
        out = CSS / out_name
        out.write_text("".join(chunks), encoding="utf-8")
        print(f"{out_name}: {out.stat().st_size} bytes")


if __name__ == "__main__":
    main()
