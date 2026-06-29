@echo off
REM ─────────────────────────────────────────────────────────────────────────
REM  SUMNABATCH — build script
REM  Requires: Python 3.11+, pip install -r requirements.txt
REM  Output:   C:\SUMNABATCH\sumnabatch.exe
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

REM ── Read BUILD_VERSION from main.py ───────────────────────────────────────
for /f "tokens=3 delims= " %%V in ('findstr /C:"BUILD_VERSION = " main.py') do set RAW_VER=%%V
set BUILD_VER=%RAW_VER:"=%
set EXE_NAME=sumnabatch.exe
echo Build version: %BUILD_VER%
echo Output name:   %EXE_NAME%

REM ── Single fixed spec (SUMNABATCH). sumnabatch.spec auto-bundles every local
REM    .py, so there is NO per-version spec to clone/rename. This is the fix:
REM    build.bat used to build smackyourbatchup-<ver>.spec, which bump_version.py
REM    cloned forward each build — propagating a truncated spec (the build error).
set SPEC_FILE=sumnabatch.spec
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
if not exist C:\SUMNABATCH mkdir C:\SUMNABATCH
pyinstaller --clean %SPEC_FILE% --distpath "C:\SUMNABATCH"

echo.
if exist "C:\SUMNABATCH\%EXE_NAME%" (
    echo Build successful: C:\SUMNABATCH\%EXE_NAME%
    echo Done. Launch: C:\SUMNABATCH\%EXE_NAME%
) else (
    echo Build FAILED — check output above for errors.
    pause
    exit /b 1
)
