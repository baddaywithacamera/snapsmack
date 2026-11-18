# SNAPSMACK_EOF_HEADER
#     # ===== SNAPSMACK EOF =====
# Last non-empty line of this file MUST match the line above.
# Missing or different = truncated/corrupted. Restore before saving.
"""SS-1419 — the build script's judgements, tested instead of trusted.

The two faults these cover both shipped because the logic was in a .bat and
a .sh, where nothing runs it until a human does:

  - the script invented a version number every time it ran;
  - it tested for a one-file output the spec stopped producing, so every
    successful build printed "Build FAILED".

NOT CHECKED here: an actual PyInstaller build, an actual install, and the
batch and shell scripts themselves. What is checked is every decision they
now delegate to release_guard.py.
"""

import os
import sys

import pytest

HERE = os.path.dirname(os.path.abspath(__file__))
SUYB = os.path.dirname(HERE)
sys.path.insert(0, SUYB)

import release_guard  # noqa: E402


# ── the version is read, never invented ──────────────────────────────────────

def test_the_real_version_file_and_changelog_agree():
    """The repository as it stands must be buildable. If this fails, somebody
    bumped _version.py without writing the changelog entry -- which is the
    exact state the old script would have built and shipped."""
    assert release_guard.release_version() == release_guard.read_build_version()


def test_a_version_with_no_changelog_entry_is_refused(tmp_path):
    version = tmp_path / "_version.py"
    version.write_bytes(b'BUILD_VERSION = "9.9.99"\n')
    changelog = tmp_path / "CHANGELOG.md"
    changelog.write_text("# Changelog\n\n## Versioning\n\n## 0.7.54 - today\n",
                         encoding="utf-8")

    with pytest.raises(SystemExit) as refused:
        release_guard.release_version(str(version), str(changelog))
    message = str(refused.value)
    assert "9.9.99" in message
    assert "0.7.54" in message, "the refusal should say what the newest entry IS"


def test_a_version_with_a_changelog_entry_is_allowed(tmp_path):
    version = tmp_path / "_version.py"
    version.write_bytes(b'BUILD_VERSION = "0.7.55"\n')
    changelog = tmp_path / "CHANGELOG.md"
    changelog.write_text("# Changelog\n\n## 0.7.55 - today\n\n## 0.7.54 - before\n",
                         encoding="utf-8")
    assert release_guard.release_version(str(version), str(changelog)) == "0.7.55"


def test_prose_headings_are_not_mistaken_for_versions(tmp_path):
    """CHANGELOG.md has a '## Versioning' heading. A looser pattern would let
    a build pass by matching a heading that is not a release at all."""
    changelog = tmp_path / "CHANGELOG.md"
    changelog.write_text("## Versioning\n\n## Unreleased\n\n## 0.7.54 - today\n",
                         encoding="utf-8")
    assert release_guard.changelog_versions(str(changelog)) == ["0.7.54"]


def test_the_guard_does_not_write_to_the_version_file(tmp_path):
    """The whole complaint: running the build must not change the version."""
    version = tmp_path / "_version.py"
    version.write_bytes(b'BUILD_VERSION = "0.7.55"\n')
    changelog = tmp_path / "CHANGELOG.md"
    changelog.write_text("## 0.7.55 - today\n", encoding="utf-8")
    before = version.read_bytes()

    release_guard.release_version(str(version), str(changelog))

    assert version.read_bytes() == before


# ── the output path matches the spec that actually runs ──────────────────────

def test_expected_exe_is_the_onedir_path():
    got = release_guard.expected_exe(os.path.join("X:", "build", "dist"))
    assert got.endswith(os.path.join("suyb", "suyb.exe"))
    assert "smackupyourbackup-" not in got, (
        "back to the one-file name the spec stopped producing")


def test_the_spec_really_is_onedir():
    """The constants above are only right while the spec stays onedir. If
    somebody switches it back to one-file, this says so here rather than
    letting the scripts silently look in the wrong place again."""
    with open(os.path.join(SUYB, "smackupyourbackup.spec"), "r",
              encoding="utf-8", errors="replace") as handle:
        spec = handle.read()
    assert "exclude_binaries=True" in spec, "spec is no longer onedir"
    assert "COLLECT(" in spec, "spec has no COLLECT, so it is not onedir"
    assert "name='suyb'" in spec, "the collected folder is no longer named suyb"


# ── an install must not land on a running backup ─────────────────────────────

def test_a_missing_file_is_not_in_use(tmp_path):
    assert release_guard.is_in_use(str(tmp_path / "nothing.exe")) is False


def test_an_ordinary_file_is_not_in_use(tmp_path):
    target = tmp_path / "suyb.exe"
    target.write_bytes(b"MZ")
    assert release_guard.is_in_use(str(target)) is False


def test_a_file_that_cannot_be_opened_for_writing_is_in_use(tmp_path, monkeypatch):
    """Codex's 2026-11-18 blocker: the installed exe was running as PID 48116
    while a backup may have been in flight. Windows locks a running image
    against writing, which is what this detects. Simulated here rather than
    by launching a process, and the simulation is the same OSError the real
    lock raises."""
    target = tmp_path / "suyb.exe"
    target.write_bytes(b"MZ")

    real_open = open

    def locked(path, mode="r", *args, **kwargs):
        if os.path.abspath(str(path)) == os.path.abspath(str(target)) and "+" in mode:
            raise PermissionError(32, "The process cannot access the file")
        return real_open(path, mode, *args, **kwargs)

    monkeypatch.setattr("builtins.open", locked)
    assert release_guard.is_in_use(str(target)) is True


def test_check_idle_refuses_with_a_nonzero_exit(tmp_path, monkeypatch, capsys):
    target = tmp_path / "suyb.exe"
    target.write_bytes(b"MZ")
    monkeypatch.setattr(release_guard, "is_in_use", lambda p: True)

    assert release_guard._main(["release_guard.py", "check-idle", str(target)]) == 1
    err = capsys.readouterr().err
    assert "Close SMACK UP YOUR BACKUP" in err
    assert "Nothing has been installed" in err, (
        "a refusal must say what state the machine is left in")


def test_check_idle_allows_an_idle_target(tmp_path):
    target = tmp_path / "suyb.exe"
    target.write_bytes(b"MZ")
    assert release_guard._main(["release_guard.py", "check-idle", str(target)]) == 0


# ── the staged artifact can be identified afterwards ─────────────────────────

def test_sha256_matches_hashlib(tmp_path):
    import hashlib
    target = tmp_path / "suyb.exe"
    target.write_bytes(b"MZ\x00payload")
    assert release_guard.sha256_of(str(target)) == \
        hashlib.sha256(b"MZ\x00payload").hexdigest().upper()


# ── the scripts must actually delegate, or none of the above matters ─────────

def script_code(script: str) -> str:
    """The script with its comment lines removed.

    My first version of these assertions searched the whole file, and the
    build.sh check failed on the comment that EXPLAINS the one-file name was
    removed. A test that cannot tell an instruction from a note about an
    instruction is a test that punishes writing things down.
    """
    with open(os.path.join(SUYB, script), "r", encoding="utf-8",
              errors="replace") as handle:
        lines = handle.read().splitlines()
    marker = "REM" if script.endswith(".bat") else "#"
    return chr(10).join(
        line for line in lines
        if not line.strip().upper().startswith(marker.upper())
    )


@pytest.mark.parametrize("script", ["build.bat", "build.sh"])
def test_the_build_scripts_no_longer_bump_the_version(script):
    body = script_code(script)
    assert "bump_version" not in body, (
        f"{script} still invents a version number")
    assert "release_guard" in body, (
        f"{script} does not ask release_guard.py for the version")


@pytest.mark.parametrize("script", ["build.bat", "build.sh"])
def test_the_build_scripts_look_for_the_onedir_output(script):
    body = script_code(script)
    assert "smackupyourbackup-" not in body, (
        f"{script} still refers to the one-file name the spec stopped producing")
    assert "expected-exe" in body, (
        f"{script} does not ask release_guard.py where the exe lands")


@pytest.mark.parametrize("script", ["build.bat", "build.sh"])
def test_the_build_scripts_check_the_target_is_idle_before_installing(script):
    body = script_code(script)
    assert body.count("check-idle") >= 2, (
        f"{script} must check before building AND again before installing; "
        "a build takes minutes and SUYB can be opened in them")


@pytest.mark.parametrize("script", ["build.bat", "build.sh"])
def test_the_build_scripts_keep_a_rollback(script):
    body = script_code(script)
    assert "rollback" in body.lower(), (
        f"{script} overwrites the installed build with no way back")

# ===== SNAPSMACK EOF =====
