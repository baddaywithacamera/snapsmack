@echo off
REM ─────────────────────────────────────────────────────────────────────────
REM  CRONOMETER — build script
REM  Requires: Python 3.11+, pip install -r requirements.txt
REM  Output:   C:\snapsmack\cronometer\cronometer.exe
REM  UPX is disabled in the spec — builds finish in 2-5 min, not an hour.
REM ─────────────────────────────────────────────────────────────────────────

REM ── Auto-increment BUILD_VERSION (skip for debug rebuilds: build.bat norev) ─
if /I "%~1"=="norev" (
    echo Skipping version bump ^(norev^) — rebuilding current version.
) else (
    echo Bumping build version...
    python bump_version.py
    if errorlevel 1 (
        echo ERROR: version bump failed. Aborting build.
        pause
        exit /b 1
    )
)

REM ── Read BUILD_VERSION from cronometer.py ─────────────────────────────────
for /f "tokens=3 delims= " %%V in ('findstr /C:"BUILD_VERSION = " cronometer.py') do set RAW_VER=%%V
set BUILD_VER=%RAW_VER:"=%
set EXE_NAME=cronometer.exe
echo Build version: %BUILD_VER%
echo Output name:   %EXE_NAME%

REM ── Single fixed spec (CRONOMETER). cronometer.spec auto-bundles every local
REM    .py, so there is NO per-version spec to clone/rename.
set SPEC_FILE=cronometer.spec
if not exist %SPEC_FILE% (
    echo ERROR: Spec file %SPEC_FILE% not found.
    pause
    exit /b 1
)

REM ── Clean stale build artifacts (prevents OneDrive / AV lock errors) ──────
if exist build (
    echo Cleaning previous build folder...
    rmdir /s /q build
)
if exist dist (
    echo Cleaning previous dist folder...
    rmdir /s /q dist
)

echo Installing dependencies...
pip install -r requirements.txt

echo.
echo Building %EXE_NAME%...
if not exist C:\snapsmack\cronometer mkdir C:\snapsmack\cronometer
pyinstaller --clean %SPEC_FILE% --distpath "C:\snapsmack\cronometer"

echo.
if exist "C:\snapsmack\cronometer\%EXE_NAME%" (
    echo Build successful: C:\snapsmack\cronometer\%EXE_NAME%
    echo Done. Launch: C:\snapsmack\cronometer\%EXE_NAME%
) else (
    echo Build FAILED — check output above for errors.
    pause
    exit /b 1
)
REM ===== SNAPSMACK EOF =====
