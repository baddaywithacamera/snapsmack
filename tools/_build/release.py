#!/usr/bin/env python3
"""
SnapSmack Release Script
Usage: python3 tools/release.py <version> <codename>
Example: python3 tools/release.py 0.7.9f "Footrest"

Patches:
  - core/constants.php         (SNAPSMACK_VERSION, SNAPSMACK_VERSION_SHORT, SNAPSMACK_VERSION_CODENAME)
  - smack-central/sc-version.php  (SC_VERSION, SC_CODENAME)
  - CHANGELOG.md               (prepends a new section header)

Does NOT commit. Stage and commit yourself after reviewing the diff.
"""

import re
import sys
from datetime import date
from pathlib import Path

REPO_ROOT = Path(__file__).resolve().parent.parent


def die(msg):
    print(f"ERROR: {msg}", file=sys.stderr)
    sys.exit(1)


def patch_file(path, replacements):
    """Apply a list of (pattern, replacement) pairs to a file."""
    text = path.read_text(encoding="utf-8")
    for pattern, replacement in replacements:
        new_text = re.sub(pattern, replacement, text)
        if new_text == text:
            die(f"Pattern not found in {path}:\n  {pattern}")
        text = new_text
    path.write_text(text, encoding="utf-8")
    print(f"  patched  {path.relative_to(REPO_ROOT)}")


def prepend_changelog(path, version, codename, today):
    text = path.read_text(encoding="utf-8")
    # Skip if this version header already exists (idempotent re-run)
    if f"## {version} —" in text:
        print(f"  skipped  {path.relative_to(REPO_ROOT)}  (version already present)")
        return
    header = (
        f"## {version} — \"{codename}\" ({today})\n\n"
        f"### Added\n- *(fill in)*\n\n---\n\n"
    )
    # Insert after the first "---\n\n" separator (after the intro block)
    marker = "---\n\n"
    idx = text.find(marker)
    if idx == -1:
        die("Could not find '---' separator in CHANGELOG.md to insert after.")
    insert_at = idx + len(marker)
    new_text = text[:insert_at] + header + text[insert_at:]
    path.write_text(new_text, encoding="utf-8")
    print(f"  patched  {path.relative_to(REPO_ROOT)}")


def main():
    if len(sys.argv) != 3:
        print(__doc__)
        sys.exit(1)

    version = sys.argv[1].strip()
    codename = sys.argv[2].strip()
    today = date.today().isoformat()

    # Basic version sanity check
    if not re.fullmatch(r'\d+\.\d+\.\d+[a-z]?', version):
        die(f"Version '{version}' doesn't look right. Expected e.g. 0.7.9f or 0.8.0")

    print(f"\nSnapSmack release: {version} \"{codename}\"  ({today})\n")

    # --- core/constants.php ---
    constants_path = REPO_ROOT / "core" / "constants.php"
    patch_file(constants_path, [
        # Doc-block version line:  * Alpha v0.7.9e
        (r"( \* Alpha v)[\d.]+[a-z]?", rf"\g<1>{version}"),
        # define('SNAPSMACK_VERSION', 'Alpha 0.7.9e');
        (r"(define\('SNAPSMACK_VERSION',\s*'Alpha )[^']+(')", rf"\g<1>{version}\2"),
        # define('SNAPSMACK_VERSION_SHORT', '0.7.9e');
        (r"(define\('SNAPSMACK_VERSION_SHORT',\s*')[^']+(')", rf"\g<1>{version}\2"),
        # define('SNAPSMACK_VERSION_CODENAME', "Recliner");  — uses double quotes
        (r"(define\('SNAPSMACK_VERSION_CODENAME',\s*\")[^\"]+(\"\))", rf'\g<1>{codename}\2'),
    ])

    # --- smack-central/sc-version.php ---
    sc_version_path = REPO_ROOT / "smack-central" / "sc-version.php"
    patch_file(sc_version_path, [
        (r"(define\('SC_VERSION',\s*')[^']+(')", rf"\g<1>{version}\2"),
        (r"(define\('SC_CODENAME',\s*')[^']+(')", rf"\g<1>{codename}\2"),
    ])

    # --- CHANGELOG.md ---
    changelog_path = REPO_ROOT / "CHANGELOG.md"
    prepend_changelog(changelog_path, version, codename, today)

    print(f"\nDone. Review the diff, fill in CHANGELOG.md, then:\n")
    print(f"  git add core/constants.php smack-central/sc-version.php CHANGELOG.md")
    print(f"  git commit -m \"Bump to Alpha v{version} \\\"{codename}\\\"\"")
    print(f"  git tag {version}")
    print(f"  git push Github master && git push Github {version}")
    print()


if __name__ == "__main__":
    main()
