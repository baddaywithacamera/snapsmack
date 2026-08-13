@echo off
REM ─────────────────────────────────────────────────────────────────────────
REM  THE HUB — build script
REM  Requires: Python 3.10+, pip install -r requirements.txt
REM  Output:   C:\snapsmack\hub\hub.exe
REM  hub.spec auto-bundles every local .py AND tools/_shared/*.py.
REM ─────────────────────────────────────────────────────────────────────────

set SPEC_FILE=hub.spec
if not exist %SPEC_FILE% (
    echo ERROR: %SPEC_FILE% not found.
    pause
    exit /b 1
)

if exist build rmdir /s /q build
if exist dist  rmdir /s /q dist

echo Installing dependencies...
pip install -r requirements.txt

echo.
echo Building hub.exe...
if not exist C:\snapsmack\hub mkdir C:\snapsmack\hub
pyinstaller --clean %SPEC_FILE% --distpath "C:\snapsmack\hub"

echo.
if exist "C:\snapsmack\hub\hub.exe" (
    echo Build successful: C:\snapsmack\hub\hub.exe
) else (
    echo Build FAILED — check output above.
    pause
    exit /b 1
)
