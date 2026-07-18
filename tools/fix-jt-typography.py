#!/usr/bin/env python3
# SNAPSMACK_EOF_HEADER: last non-empty line must be the SNAPSMACK EOF comment.
# fix-jt-typography.py — set JIVE TURKEY's title weight + tagline size to the
# classic-Insta defaults (title 700 Bold, tagline 20px). Colours untouched.
# Bumps the skin manifest version. Run natively from repo root:
#   python tools\fix-jt-typography.py            # dry run
#   python tools\fix-jt-typography.py --write     # apply
import os, re, sys

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
p = os.path.join(REPO, 'skins', 'jive-turkey', 'manifest.php')
t = open(p, encoding='utf-8').read()
orig = t

def set_default(text, key, newval):
    """Set the 'default' of the control block that starts with '<key>' => [."""
    i = text.find("'%s'" % key)
    if i == -1:
        return text, None, None
    block = text[i:i + 500]
    m = re.search(r"('default'\s*=>\s*')([^']*)(')", block)
    if not m:
        return text, None, None
    old = m.group(2)
    newblock = block[:m.start(2)] + newval + block[m.end(2):]
    return text[:i] + newblock + text[i + 500:], old, newval

changes = []
for key, want in [('jt_blog_title_weight', '700'), ('jt_tagline_size', '20')]:
    t, old, new = set_default(t, key, want)
    changes.append((key, old, new))

# bump skin version  'version' => '0.1.7'  ->  '0.1.8'
vm = re.search(r"('version'\s*=>\s*')(\d+)\.(\d+)\.(\d+)(')", t)
oldver = newver = None
if vm:
    oldver = f"{vm.group(2)}.{vm.group(3)}.{vm.group(4)}"
    newver = f"{vm.group(2)}.{vm.group(3)}.{int(vm.group(4))+1}"
    t = t[:vm.start()] + vm.group(1) + newver + vm.group(5) + t[vm.end():]

print("Planned changes:")
for k, o, n in changes:
    print(f"  {k}: {o} -> {n}" + ("   (already correct / not found)" if o is None else ""))
print(f"  skin version: {oldver} -> {newver}")

if '--write' not in sys.argv[1:]:
    print("\nDRY RUN — re-run with --write to apply.")
    sys.exit(0)

if t == orig:
    print("\nNothing changed (already correct?). Not writing.")
    sys.exit(0)

open(p, 'w', encoding='utf-8').write(t)
print("\nWritten. Then:")
print("  git add skins/jive-turkey/manifest.php")
print(f'  git commit -m "JIVE TURKEY: typography defaults match classic Insta (title 700, tagline 20px); skin v{newver}"')
print("  git push Github master")
print("  -> SKIN PACKAGER: republish JIVE TURKEY  ->  craptasti skin -> " + str(newver) + ", hard reload.")
# ===== SNAPSMACK EOF =====
