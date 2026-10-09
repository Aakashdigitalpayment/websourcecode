#!/usr/bin/env python3
"""Prune rules from a frozen app-*.css base whose classes appear nowhere in the
project markup / scripts (PHP, JS, JSON, HTML). Conservative:

  * a class counts as USED when the class, or any dash-prefix of it
    (coop-alert--danger → coop-alert--, coop-alert, coop), appears in a non-CSS
    source file — PHP builds many class names by concatenation;
  * a selector is dead only when EVERY class in it is unused (so state /
    modifier compounds like `.cr-type-badge.fulltime`, whose modifier comes
    from the database, stay); a rule goes only when all its selectors are dead;
    element / id / attribute / pseudo-only selectors stay;
  * vendor JS (DataTables, select2 …) is part of the source scan, so classes
    those libraries generate count as used;
  * @keyframes, @font-face, @supports/@media wrappers, comments and the section
    banners stay (the smoke tests assert the banners).

Blind spot: class names that live only in database content (CMS page HTML,
notice bodies) are not seen — check `scripts/reports/<sheet>.pruned.txt`
against live content before --write.

Dry run prints a report; --write rewrites the file and writes
scripts/reports/<sheet>.pruned.txt with every removed rule for review.

  python3 scripts/prune-app-css-dead-rules.py app-core.css
  python3 scripts/prune-app-css-dead-rules.py app-core.css --write
"""
from __future__ import annotations
import re, sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CSS = ROOT / "assets" / "css"
SRC_EXT = {".php", ".js", ".json", ".html", ".htm", ".txt", ".md", ".py"}
SKIP_RELS = ("assets/css/", "scripts/reports/", "node_modules/", ".git/")


def source_blob() -> str:
    parts = []
    for p in ROOT.rglob("*"):
        rel = p.relative_to(ROOT).as_posix()
        if p.suffix.lower() not in SRC_EXT or rel.startswith(SKIP_RELS) or "/node_modules/" in rel:
            continue
        if p.name.endswith(".pruned.txt"):
            continue
        try:
            parts.append(p.read_text(errors="ignore"))
        except OSError:
            pass
    return "\n".join(parts)


def class_used(cls: str, blob: str, cache: dict) -> bool:
    if cls in cache:
        return cache[cls]
    cands = {cls}
    base = cls
    while True:
        m = re.match(r"^(.*?)(--|__|-)[^-_]+$", base)
        if not m or len(m.group(1)) < 3:
            break
        base = m.group(1)
        cands.add(base)
        cands.add(base + m.group(2))
    # `'badge-' . $status` style concatenation: the first dash-prefix counts too
    ok = any(c in blob for c in cands)
    if not ok:
        first = re.match(r"^([a-zA-Z][\w]*?)(--|__|-)", cls)
        if first and len(first.group(1)) >= 2:
            pre = re.escape(first.group(1) + first.group(2))
            # only a real concatenation / interpolation counts: 'badge-' . $x, badge-{$x}, badge-<?=, `badge-${x}`, "badge-" +
            ok = re.search(pre + r"(?:['\"]\s*\.|\{\$|<\?|\$\{|['\"]\s*\+)", blob) is not None
    cache[cls] = ok
    return ok


CLASS_RE = re.compile(r"\.(-?[_a-zA-Z][\w-]*)")


def split_selectors(sel: str) -> list[str]:
    out, depth, cur = [], 0, ""
    for ch in sel:
        if ch in "([":
            depth += 1
        elif ch in ")]":
            depth -= 1
        if ch == "," and depth == 0:
            out.append(cur)
            cur = ""
        else:
            cur += ch
    out.append(cur)
    return [s.strip() for s in out if s.strip()]


def prune(text: str, blob: str, removed: list[str]) -> str:
    """Walk the sheet; keep structure, drop dead flat rules (also inside @media)."""
    cache: dict = {}
    out = []
    i, n = 0, len(text)
    while i < n:
        if text.startswith("/*", i):
            j = text.find("*/", i)
            j = n if j < 0 else j + 2
            out.append(text[i:j]); i = j; continue
        j = text.find("{", i)
        if j < 0:
            out.append(text[i:]); break
        head = text[i:j]
        # comments that sit between the previous rule and this selector stay
        # (section banners the smoke tests assert on live there)
        m_pre = re.match(r"^(\s*(?:/\*.*?\*/\s*)*)", head, re.S)
        pre = m_pre.group(0) if m_pre else ""
        if pre.strip():
            out.append(pre)
            head = head[len(pre):]
            i += len(pre)
        # find matching close brace for this block
        depth, k = 1, j + 1
        while k < n and depth:
            if text[k] == "{": depth += 1
            elif text[k] == "}": depth -= 1
            k += 1
        body = text[j + 1:k - 1]
        sel = head.strip()
        at = sel.startswith("@")
        if at and "{" in body:  # nested block (@media / @supports): recurse
            out.append(head + "{" + prune(body, blob, removed) + "}")
        elif at or sel.startswith(":root") or "{" in body:
            out.append(text[i:k])
        else:
            sels = split_selectors(sel)
            dead = bool(sels) and all(
                CLASS_RE.findall(s) and all(not class_used(c, blob, cache) for c in CLASS_RE.findall(s))
                for s in sels
            )
            if dead:
                removed.append(text[i:k].strip())
                # keep the leading whitespace/newline so the file stays readable
                lead = re.match(r"^\s*", head).group(0)
                out.append(lead.rstrip(" \t"))
            else:
                out.append(text[i:k])
        i = k
    return "".join(out)


def main() -> int:
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    if not args:
        print(__doc__); return 2
    sheet = args[0]
    path = CSS / sheet
    text = path.read_text(encoding="utf-8")
    blob = source_blob()
    removed: list[str] = []
    new = prune(text, blob, removed)
    new = re.sub(r"\n{3,}", "\n\n", new)
    saved = len(text.encode()) - len(new.encode())
    print(f"{sheet}: {len(removed)} dead rules, {saved/1024:.1f} KB of {len(text.encode())/1024:.0f} KB")
    if "--write" in sys.argv:
        rep = ROOT / "scripts" / "reports"
        rep.mkdir(exist_ok=True)
        (rep / f"{sheet}.pruned.txt").write_text("\n\n".join(removed), encoding="utf-8")
        path.write_text(new, encoding="utf-8")
        print(f"written; removed rules listed in scripts/reports/{sheet}.pruned.txt")
    else:
        for r in removed[:25]:
            print("  -", r.split("{")[0].strip()[:110])
        if len(removed) > 25:
            print(f"  … +{len(removed)-25}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
