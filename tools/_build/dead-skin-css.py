"""Which skin CSS styles markup that nothing emits any more.

Sean, 19 October 2026: "my sites have been broken since oct 5 and it's oct 18."
He had been finding them one at a time, by eye, on live sites -- the GALLERIA
filmstrip, CHAPLIN's EXIF table, SLICKR's byline, RATIONAL GEO's archive grid.
Every one had the same shape: a stylesheet still carrying rules for markup the
strict conversion stopped emitting. A rule with nothing behind it is invisible
to a test that only asks whether a class exists SOMEWHERE.

This asks the opposite question, which is the useful one: for every class a
skin's stylesheet styles, does anything in core/, skins/ or assets/js still
emit it? What comes back is a map of features that silently stopped rendering.

    python tools/_build/dead-skin-css.py

Noise is filtered: editor utilities an author types into a post (align*, wp-*),
state classes, and numbered variants where the renderer only ever emits the one
that applies. What is left is clustered by feature word, because thirty dead
rules named *-comment-* in five skins is one missing feature, not thirty.

Deliberately a report, not a gate. The count was 894 across 29 skins the first
time it ran; a ratchet starting there would mean nothing.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import os, re, sys, collections
sys.stdout.reconfigure(encoding="utf-8", errors="replace")
root = os.getcwd()
emit = []
for base in ("core", "skins", "assets/js"):
    for dp, _, fns in os.walk(os.path.join(root, base)):
        for fn in fns:
            if fn.endswith((".php", ".js")):
                emit.append(open(os.path.join(dp, fn), encoding="utf-8", errors="replace").read())
EMIT = "\n".join(emit)

# Noise: editor/content utilities authors type into posts, and numbered variants
# where the renderer only ever emits the one that applies.
NOISE = re.compile(r"^(align|asset-|wp-|has-|is-|cycle-|archive-cols-\d|archive-thumbs-)")
CLASS_SEL = re.compile(r"\.(-?[A-Za-z_][\w-]*)")
clusters = collections.Counter()
examples = {}
for slug in sorted(os.listdir(os.path.join(root, "skins"))):
    css = os.path.join(root, "skins", slug, "style.css")
    if not os.path.isfile(css):
        continue
    text = re.sub(r"/\*.*?\*/", " ", open(css, encoding="utf-8", errors="replace").read(), flags=re.S)
    names = set()
    for block in re.findall(r"([^{}]+)\{", text):
        if "@" in block:
            continue
        for m in CLASS_SEL.finditer(block):
            if len(m.group(1)) > 3:
                names.add(m.group(1))
    for n in sorted(names):
        if n in EMIT or NOISE.match(n):
            continue
        # Cluster on the feature word, e.g. xx-comment-body -> comment
        parts = re.split(r"-", n)
        key = next((p for p in parts if len(p) > 3 and p not in ("item", "link", "wrap", "inner", "text")), parts[-1])
        clusters[key] += 1
        examples.setdefault(key, []).append(slug + ":" + n)

print(f"{'FEATURE':<16}{'DEAD RULES':>11}   where")
for key, n in clusters.most_common(18):
    where = sorted({e.split(":")[0] for e in examples[key]})
    print(f"{key:<16}{n:>11}   {len(where)} skins: " + ", ".join(where[:5]) + ("..." if len(where) > 5 else ""))
# ===== SNAPSMACK EOF =====
