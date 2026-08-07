@echo off
REM FLKR FCKR — build.bat
REM Builds a single-file Windows .exe via PyInstaller.
REM Run from the tools/flkr-fckr/ directory.
REM Forked from tools/unzucker/build.bat.

echo === FLKR FCKR build ===

REM Install / upgrade dependencies
REM cryptography + keyring back the credential vault (SECAUDIT 040 finding A).
REM Without them the exe still runs, but "Key security" reports encryption
REM unavailable and the API key stays base64 — so they are NOT optional here.
pip install --upgrade pyinstaller pillow requests cryptography keyring

REM Auto-increment the 0.7.xx build version (main.py BUILD_VERSION) before building.
REM Try the py launcher first, then python; ABORT loudly if neither bumps so a
REM stale, un-versioned exe is never shipped silently (this was the bug: a bare
REM "python" call no-oped when only the py launcher was on PATH).
py bump_version.py || python bump_version.py
if errorlevel 1 (
    echo.
    echo *** VERSION BUMP FAILED - is Python on PATH? Build aborted. ***
    pause
    exit /b 1
)

REM Build single-file exe. --version-file stamps the Windows file-version
REM resource (Properties > Details) from the version_info.txt that
REM bump_version.py just regenerated. WITHOUT this flag the exe has no version
REM number even though BUILD_VERSION was bumped — that was the recurring bug.
REM --collect-all PIL + the _tkinter_finder hidden import: the one-file/windowed
REM freeze was dropping Pillow (or its Tk bridge), so the thumbnail worker's
REM "from PIL import Image / ImageTk" failed silently and thumbnails never rendered.
REM --paths ..\_shared + --hidden-import snap_thumbs: bundle the shared,
REM build-once client thumbnailer (tools/_shared/snap_thumbs.py) so the frozen
REM exe can import it. In dev a sys.path bootstrap in image_prep.py finds it;
REM in the bundle PyInstaller must be told where it lives, hence these flags.
REM --hidden-import snap_vault: same story for the credential vault.
REM --collect-submodules keyring.backends: keyring picks its backend at runtime by
REM import, so PyInstaller's static analysis misses every backend and the frozen
REM exe silently reports "no keychain" — which would quietly disable the
REM remember-on-this-machine option rather than failing loudly.
pyinstaller --onefile --windowed --name flkrfckr --icon assets\icon.ico --version-file version_info.txt --collect-all PIL --hidden-import PIL._tkinter_finder --paths ..\_shared --hidden-import snap_thumbs --hidden-import snap_stepup --hidden-import snap_vault --collect-submodules keyring.backends main.py

echo.
echo Done. Exe is in dist\flkrfckr.exe
pause
REM ===== SNAPSMACK EOF =====
