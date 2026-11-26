"""Stage reviewed desktop builds outside the shared root and apply at launch.

# SNAPSMACK_EOF_HEADER
# Last non-empty line must be the Python SNAPSMACK EOF marker.
"""

import argparse
from contextlib import contextmanager
import hashlib
import json
import os
import re
import shutil
from pathlib import Path
from tempfile import NamedTemporaryFile


def _digest(path):
    with Path(path).open('rb') as stream:
        fingerprint = hashlib.sha256()
        for block in iter(lambda: stream.read(1024 * 1024), b''):
            fingerprint.update(block)
    return fingerprint.hexdigest()


def runtime_digest(folder):
    """Hash all bundle paths and contents. No redirected files are eligible."""
    folder = Path(folder).absolute()
    if folder.resolve() != folder or not folder.is_dir():
        raise ValueError('The runtime bundle must be an ordinary directory.')
    inventory = []
    for file in sorted(folder.rglob('*')):
        if file.resolve() != file:
            raise ValueError('The runtime bundle contains a redirected path.')
        if file.is_file():
            inventory.append((file.relative_to(folder).as_posix(), _digest(file)))
    if not inventory:
        raise ValueError('The runtime bundle has no files.')
    return hashlib.sha256(json.dumps(inventory, separators=(',', ':')).encode('utf-8')).hexdigest()


def _write_receipt(path, receipt):
    path.parent.mkdir(parents=True, exist_ok=True)
    with NamedTemporaryFile(mode='w', encoding='utf-8', dir=path.parent, delete=False) as stream:
        temp = Path(stream.name)
        try:
            json.dump(receipt, stream)
            stream.close()
            os.replace(temp, path)
        finally:
            temp.unlink(missing_ok=True)


@contextmanager
def _slot_lock(folder):
    folder.mkdir(parents=True, exist_ok=True)
    with (folder / 'slot.lock').open('a+b') as stream:
        stream.seek(0)
        if os.name == 'nt':
            import msvcrt
            try:
                msvcrt.locking(stream.fileno(), msvcrt.LK_NBLCK, 1)
            except OSError as exc:
                raise ValueError('Another SNAP HQ operation is using this pending slot.') from exc
        else:
            import fcntl
            fcntl.flock(stream.fileno(), fcntl.LOCK_EX)
        yield


class PendingBuilds:
    def __init__(self, state_dir, targets):
        self.state_dir = Path(state_dir).resolve()
        self.targets = {slot: Path(target).absolute() for slot, target in targets.items()}

    def _state(self, slot):
        if slot not in self.targets:
            raise ValueError(f'Unknown SNAP HQ tool: {slot}')
        return self.state_dir / slot

    def _receipt(self, slot, name):
        path = self._state(slot) / f'{name}.json'
        try:
            receipt = json.loads(path.read_text(encoding='utf-8'))
        except FileNotFoundError:
            return None
        if (not isinstance(receipt, dict) or receipt.get('tool') != slot
                or not re.fullmatch(r'[a-f0-9]{64}', str(receipt.get('sha256', '')))
                or not re.fullmatch(r'[a-f0-9]{40}', str(receipt.get('commit', '')))
                or (receipt.get('runtime_sha256') is not None
                    and not re.fullmatch(r'[a-f0-9]{64}', str(receipt['runtime_sha256'])))
                or not re.fullmatch(r'[0-9][a-zA-Z0-9.\-]{0,31}', str(receipt.get('version', '')))):
            raise ValueError(f'Invalid {name} build record for {slot}')
        return receipt

    def _check_runtime(self, slot, expected):
        installed = self.targets[slot].parent / '_internal'
        if expected is None and not installed.exists():
            return
        if expected is not None and installed.is_dir() and runtime_digest(installed) == expected:
            return
        raise ValueError(f'{slot} needs its complete runtime bundle installed together. No executable was replaced.')

    def stage(self, slot, executable, version, commit, sha256):
        folder = self._state(slot)
        with _slot_lock(folder):
            return self._stage(slot, executable, version, commit, sha256, folder)

    def _stage(self, slot, executable, version, commit, sha256, folder):
        executable = Path(executable).resolve(strict=True)
        runtime = executable.parent / '_internal'
        expected_runtime = runtime_digest(runtime) if runtime.exists() else None
        self._check_runtime(slot, expected_runtime)
        if executable.suffix.lower() != '.exe' or executable == self.targets[slot]:
            raise ValueError('Select the separate, reviewed Windows executable.')
        if (not re.fullmatch(r'[a-f0-9]{64}', sha256) or not re.fullmatch(r'[a-f0-9]{40}', commit)
                or not re.fullmatch(r'[0-9][a-zA-Z0-9.\-]{0,31}', version)):
            raise ValueError('Supply the version and full source commit and executable SHA-256 from review.')
        folder.mkdir(parents=True, exist_ok=True)
        payload = folder / f'{sha256}.exe'
        with NamedTemporaryFile(dir=folder, delete=False) as stream:
            temp = Path(stream.name)
        try:
            shutil.copyfile(executable, temp)
            if _digest(temp) != sha256:
                raise ValueError('The executable SHA-256 differs from its reviewed value.')
            os.replace(temp, payload)
            receipt = dict(tool=slot, version=version, commit=commit, sha256=sha256)
            if expected_runtime is not None:
                receipt['runtime_sha256'] = expected_runtime
            _write_receipt(folder / 'pending.json', receipt)
        finally:
            temp.unlink(missing_ok=True)
        return payload

    def pending(self, slot):
        return self._receipt(slot, 'pending')

    def installed(self, slot):
        receipt = self._receipt(slot, 'installed')
        target = self.targets[slot]
        if receipt and target.is_file() and _digest(target) == receipt['sha256']:
            return receipt
        return None

    def apply(self, slot):
        folder = self._state(slot)
        if not (folder / 'pending.json').is_file():
            return None
        with _slot_lock(folder):
            return self._apply(slot, folder)

    def _apply(self, slot, folder):
        pending = self.pending(slot)
        if not pending:
            return None
        target = self.targets[slot]
        if target.resolve() != target:
            raise ValueError(f'{slot} launch path is redirected. The build is still staged.')
        payload = folder / f"{pending['sha256']}.exe"
        self._check_runtime(slot, pending.get('runtime_sha256'))
        target.parent.mkdir(parents=True, exist_ok=True)
        with NamedTemporaryFile(prefix='.hq-pending-', dir=target.parent, delete=False) as stream:
            temp = Path(stream.name)
        try:
            shutil.copyfile(payload, temp)
            if _digest(temp) != pending['sha256']:
                raise ValueError(f'The staged {slot} executable no longer matches its reviewed hash.')
            if not target.is_file() or _digest(target) != pending['sha256']:
                try:
                    os.replace(temp, target)
                except PermissionError as exc:
                    raise ValueError(f'Windows refused to replace {slot}. Close it if it is running and try again. The build is still staged.') from exc
            _write_receipt(folder / 'installed.json', pending)
            (folder / 'pending.json').unlink(missing_ok=True)
            return pending
        finally:
            temp.unlink(missing_ok=True)


def main():
    import main as hub

    parser = argparse.ArgumentParser(description='Stage a reviewed build for an existing SNAP HQ tool.')
    parser.add_argument('tool', choices=sorted(hub._tool_launch_paths()))
    parser.add_argument('executable')
    parser.add_argument('--version', required=True)
    parser.add_argument('--commit', required=True)
    parser.add_argument('--sha256', required=True)
    args = parser.parse_args()
    try:
        path = hub._pending_builds().stage(args.tool, args.executable, args.version, args.commit, args.sha256)
    except (OSError, ValueError) as exc:
        parser.exit(1, f'{exc}\n')
    print(f'{args.tool} {args.version} staged at {path}.')


if __name__ == '__main__':
    main()

# ===== SNAPSMACK EOF =====
