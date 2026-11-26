"""SNAP HQ pending-build tests using disposable executables and receipts.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import hashlib
from pathlib import Path
import sys

import pytest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from pending_builds import PendingBuilds


COMMIT = '1' * 40


def prepare(tmp_path):
    shared = tmp_path / 'shared'
    target = shared / 'sybu' / 'sybu.exe'
    target.parent.mkdir(parents=True)
    target.write_bytes(b'previous build')
    candidate = tmp_path / 'reviewed.exe'
    candidate.write_bytes(b'reviewed build')
    digest = hashlib.sha256(candidate.read_bytes()).hexdigest()
    builds = PendingBuilds(tmp_path / 'protected', {
        'sybu': target,
        'suyb': shared / 'suyb' / 'suyb.exe',
    })
    return builds, target, candidate, digest


def test_stage_leaves_launch_file_untouched_and_apply_records_version(tmp_path):
    builds, target, candidate, digest = prepare(tmp_path)
    builds.stage('sybu', candidate, '0.7.79', COMMIT, digest)
    assert target.read_bytes() == b'previous build'
    assert builds.pending('suyb') is None
    assert builds.apply('sybu') == {'tool': 'sybu', 'version': '0.7.79', 'commit': COMMIT, 'sha256': digest}
    assert target.read_bytes() == candidate.read_bytes()
    assert builds.pending('sybu') is None
    assert builds.installed('sybu')['commit'] == COMMIT
    assert builds.apply('sybu') is None


def test_modified_pending_payload_never_replaces_live_executable(tmp_path):
    builds, target, candidate, digest = prepare(tmp_path)
    payload = builds.stage('sybu', candidate, '0.7.79', COMMIT, digest)
    payload.write_bytes(b'unreviewed build')
    with pytest.raises(ValueError, match='reviewed hash'):
        builds.apply('sybu')
    assert target.read_bytes() == b'previous build'
    assert builds.pending('sybu')['sha256'] == digest


def test_busy_file_holds_pending_until_the_next_launch(tmp_path, monkeypatch):
    import pending_builds

    builds, target, candidate, digest = prepare(tmp_path)
    builds.stage('sybu', candidate, '0.7.79', COMMIT, digest)
    replace = pending_builds.os.replace

    def busy(source, destination):
        if destination == target:
            raise PermissionError('open Windows executable')
        return replace(source, destination)

    with monkeypatch.context() as patch:
        patch.setattr(pending_builds.os, 'replace', busy)
        with pytest.raises(ValueError, match='still staged'):
            builds.apply('sybu')
    assert target.read_bytes() == b'previous build'
    assert builds.apply('sybu')['version'] == '0.7.79'
    target.write_bytes(b'changed elsewhere')
    assert builds.installed('sybu') is None


def test_staging_refuses_unreviewed_digest_and_runtime_bundle(tmp_path):
    builds, target, candidate, digest = prepare(tmp_path)
    with pytest.raises(ValueError, match='reviewed value'):
        builds.stage('sybu', candidate, '0.7.79', COMMIT, '0' * 64)
    assert builds.pending('sybu') is None
    (target.parent / '_internal').mkdir()
    with pytest.raises(ValueError, match='complete runtime bundle'):
        builds.stage('sybu', candidate, '0.7.79', COMMIT, digest)

def test_matching_runtime_bundle_can_be_reused_until_it_changes(tmp_path):
    builds, target, candidate, digest = prepare(tmp_path)
    for executable in (target, candidate):
        runtime = executable.parent / '_internal'
        runtime.mkdir()
        (runtime / 'library.dll').write_bytes(b'reviewed dependency')
    builds.stage('sybu', candidate, '0.7.79', COMMIT, digest)
    library = target.parent / '_internal' / 'library.dll'
    library.write_bytes(b'different dependency')
    with pytest.raises(ValueError, match='complete runtime bundle'):
        builds.apply('sybu')
    assert target.read_bytes() == b'previous build'
    library.write_bytes(b'reviewed dependency')
    assert builds.apply('sybu')['version'] == '0.7.79'
    assert library.read_bytes() == b'reviewed dependency'


# ===== SNAPSMACK EOF =====
