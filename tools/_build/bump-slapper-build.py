"""Advance SNAP SLAPPER's build number by one.

The version is 0.7.<builds>: 0.7 tracks the CMS line, and the last part counts
BUILDS -- exes actually produced and kept -- not commits.

That distinction is the whole point. SNAP SLAPPER was stamped 0.7.561 on its
first day, inheriting the CMS's number instead of starting its own, and then
moved once per commit: twenty-one numbers on 2026-09-13 alone, forty-six
versions in six weeks, arriving at 0.8.19 for a program that had never shipped.
It was also the only desktop tool missing from tools/desktop-versions.json, so
nothing ever objected.

Run this when you make a build you intend to keep, and raise the floor in the
same commit:

    python tools/_build/bump-slapper-build.py

It refuses to run twice without a build in between only by convention -- it
cannot see your intent -- so do not run it to "tidy up" a number.
"""

import io
import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
VERSION_FILE = os.path.join(ROOT, 'tools', 'hub', 'slapper_qt', '__init__.py')
REGISTRY = os.path.join(ROOT, 'tools', 'desktop-versions.json')
PATTERN = re.compile(r'BUILD_VERSION = "(\d+)\.(\d+)\.(\d+)"')


def main() -> int:
    source = io.open(VERSION_FILE, encoding='utf-8', newline='').read()
    match = PATTERN.search(source)
    if not match:
        print('No BUILD_VERSION literal in %s' % VERSION_FILE, file=sys.stderr)
        return 1
    major, minor, build = (int(part) for part in match.groups())
    new = '%d.%d.%d' % (major, minor, build + 1)
    old = '%d.%d.%d' % (major, minor, build)

    io.open(VERSION_FILE, 'w', encoding='utf-8', newline='').write(
        source.replace('BUILD_VERSION = "%s"' % old,
                       'BUILD_VERSION = "%s"' % new, 1))

    # The floor must move with it, or the next integrity run reports a revert.
    raw = io.open(REGISTRY, encoding='utf-8', newline='').read()
    registry = json.loads(raw)
    recorded = str(registry.get('snap-slapper', {}).get('version', ''))
    if recorded == old:
        raw = raw.replace('"version": "%s"' % old, '"version": "%s"' % new, 1)
        io.open(REGISTRY, 'w', encoding='utf-8', newline='').write(raw)
        print('SNAP SLAPPER %s -> %s  (version and floor)' % (old, new))
    else:
        print('SNAP SLAPPER %s -> %s' % (old, new))
        print('WARNING: the floor in desktop-versions.json reads %r, not %r. '
              'Raise it by hand in this commit.' % (recorded, old),
              file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
