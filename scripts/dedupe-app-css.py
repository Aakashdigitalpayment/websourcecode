#!/usr/bin/env python3
"""Mechanical, behaviour-neutral cleanup of the frozen app-*.css bases:

  1. exact duplicate rules — same @media context, same selector list, same
     declarations: the EARLIER copies are dropped (the later one already wins);
  2. inert design-token declarations — `--x: …` inside a top-level `:root {}`
     of a base sheet when global.css (loaded after every base) declares the
     same token with the IDENTICAL value in its own :root — removing the copy
     cannot change the resolved value whatever the load order.

Dry run prints counts; --write rewrites the sheet. Verify with full-page
screenshot diffs before committing (see README → CSS architecture).

  python3 scripts/dedupe-app-css.py app-public.css [--write]
"""
from __future__ import annotations
import re, sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CSS = ROOT / "assets" / "css"
NORM = lambda s: re.sub(r"\s+", " ", s.strip())


def global_tokens() -> dict[str, str]:
    """token → normalised value as global.css :root declares it (last wins)."""
    t = re.sub(r"/\*.*?\*/", "", (CSS / "global.css").read_text(), flags=re.S)
    toks: dict[str, str] = {}
    for m in re.finditer(r"(?:^|\})\s*:root\s*\{([^{}]*)\}", t):
        for k, v in re.findall(r"(--[\w-]+)\s*:\s*([^;]+)", m.group(1)):
            toks[k] = NORM(v).lower()
    return toks


def walk(text: str, media: str, seen: dict, out: list, stats: dict, toks: set[str], top: bool) -> str:
    i, n = 0, len(text)
    buf = []
    while i < n:
        if text.startswith("/*", i):
            j = text.find("*/", i); j = n if j < 0 else j + 2
            buf.append(text[i:j]); i = j; continue
        j = text.find("{", i)
        if j < 0:
            buf.append(text[i:]); break
        head = text[i:j]
        m_pre = re.match(r"^(\s*(?:/\*.*?\*/\s*)*)", head, re.S)
        pre = m_pre.group(0) if m_pre else ""
        if pre.strip():
            buf.append(pre); head = head[len(pre):]
        depth, k = 1, j + 1
        while k < n and depth:
            if text[k] == "{": depth += 1
            elif text[k] == "}": depth -= 1
            k += 1
        body = text[j + 1:k - 1]
        sel = NORM(head)
        if sel.startswith("@") and "{" in body:
            inner = walk(body, media + "|" + sel, seen, out, stats, toks, False)
            buf.append(head + "{" + inner + "}")
        elif sel.startswith("@") or "{" in body:
            buf.append(text[i:k] if not pre.strip() else head + "{" + body + "}")
        else:
            decls = body
            if top and sel == ":root" and toks:
                kept = []
                for d in re.split(r";", decls):
                    mm = re.match(r"\s*(--[\w-]+)\s*:\s*(.+)$", d, re.S)
                    # only an IDENTICAL value is provably inert whatever the load order
                    if mm and toks.get(mm.group(1)) == NORM(mm.group(2)).lower():
                        stats["tokens"] += 1
                        continue
                    kept.append(d)
                decls = ";".join(kept)
                if NORM(decls) in ("", ";"):
                    stats["rules"] += 1
                    i = k
                    continue
            key = (media, sel, NORM(decls))
            if key in seen:
                # drop this EARLIER copy: mark position; later copy remains
                pos = seen[key]
                out[pos] = ""
                stats["dups"] += 1
            seen[key] = len(out)
            out.append(head + "{" + decls + "}")
            buf.append("\0%d\0" % (len(out) - 1))
        i = k
    return "".join(buf)


def main() -> int:
    args = [a for a in sys.argv[1:] if not a.startswith("--")]
    if not args:
        print(__doc__); return 2
    path = CSS / args[0]
    text = path.read_text(encoding="utf-8")
    toks = global_tokens() if args[0].startswith("app-") else {}
    seen: dict = {}; out: list = []; stats = {"dups": 0, "tokens": 0, "rules": 0}
    skel = walk(text, "", seen, out, stats, toks, True)
    new = re.sub(r"\0(\d+)\0", lambda m: out[int(m.group(1))], skel)
    new = re.sub(r"\n{3,}", "\n\n", new)
    print(f"{args[0]}: {stats['dups']} duplicate rules, {stats['tokens']} inert tokens, {stats['rules']} emptied :root blocks, "
          f"{(len(text.encode())-len(new.encode()))/1024:.1f} KB of {len(text.encode())/1024:.0f} KB")
    if "--write" in sys.argv:
        path.write_text(new, encoding="utf-8")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
