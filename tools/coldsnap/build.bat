@echo off
REM ─────────────────────────────────────────────────────────────────────────
REM  COLD SNAP — build script
REM  Requires: Python 3.11+, pip install -r requirements.txt
REM  Output:   C:\COLDSNAP\coldsnap.exe
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

REM ── Read BUILD_VERSION from coldsnap.py ───────────────────────────────────
for /f "tokens=3 delims= " %%V in ('findstr /C:"BUILD_VERSION = " coldsnap.py') do set RAW_VER=%%V
set BUILD_VER=%RAW_VER:"=%
set EXE_NAME=coldsnap.exe
echo Build version: %BUILD_VER%
echo Output name:   %EXE_NAME%

REM ── Single fixed spec (COLD SNAP). coldsnap.spec auto-bundles every local
REM    .py, so there is NO per-version spec to clone/rename.
set SPEC_FILE=coldsnap.spec
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
if not exist C:\COLDSNAP mkdir C:\COLDSNAP
pyinstaller --clean %SPEC_FILE% --distpath "C:\COLDSNAP"

echo.
if exist "C:\COLDSNAP\%EXE_NAME%" (
    echo Build successful: C:\COLDSNAP\%EXE_NAME%
    echo Done. Launch: C:\COLDSNAP\%EXE_NAME%
) else (
    echo Build FAILED — check output above for errors.
    pause
    exit /b 1
)
