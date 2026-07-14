# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""
Smack Your Batch Up — version bumper.

Called by build.bat before each build (unless `build.bat norev`). Increments
the third component of BUILD_VERSION in main.py, clones the matching PyInstaller
spec to the new version, and drops a dated CHANGELOG stub so the changelog's
latest entry keeps matching BUILD_VERSION. Prints the new version on success.

Pure stdlib. Safe to run by hand; idempotent on the CHANGELOG (won't double-add).
"""

import datetime
import pathlib
import re
import sys

HERE      = pathlib.Path(__file__).resolve().parent
MAIN      = HERE / "main.py"
CHANGELOG = HERE / "CHANGELOG.md"
SPEC_STEM = "smackyourbatchup-"


def _fail(msg: str) -> "None":
    sys.stderr.write(f"bump_version: {msg}\n")
    raise SystemExit(1)


def read_version() -> "tuple[str, str]":
    if not MAIN.exists():
        _fail(f"{MAIN.name} not found.")
    text = MAIN.read_text(encoding="utf-8")
    m = re.search(r'BUILD_VERSION\s*=\s*"([^"]+)"', text)
    if not m:
        _fail("BUILD_VERSION not found in main.py.")
    return m.group(1), text


def next_version(ver: str) -> str:
    m = re.fullmatch(r"(\d+)\.(\d+)\.(\d+)", ver)
    if not m:
        _fail(f"version {ver!r} is not plain MAJOR.MINOR.PATCH "
              "(legacy letter-suffix?) — bump it by hand.")
    major, minor, patch = (int(x) for x in m.groups())
    return f"{major}.{minor}.{patch + 1}"


def bump_main(old: str, new: str, text: str) -> None:
    updated = text.replace(f'BUILD_VERSION = "{old}"',
                           f'BUILD_VERSION = "{new}"', 1)
    if updated == text:
        _fail("could not rewrite BUILD_VERSION line.")
    MAIN.write_text(updated, encoding="utf-8")


def clone_spec(old: str, new: str) -> None:
    old_spec = HERE / f"{SPEC_STEM}{old}.spec"
    new_spec = HERE / f"{SPEC_STEM}{new}.spec"
    if new_spec.exists():
        return  # already cloned (e.g. re-run after a failed build)
    if not old_spec.exists():
        _fail(f"previous spec {old_spec.name} not found — cannot clone for the build.")
    spec_text = old_spec.read_text(encoding="utf-8")
    # Only the EXE name field carries the version: smackyourbatchup-<ver>.
    spec_text = spec_text.replace(f"{SPEC_STEM}{old}", f"{SPEC_STEM}{new}")
    new_spec.write_text(spec_text, encoding="utf-8")


def stub_changelog(new: str) -> None:
    # Non-fatal: never block a build over a changelog stub.
    try:
        if not CHANGELOG.exists():
            return
        cl = CHANGELOG.read_text(encoding="utf-8")
        if re.search(rf"^## {re.escape(new)}\b", cl, flags=re.M):
            return  # entry already exists
        today = datetime.date.today().isoformat()
        stub = (f"## {new} — {today}\n\n"
                f"### Changed\n- _TODO: describe this build._\n\n---\n\n")
        m = re.search(r"^## \d", cl, flags=re.M)   # first existing version entry
        if not m:
            return
        idx = m.start()
        CHANGELOG.write_text(cl[:idx] + stub + cl[idx:], encoding="utf-8")
    except Exception as e:   # noqa: BLE001 — best-effort only
        sys.stderr.write(f"bump_version: changelog stub skipped ({e})\n")


def main() -> None:
    old, text = read_version()
    new = next_version(old)
    bump_main(old, new, text)
    # No per-version spec clone: build.bat uses the single fixed sumnabatch.spec
    # (it auto-bundles every local .py). Cloning smackyourbatchup-<ver>.spec was
    # the SYBU pattern that propagated a truncated spec into 0.7.19/0.7.20.
    stub_changelog(new)
    print(new)


if __name__ == "__main__":
    main()
# ===== SNAPSMACK EOF =====
