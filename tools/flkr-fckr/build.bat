@echo off
REM FLKR FCKR — build.bat
REM Builds a single-file Windows .exe via PyInstaller from flkrfckr.spec.
REM Run from the tools/flkr-fckr/ directory.
REM
REM The exe is the Qt window (flkrfckr_launcher.py -> flkrfckr_qt.py) over the
REM tkinter-free engine (flkrfckr_core.py). main.py is the OLD tkinter window
REM and must never be the entry script again — the spec pins the launcher and
REM the post-build check below refuses an exe that imports tkinter.

echo === FLKR FCKR build ===

REM Build from the repo's pinned Python, never whatever "python" is on PATH
REM (spec drift lesson, 2026-09-15). Falls back to py/python only if missing.
set PYBIN=C:\dev\snapsmack\.python-build\python.exe
if not exist "%PYBIN%" set PYBIN=python

REM Install / upgrade dependencies.
REM cryptography + keyring back the credential vault (SECAUDIT 040 finding A).
REM Without them the exe still runs, but "Key security" reports encryption
REM unavailable and the API key stays base64 — so they are NOT optional here.
"%PYBIN%" -m pip install --upgrade pyinstaller PySide6 pillow requests cryptography keyring
if errorlevel 1 (
    echo *** pip install failed. Build aborted. ***
    pause
    exit /b 1
)

REM Auto-increment 0.7.xx in main.py AND flkrfckr_core.py (the Qt window reads
REM the core copy) and regenerate version_info.txt. ABORT loudly if the bump
REM fails so a stale, un-versioned exe is never shipped silently.
"%PYBIN%" bump_version.py
if errorlevel 1 (
    echo.
    echo *** VERSION BUMP FAILED. Build aborted. ***
    pause
    exit /b 1
)

REM COLLECT DIRECTORY, not a one-file exe (SS-1195). Qt is used under the
REM LGPLv3, which obliges us to let a recipient relink it, and a onefile exe
REM unpacks to a temp directory at run time and cannot be relinked. The spec
REM carries the icon (assets\icon.ico), the version resource
REM (version_info.txt), licenses\ and every local + _shared .py.
"%PYBIN%" -m PyInstaller --noconfirm --clean flkrfckr.spec
if errorlevel 1 (
    echo *** PyInstaller failed. Build aborted. ***
    pause
    exit /b 1
)

REM Post-build check: the exe must be the Qt shell, must carry the version and
REM the icon. Verifies the ENTRY SCRIPT, not just the number (SYBU 0.7.67 shipped
REM the Tk app because only the version was checked).
"%PYBIN%" ..\_build\verify_exe.py dist\flkrfckr\flkr-fckr.exe --entry flkrfckr_launcher --no-tk
if errorlevel 1 (
    echo *** Built exe failed verification. NOT copied. ***
    pause
    exit /b 1
)

if not exist C:\snapsmack\flkr-fckr mkdir C:\snapsmack\flkr-fckr
REM /E copies the WHOLE COLLECT directory — the Qt DLLs and licenses\ included.
REM Copying only the exe would leave something that cannot start. The exe is
REM named flkr-fckr in the spec, so it lands at the path SNAP HQ launches
REM (tools/hub/qt_main.py: C:\snapsmack\flkr-fckr\flkr-fckr.exe), unchanged.
xcopy /E /I /Y "dist\flkrfckr\*" "C:\snapsmack\flkr-fckr\"
if errorlevel 1 (
    echo *** Deploy failed. ***
    pause
    exit /b 1
)
"%PYBIN%" ..\hub\trust-installed-exe.py C:\snapsmack\flkr-fckr\flkr-fckr.exe
if errorlevel 1 exit /b 1

echo.
echo Done. Build is the DIRECTORY dist\flkrfckr (exe: dist\flkrfckr\flkr-fckr.exe)
pause
REM ===== SNAPSMACK EOF =====
