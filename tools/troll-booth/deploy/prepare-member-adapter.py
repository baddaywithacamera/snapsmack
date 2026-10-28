from __future__ import annotations
# SNAPSMACK_EOF_HEADER: preserve the final EOF marker.
import argparse
import json
import shutil
import tempfile
from pathlib import Path


def transform(source, changes):
    for before, after in changes:
        if not before:
            source += after
        else:
            if source.count(before) == 0 and "TROLL BOOTH" in before:
                before = before.replace("TROLL BOOTH", "BRAIN TRUST")
                after = after.replace("TROLL BOOTH", "BRAIN TRUST")
            if source.count(before) != 1:
                raise RuntimeError('Installed adapter differs at a required change; no files installed')
            source = source.replace(before, after, 1)
    # Preserve the live PKCE metadata override and advertise the new grants there.
    source = source.replace('"scopes_supported": ["booth:read", "booth:write"]',
                            '"scopes_supported": ["booth:read", "booth:write", "conversation:read", "conversation:post"]')
    compile(source, '<member-adapter>', 'exec')
    return source


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('changes', type=Path)
    parser.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    import troll_booth
    target = Path(troll_booth.__file__).parent
    changes = json.loads(args.changes.read_text())
    prepared = {name: transform((target / name).read_text(), edits) for name, edits in changes.items()}
    backup = Path(tempfile.mkdtemp(prefix='brain-trust-member-backup-', dir='/opt/snapsmack/troll-booth'))
    stage = backup / 'staged'
    stage.mkdir()
    for name, text in prepared.items():
        shutil.copy2(target / name, backup / name)
        (stage / name).write_text(text)
    print('Backup and staged files:', backup)
    if args.apply:
        try:
            for name in prepared:
                shutil.copyfile(stage / name, target / name)
        except Exception:
            for name in prepared:
                shutil.copyfile(backup / name, target / name)
            raise
        print('Adapter files updated; service not restarted. Validate imports before restart.')
    else:
        print('Validation only; installed files unchanged.')


if __name__ == '__main__':
    main()
# ===== SNAPSMACK EOF =====
