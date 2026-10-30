# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""
COLD SNAP — version bumper.

Called by build.bat before each build (unless `build.bat norev`). Increments
the third component of BUILD_VERSION in coldsnap.py and drops a dated CHANGELOG
stub so the changelog's latest entry keeps matching BUILD_VERSION. Prints the
new version on success.

Pure stdlib. Safe to run by hand; idempotent on the CHANGELOG (won't double-add).
"""

import datetime
import pathlib
import re
import sys

HERE      = pathlib.Path(__file__).resolve().parent
MAIN      = HERE / "_version.py"   # single source of truth (was coldsnap.py)
CHANGELOG = HERE / "CHANGELOG.md"


def _fail(msg: str) -> "None":
    sys.stderr.write(f"bump_version: {msg}\n")
    raise SystemExit(1)


def read_version() -> "tuple[str, str]":
    if not MAIN.exists():
        _fail(f"{MAIN.name} not found.")
    text = MAIN.read_text(encoding="utf-8")
    m = re.search(r'BUILD_VERSION\s*=\s*"([^"]+)"', text)
    if not m:
        _fail("BUILD_VERSION not found in _version.py.")
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


def _parts(ver: str) -> "tuple[int, int, int] | None":
    m = re.fullmatch(r"(\d+)\.(\d+)\.(\d+)", ver.strip())
    return tuple(int(x) for x in m.groups()) if m else None


def read_installed(text: str) -> str:
    """The version the owner actually has, recorded in _version.py. '' if none."""
    m = re.search(r'INSTALLED_VERSION\s*=\s*"([^"]+)"', text)
    return m.group(1) if m else ""


def check_not_backward(new: str, installed: str) -> None:
    """Refuse to build a number at or below what is already installed.

    THE FAULT THIS EXISTS FOR. bump_version writes the new number into
    _version.py, so every build leaves a change that has to be COMMITTED.
    Twice it was not: the committed history runs 0.7.21, .22, .23, .25, .26 and
    stopped on 6 October while 0.7.28 was running on the owner's machine. The
    next build would have produced 0.7.27 and installed it OVER 0.7.28 -- a
    silent rollback, with the newer features disappearing and the version
    number going down. Codex caught it in preflight; nothing in the tooling
    would have.

    A stale checkout now fails here, loudly, instead of shipping backwards.
    """
    if not installed:
        return
    want, floor = _parts(new), _parts(installed)
    if want is None or floor is None:
        return
    if want > floor:
        return
    _fail(
        f"REFUSING TO BUILD {new}: {installed} is already installed.\n"
        f"\n"
        f"  _version.py BUILD_VERSION    {new} (after the bump)\n"
        f"  _version.py INSTALLED_VERSION {installed}\n"
        f"\n"
        f"This checkout is behind what the owner is running, which happens when\n"
        f"a previous build's version bump was never committed. Building now\n"
        f"would install an older number over a newer one.\n"
        f"\n"
        f"Set BUILD_VERSION to the installed version, commit it, and build\n"
        f"again -- or raise INSTALLED_VERSION if it is the thing that is stale."
    )


def main() -> None:
    old, text = read_version()
    new = next_version(old)
    check_not_backward(new, read_installed(text))
    bump_main(old, new, text)
    stub_changelog(new)
    print(new)


if __name__ == "__main__":
    main()
# ===== SNAPSMACK EOF =====
