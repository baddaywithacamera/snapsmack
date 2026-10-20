# Build specs for TROLL BOOTH and SMACK DEALER

These are the PyInstaller specs, kept **here** rather than in `build/`
because `.gitignore` excludes `tools/*/build/` — so a spec left there does not
travel with a commit, and every checkout grows its own frozen copy.

That is not hypothetical. On 15 September 2026 a stale untracked `sybu.spec`
in one worktree still said `Analysis(['main.py'])`, so the rebuild shipped the
retired Tk window wearing the current version number. The spec is the thing
that decides what actually goes in the exe; it belongs in git.

## Rebuilding

TROLL BOOTH is a PySide6 app and the shipped exe bundles **Python 3.10 with
PySide6 6.12** — that is the app's own `.venv`, not the suite's
`.python-build` (Python 3.12, PySide6 6.9.3). Building with the suite
interpreter would quietly downgrade PySide6 by three minor versions.

    cd tools/troll-booth
    .venv/Scripts/python.exe -m pip install pyinstaller
    .venv/Scripts/pyinstaller.exe --noconfirm         --distpath dist --workpath build "_build/TROLL BOOTH.spec"

## Before saying "built"

Read the PyInstaller TOC and confirm the **last** script entry is
`desktop_entry`, and that the bundled module carries the change — the app code
sits compressed inside `PYZ.pyz`, so searching the exe for a string finds
nothing either way and proves nothing. Extract the module and look at its
code object instead.
