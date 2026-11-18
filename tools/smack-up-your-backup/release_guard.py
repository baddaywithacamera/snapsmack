"""SMACK UP YOUR BACKUP — the decisions a release script must get right.

# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.

SS-1419. build.bat and build.sh carried three judgements in shell, where
nothing can test them, and two of the three were wrong:

  1. THEY BUMPED THE VERSION THEMSELVES. Running the build was enough to
     invent a new version number, so a rebuild of the same code produced a
     different one and the changelog never knew about it. The version is a
     decision somebody makes and writes down; the build's job is to refuse
     when the writing down has not happened.

  2. THEY WAITED FOR A FILE THE SPEC STOPPED PRODUCING. The scripts tested
     for dist/smackupyourbackup-<ver>.exe. smackupyourbackup.spec builds
     onedir -- exclude_binaries=True, EXE(name='suyb'), COLLECT(name='suyb')
     -- so the output is dist/suyb/suyb.exe and that test could never be
     true. Every successful build printed "Build FAILED". A script that
     reports failure on success teaches its operator to ignore it.

  3. They overwrote the installed copy with no rollback and no check that it
     was idle. Codex hit the second half of that on 2026-11-18: the installed
     suyb.exe was running as PID 48116 while a backup may have been in
     flight, and he stopped by hand rather than let the script decide.

Shell is where this logic goes to stop being checked, so it lives here and
the scripts call it. Every function below is covered by
tests/test_ss1419_release_guard.py.
"""

from __future__ import annotations

import hashlib
import os
import re
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
VERSION_FILE = os.path.join(HERE, "_version.py")
CHANGELOG_FILE = os.path.join(HERE, "CHANGELOG.md")

#: What smackupyourbackup.spec actually produces: COLLECT(name='suyb') around
#: EXE(name='suyb'). Kept here so the scripts cannot disagree with the spec
#: the way they did before.
DIST_SUBDIR = "suyb"
EXE_BASENAME = "suyb.exe"


def read_build_version(version_file: str = VERSION_FILE) -> str:
    """The version as declared, never as incremented."""
    with open(version_file, "rb") as handle:
        data = handle.read()
    match = re.search(rb'BUILD_VERSION\s*=\s*"([^"]+)"', data)
    if not match:
        raise ValueError(f"no BUILD_VERSION literal in {version_file}")
    return match.group(1).decode("ascii")


def changelog_versions(changelog_file: str = CHANGELOG_FILE) -> list[str]:
    """Every version that has a '## <version>' heading, in file order.

    The 'Versioning' heading and any other prose heading are ignored: only a
    heading that starts with something version-shaped counts.
    """
    with open(changelog_file, "r", encoding="utf-8", errors="replace") as handle:
        text = handle.read()
    return re.findall(r"^##\s+(\d+(?:\.\d+)+)\b", text, flags=re.MULTILINE)


def release_version(version_file: str = VERSION_FILE,
                    changelog_file: str = CHANGELOG_FILE) -> str:
    """The version this build is allowed to produce, or raise.

    Refusing is the point. A build whose version has no changelog entry is a
    build nobody has decided to make.
    """
    version = read_build_version(version_file)
    versions = changelog_versions(changelog_file)
    if version not in versions:
        newest = versions[0] if versions else "(none)"
        raise SystemExit(
            f"REFUSED: _version.py declares {version}, and CHANGELOG.md has no "
            f"'## {version}' entry (its newest is {newest}).\n"
            "  The build does not invent version numbers any more. Either add "
            "the changelog entry for this version,\n"
            "  or set BUILD_VERSION to the release you actually mean to build."
        )
    return version


def expected_exe(dist_root: str) -> str:
    """Where the onedir build puts the executable."""
    return os.path.join(dist_root, DIST_SUBDIR, EXE_BASENAME)


def sha256_of(path: str) -> str:
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest().upper()


def is_in_use(path: str) -> bool:
    """True if the file cannot be opened for writing because something holds it.

    Windows locks a running executable against writing, so this answers "is
    SUYB running from this exact path" without naming processes or needing a
    process table. A missing file is not in use.

    It is deliberately conservative in one direction only: anything that
    stops us opening the file for write is treated as in use, because the
    cost of a false "busy" is a message asking the owner to close SUYB, and
    the cost of a false "idle" is overwriting a backup in flight.
    """
    if not os.path.exists(path):
        return False
    try:
        with open(path, "r+b"):
            return False
    except OSError:
        return True


def _main(argv: list[str]) -> int:
    if len(argv) < 2:
        sys.stderr.write("usage: release_guard.py <release-version|expected-exe DIST|"
                         "sha256 PATH|check-idle PATH>\n")
        return 2
    command = argv[1]
    try:
        if command == "release-version":
            print(release_version())
            return 0
        if command == "expected-exe":
            print(expected_exe(argv[2]))
            return 0
        if command == "sha256":
            print(sha256_of(argv[2]))
            return 0
        if command == "check-idle":
            if is_in_use(argv[2]):
                sys.stderr.write(
                    f"REFUSED: {argv[2]} is running or locked.\n"
                    "  It may be part-way through a backup. Close SMACK UP YOUR BACKUP "
                    "and run this again.\n"
                    "  Nothing has been installed; the staged build is untouched.\n")
                return 1
            return 0
    except SystemExit as stop:          # release_version's refusal
        sys.stderr.write(f"{stop}\n")
        return 1
    except (OSError, ValueError) as err:
        sys.stderr.write(f"release_guard: {err}\n")
        return 1
    sys.stderr.write(f"release_guard: unknown command {command!r}\n")
    return 2


if __name__ == "__main__":
    raise SystemExit(_main(sys.argv))

# ===== SNAPSMACK EOF =====
