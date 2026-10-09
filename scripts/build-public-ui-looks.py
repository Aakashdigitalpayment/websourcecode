#!/usr/bin/env python3
"""Split assets/css/public-ui-looks.css (the SOURCE, all ten looks, ~224 KB) into one
file per look under assets/css/looks/, so a public page downloads only the look it
uses (the live site runs one look; the other nine were dead weight on every page).

Rule placement, source order and @media wrappers preserved:
  * selector names one look only            → that look's file
  * selector names several looks            → each of those looks' files (whole rule, unmatched selectors are inert)
  * selector has :not([data-ui-look="X"])   → every look except X
  * no data-ui-look in the selector         → every look (shared)
  * @keyframes / @font-face / :root         → every look

  python3 scripts/build-public-ui-looks.py            # write assets/css/looks/public-ui-looks.<look>.css
  python3 scripts/build-public-ui-looks.py --check    # exit 1 if the split files drifted from the source
"""
from __future__ import annotations
import re, sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / "assets" / "css" / "public-ui-looks.css"
OUT = ROOT / "assets" / "css" / "looks"
LOOKS = ["soft", "editorial", "compact", "sharp", "airy", "bold", "cascade", "harbor", "pulse", "summit"]


def looks_for(selector: str) -> set[str]:
    nots = set(re.findall(r':not\(\[data-ui-look="([a-z]+)"\]\)', selector))
    pos = set(re.findall(r'(?<!:not\()\[data-ui-look="([a-z]+)"\]', selector))
    pos -= nots
    if pos:
        return pos
    if nots:
        return set(LOOKS) - nots
    return set(LOOKS)


def split(text: str) -> dict[str, list[str]]:
    out = {lk: [] for lk in LOOKS}

    def walk(s: str, wrap: list[str]) -> None:
        i, n = 0, len(s)
        while i < n:
            if s.startswith("/*", i):
                j = s.find("*/", i); i = n if j < 0 else j + 2; continue
            j = s.find("{", i)
            if j < 0:
                break
            head = s[i:j].strip()
            depth, k = 1, j + 1
            while k < n and depth:
                depth += (s[k] == "{") - (s[k] == "}"); k += 1
            body = s[j + 1:k - 1]
            i = k
            if not head:
                continue
            if head.startswith("@") and "{" in body and not head.startswith("@keyframes") and not head.startswith("@font-face"):
                walk(body, wrap + [head]); continue
            rule = head + " {" + body.rstrip() + "\n}"
            targets = set(LOOKS) if head.startswith("@") or head.startswith(":root") else looks_for(head)
            for lk in targets:
                out[lk].append(("".join(w + " {\n" for w in wrap)) + rule + ("\n}" * len(wrap)))
    walk(text, [])
    return out


def render(lk: str, rules: list[str]) -> str:
    head = (f"/* AUTO-GENERATED: public-ui-looks.{lk}.css — do not edit.\n"
            f" * Source: assets/css/public-ui-looks.css (edit there), then\n"
            f" * python3 scripts/build-public-ui-looks.py\n */\n\n")
    return head + "\n\n".join(rules) + "\n"


def main() -> int:
    text = SRC.read_text(encoding="utf-8")
    parts = split(text)
    check = "--check" in sys.argv
    stale = []
    OUT.mkdir(exist_ok=True)
    for lk in LOOKS:
        body = render(lk, parts[lk])
        path = OUT / f"public-ui-looks.{lk}.css"
        if check:
            if not path.is_file() or path.read_text(encoding="utf-8") != body:
                stale.append(path.name)
        else:
            path.write_text(body, encoding="utf-8")
            print(f"{path.name}: {len(body.encode())/1024:.0f} KB, {len(parts[lk])} rules")
    if check:
        if stale:
            print("STALE look files (run scripts/build-public-ui-looks.py): " + ", ".join(stale)); return 1
        print("look files in sync with public-ui-looks.css")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
