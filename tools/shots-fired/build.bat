@echo off
REM ─────────────────────────────────────────────────────────────────────────
REM  SHOTS FIRED — build script
REM  Requires: Python 3.11+, pip install -r requirements.txt
REM  Output:   C:\snapsmack\shots-fired\shots-fired.exe
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

REM ── Read BUILD_VERSION from main.py ────────────────────────────────────────
for /f "tokens=3 delims= " %%V in ('findstr /C:"BUILD_VERSION = " main.py') do set RAW_VER=%%V
set BUILD_VER=%RAW_VER:"=%
set EXE_NAME=shots-fired.exe
echo Build version: %BUILD_VER%
echo Output name:   %EXE_NAME%

REM ── Single fixed spec. shots-fired.spec auto-bundles every local .py, so there
REM    is NO per-version spec to clone/rename.
set SPEC_FILE=shots-fired.spec
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
if not exist C:\snapsmack\shots-fired mkdir C:\snapsmack\shots-fired
pyinstaller --clean %SPEC_FILE% --distpath "C:\snapsmack\shots-fired"

echo.
if exist "C:\snapsmack\shots-fired\%EXE_NAME%" (
    echo Build successful: C:\snapsmack\shots-fired\%EXE_NAME%
    echo Done. Launch: C:\snapsmack\shots-fired\%EXE_NAME%
) else (
    echo Build FAILED — check output above for errors.
    pause
    exit /b 1
)
