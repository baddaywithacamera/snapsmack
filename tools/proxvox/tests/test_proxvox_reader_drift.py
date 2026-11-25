# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the canonical Python EOF marker below.

import hashlib
from pathlib import Path
import shlex
import shutil
import subprocess

import pytest


PROVISIONER = Path(__file__).resolve().parents[1] / 'server' / 'provision-host.sh'
BASH = shutil.which('bash')
GIT_BASH = Path('C:/Program Files/Git/bin/bash.exe')
if GIT_BASH.is_file():
    BASH = str(GIT_BASH)


@pytest.mark.skipif(not BASH, reason='requires Bash')
@pytest.mark.parametrize('installed', [b'reviewed reader', b'old reader', None])
def test_verify_reader(tmp_path, installed):
    wrapper = tmp_path / 'reader with spaces'
    if installed is not None:
        wrapper.write_bytes(installed)
        original_mtime = wrapper.stat().st_mtime_ns

    pinned = hashlib.sha256(b'reviewed reader').hexdigest()
    script = tmp_path / 'provision-host.sh'
    lines = PROVISIONER.read_text(encoding='utf-8').splitlines()
    overrides = {
        'WRAPPER_PATH': shlex.quote(wrapper.as_posix()),
        'WRAPPER_SHA256': pinned,
    }
    for i, line in enumerate(lines):
        name = line.split('=', 1)[0]
        if name in overrides:
            lines[i] = f'{name}={overrides.pop(name)}'
    assert not overrides
    script.write_bytes(('\n'.join(lines) + '\n').encode())

    result = subprocess.run(
        [BASH, script.as_posix(), 'verify'], capture_output=True, encoding='utf-8', timeout=15,
    )

    if installed is None:
        assert result.returncode == 1
        assert 'no reader installed' in result.stderr
        assert not wrapper.exists()
    else:
        actual = hashlib.sha256(installed).hexdigest()
        output = result.stdout if actual == pinned else result.stderr
        assert result.returncode == (0 if actual == pinned else 1)
        assert ('READER PASS' if actual == pinned else 'READER DRIFT') in output
        assert actual in output and pinned in output
        assert wrapper.read_bytes() == installed
        assert wrapper.stat().st_mtime_ns == original_mtime


# ===== SNAPSMACK EOF =====
